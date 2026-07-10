<?php

namespace MultiTenantSaas\Tests;

use Carbon\Carbon;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TrustedDevice;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\TrustedDeviceService;
use MultiTenantSaas\Tests\Schema\SecurityModule;

/**
 * TASK-017 TrustedDeviceService 单元测试
 *
 * 覆盖：trustDevice、isDeviceTrusted、listDevices、listActiveDevices、revokeDevice、renameDevice、extendTrust、purgeExpired
 */
class TrustedDeviceServiceTest extends TestCase
{
    protected array $uses = [SecurityModule::class];

    private TrustedDeviceService $service;

    private int $userId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId('1001');

        User::create([
            'user_id' => $this->userId,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->service = app(TrustedDeviceService::class);
    }

    private function makeRequest(string $ip = '192.168.1.1', string $userAgent = 'Mozilla/5.0'): Request
    {
        $request = Request::create('/test', 'GET');
        $request->server->set('REMOTE_ADDR', $ip);
        $request->headers->set('User-Agent', $userAgent);

        return $request;
    }

    // ---------- 指纹生成 ----------

    public function test_generate_fingerprint(): void
    {
        $fp1 = $this->service->generateFingerprint('1.2.3.4', 'Agent/1.0');
        $fp2 = $this->service->generateFingerprint('1.2.3.4', 'Agent/1.0');
        $fp3 = $this->service->generateFingerprint('1.2.3.4', 'Agent/2.0');

        $this->assertSame($fp1, $fp2);
        $this->assertNotSame($fp1, $fp3);
        $this->assertSame(64, strlen($fp1)); // SHA256 hex
    }

    public function test_fingerprint_from_request(): void
    {
        $request = $this->makeRequest('10.0.0.1', 'TestAgent/1.0');
        $fp = $this->service->fingerprintFromRequest($request);

        $expected = hash('sha256', '10.0.0.1|TestAgent/1.0');
        $this->assertSame($expected, $fp);
    }

    // ---------- trustDevice ----------

    public function test_trust_device_creates_new(): void
    {
        $fingerprint = 'abc123';
        $device = $this->service->trustDevice($this->userId, $fingerprint, '1.2.3.4', 'Agent/1.0', 30, 'My PC');

        $this->assertInstanceOf(TrustedDevice::class, $device);
        $this->assertSame($fingerprint, $device->device_fingerprint);
        $this->assertSame('My PC', $device->device_name);
        $this->assertDatabaseHas('trusted_devices', [
            'user_id' => $this->userId,
            'device_fingerprint' => $fingerprint,
        ]);
    }

    public function test_trust_device_renews_existing(): void
    {
        $fingerprint = 'abc123';
        $device1 = $this->service->trustDevice($this->userId, $fingerprint, '1.2.3.4', 'Agent/1.0', 10);
        $device2 = $this->service->trustDevice($this->userId, $fingerprint, '5.6.7.8', 'Agent/2.0', 20, 'Renewed');

        $this->assertSame($device1->trusted_device_id, $device2->trusted_device_id);
        $this->assertSame('5.6.7.8', $device2->ip_address);
        $this->assertSame('Renewed', $device2->device_name);
    }

    public function test_trust_current_device(): void
    {
        $request = $this->makeRequest('192.168.1.1', 'TestBrowser/1.0');
        $device = $this->service->trustCurrentDevice($this->userId, $request, 30, 'Browser');

        $this->assertInstanceOf(TrustedDevice::class, $device);
        $this->assertSame('Browser', $device->device_name);
        $this->assertSame('192.168.1.1', $device->ip_address);
    }

    // ---------- isDeviceTrusted ----------

    public function test_is_device_trusted_returns_true_for_valid(): void
    {
        $fingerprint = 'abc123';
        $this->service->trustDevice($this->userId, $fingerprint, '1.2.3.4', 'Agent/1.0', 30);

        $this->assertTrue($this->service->isDeviceTrusted($this->userId, $fingerprint));
    }

    public function test_is_device_trusted_returns_false_for_unknown(): void
    {
        $this->assertFalse($this->service->isDeviceTrusted($this->userId, 'unknown_fp'));
    }

    public function test_is_device_trusted_returns_false_for_expired(): void
    {
        $fingerprint = 'abc123';
        $this->service->trustDevice($this->userId, $fingerprint, '1.2.3.4', 'Agent/1.0', 1);

        TrustedDevice::where('user_id', $this->userId)
            ->where('device_fingerprint', $fingerprint)
            ->update(['expires_at' => Carbon::yesterday()]);

        $this->assertFalse($this->service->isDeviceTrusted($this->userId, $fingerprint));
    }

    public function test_is_current_device_trusted(): void
    {
        $request = $this->makeRequest('10.0.0.1', 'MyAgent');
        $this->service->trustCurrentDevice($this->userId, $request, 30);

        $this->assertTrue($this->service->isCurrentDeviceTrusted($this->userId, $request));
    }

    // ---------- listDevices / listActiveDevices ----------

    public function test_list_devices_returns_all(): void
    {
        $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 30);
        $this->service->trustDevice($this->userId, 'fp2', '2.2.2.2', 'A2', 30);

        $devices = $this->service->listDevices($this->userId);
        $this->assertCount(2, $devices);
    }

    public function test_list_active_devices_excludes_expired(): void
    {
        $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 30);
        $this->service->trustDevice($this->userId, 'fp2', '2.2.2.2', 'A2', 1);

        TrustedDevice::where('user_id', $this->userId)
            ->where('device_fingerprint', 'fp2')
            ->update(['expires_at' => Carbon::yesterday()]);

        $active = $this->service->listActiveDevices($this->userId);
        $this->assertCount(1, $active);
        $this->assertSame('fp1', $active->first()->device_fingerprint);
    }

    // ---------- revokeDevice / revokeAllDevices ----------

    public function test_revoke_device(): void
    {
        $device = $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 30);

        $this->assertTrue($this->service->revokeDevice($this->userId, $device->trusted_device_id));
        $this->assertDatabaseMissing('trusted_devices', [
            'trusted_device_id' => $device->trusted_device_id,
        ]);
    }

    public function test_revoke_device_returns_false_for_nonexistent(): void
    {
        $this->assertFalse($this->service->revokeDevice($this->userId, 999999));
    }

    public function test_revoke_all_devices(): void
    {
        $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 30);
        $this->service->trustDevice($this->userId, 'fp2', '2.2.2.2', 'A2', 30);

        $count = $this->service->revokeAllDevices($this->userId);
        $this->assertSame(2, $count);
        $this->assertCount(0, $this->service->listDevices($this->userId));
    }

    // ---------- renameDevice ----------

    public function test_rename_device(): void
    {
        $device = $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 30, 'Old Name');

        $renamed = $this->service->renameDevice($this->userId, $device->trusted_device_id, 'New Name');

        $this->assertNotNull($renamed);
        $this->assertSame('New Name', $renamed->device_name);
    }

    public function test_rename_device_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->service->renameDevice($this->userId, 999999, 'Nope'));
    }

    // ---------- extendTrust ----------

    public function test_extend_trust(): void
    {
        $device = $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 1);

        $original = $device->fresh()->expires_at;
        $extended = $this->service->extendTrust($this->userId, $device->trusted_device_id, 60);

        $this->assertNotNull($extended);
        $this->assertTrue($extended->expires_at->greaterThan($original));
    }

    public function test_extend_trust_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->service->extendTrust($this->userId, 999999, 30));
    }

    // ---------- purgeExpired ----------

    public function test_purge_expired(): void
    {
        $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 30);
        $this->service->trustDevice($this->userId, 'fp2', '2.2.2.2', 'A2', 1);

        TrustedDevice::where('user_id', $this->userId)
            ->where('device_fingerprint', 'fp2')
            ->update(['expires_at' => Carbon::yesterday()]);

        $purged = $this->service->purgeExpired();

        $this->assertSame(1, $purged);
        $this->assertCount(1, $this->service->listDevices($this->userId));
    }

    public function test_purge_expired_returns_zero_when_none(): void
    {
        $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 30);

        $this->assertSame(0, $this->service->purgeExpired());
    }

    // ---------- findDevice ----------

    public function test_find_device(): void
    {
        $device = $this->service->trustDevice($this->userId, 'fp1', '1.1.1.1', 'A1', 30);

        $found = $this->service->findDevice($this->userId, $device->trusted_device_id);
        $this->assertNotNull($found);
        $this->assertSame($device->trusted_device_id, $found->trusted_device_id);
    }

    public function test_find_device_returns_null_for_nonexistent(): void
    {
        $this->assertNull($this->service->findDevice($this->userId, 999999));
    }
}
