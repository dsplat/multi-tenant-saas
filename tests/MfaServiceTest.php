<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\MfaDevice;
use MultiTenantSaas\Models\MfaRecoveryCode;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\MfaService;
use MultiTenantSaas\Tests\Schema\SecurityModule;

/**
 * TASK-015 MfaService 单元测试
 *
 * 覆盖：TOTP 生成/校验、邮箱/短信验证码、恢复码、设备管理、综合挑战校验
 */
class MfaServiceTest extends TestCase
{
    protected array $uses = [SecurityModule::class];

    private MfaService $service;

    private int $userId = 2001;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        User::unguarded(function () {
            User::create([
                'user_id' => $this->userId,
                'name' => 'Alice',
                'email' => 'alice@example.com',
                'phone' => '13800138000',
                'password' => bcrypt('secret'),
            ]);
        });

        TenantContext::setTenantId('1001');

        $this->service = app(MfaService::class);
    }

    // ---------- TOTP ----------

    public function test_totp_secret_is_generated_as_base32(): void
    {
        $secret = $this->service->generateTotpSecret();

        $this->assertNotEmpty($secret);
        // Base32 字符集校验
        $this->assertSame(0, preg_match('/[^ABCDEFGHIJKLMNOPQRSTUVWXYZ234567=]/', $secret));
    }

    public function test_otpauth_uri_contains_secret_and_issuer(): void
    {
        $secret = $this->service->generateTotpSecret();
        $uri = $this->service->getOtpauthUri($secret, 'alice@example.com');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret='.$secret, $uri);
        $this->assertStringContainsString('period=30', $uri);
        $this->assertStringContainsString('digits=6', $uri);
    }

    public function test_totp_current_code_verifies(): void
    {
        $secret = $this->service->generateTotpSecret();
        $code = $this->service->generateTotp($secret);

        $this->assertTrue($this->service->verifyTotp($secret, $code));
    }

    public function test_totp_wrong_code_fails(): void
    {
        $secret = $this->service->generateTotpSecret();

        $this->assertFalse($this->service->verifyTotp($secret, '000000'));
    }

    public function test_totp_accepts_previous_window(): void
    {
        $secret = $this->service->generateTotpSecret();
        // 30 秒前的验证码
        $code = $this->service->generateTotp($secret, time() - 30);

        $this->assertTrue($this->service->verifyTotp($secret, $code, 1));
    }

    public function test_totp_rejects_out_of_window(): void
    {
        $secret = $this->service->generateTotpSecret();
        // 5 分钟前的验证码，超出窗口
        $code = $this->service->generateTotp($secret, time() - 300);

        $this->assertFalse($this->service->verifyTotp($secret, $code, 1));
    }

    // ---------- 邮箱验证码 ----------

    public function test_email_code_generate_and_verify(): void
    {
        $code = $this->service->generateEmailCode($this->userId);

        $this->assertTrue($this->service->verifyEmailCode($this->userId, $code));
        // 验证码一次性使用
        $this->assertFalse($this->service->verifyEmailCode($this->userId, $code));
    }

    public function test_email_code_wrong_fails(): void
    {
        $this->service->generateEmailCode($this->userId);

        $this->assertFalse($this->service->verifyEmailCode($this->userId, '999999'));
    }

    public function test_send_email_code_generates_and_verifies(): void
    {
        // 依赖 TestCase 中 mail.default=log 驱动，Mail::raw 实际写日志而不抛异常
        $code = $this->service->sendEmailCode($this->userId);

        $this->assertSame(6, strlen($code));
        $this->assertTrue($this->service->verifyEmailCode($this->userId, $code));
    }

    // ---------- 短信验证码 ----------

    public function test_sms_code_generate_and_verify(): void
    {
        $code = $this->service->generateSmsCode($this->userId);

        $this->assertTrue($this->service->verifySmsCode($this->userId, $code));
        $this->assertFalse($this->service->verifySmsCode($this->userId, $code));
    }

    public function test_send_sms_code_returns_code(): void
    {
        // SmsService 默认 log 驱动，无需伪造
        $code = $this->service->sendSmsCode($this->userId);

        $this->assertSame(6, strlen($code));
    }

    // ---------- 恢复码 ----------

    public function test_recovery_codes_generated_with_correct_count(): void
    {
        $codes = $this->service->generateRecoveryCodes(8);

        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $code);
        }
    }

    public function test_recovery_code_verify_and_mark_used(): void
    {
        $codes = $this->service->regenerateRecoveryCodes($this->userId, 5);

        $this->assertTrue($this->service->verifyRecoveryCode($this->userId, $codes[0]));
        // 已使用不可重复
        $this->assertFalse($this->service->verifyRecoveryCode($this->userId, $codes[0]));
        // 其余仍可用
        $this->assertTrue($this->service->verifyRecoveryCode($this->userId, $codes[1]));
    }

    public function test_recovery_code_invalid_fails(): void
    {
        $this->service->regenerateRecoveryCodes($this->userId, 3);

        $this->assertFalse($this->service->verifyRecoveryCode($this->userId, 'AAAA-BBBB-CCCC'));
    }

    public function test_recovery_code_status(): void
    {
        $codes = $this->service->regenerateRecoveryCodes($this->userId, 5);
        $this->service->verifyRecoveryCode($this->userId, $codes[0]);

        $status = $this->service->getRecoveryCodeStatus($this->userId);

        $this->assertSame(5, $status['total']);
        $this->assertSame(1, $status['used']);
        $this->assertSame(4, $status['remaining']);
    }

    public function test_recovery_codes_regenerate_replaces_old(): void
    {
        $first = $this->service->regenerateRecoveryCodes($this->userId, 3);
        $second = $this->service->regenerateRecoveryCodes($this->userId, 3);

        // 旧恢复码失效
        $this->assertFalse($this->service->verifyRecoveryCode($this->userId, $first[0]));
        // 新恢复码可用
        $this->assertTrue($this->service->verifyRecoveryCode($this->userId, $second[0]));

        $this->assertSame(3, MfaRecoveryCode::where('user_id', $this->userId)->count());
    }

    // ---------- 设备管理 ----------

    public function test_setup_totp_device_and_list(): void
    {
        $secret = $this->service->generateTotpSecret();
        $device = $this->service->setupTotpDevice($this->userId, $secret, 'Authenticator');

        $this->assertInstanceOf(MfaDevice::class, $device);
        $this->assertTrue($device->is_primary);
        $this->assertTrue($device->is_verified);
        $this->assertTrue($this->service->hasMfaEnabled($this->userId));

        $devices = $this->service->listDevices($this->userId);
        $this->assertCount(1, $devices);
    }

    public function test_setup_email_and_sms_devices(): void
    {
        $email = $this->service->setupEmailDevice($this->userId, 'My Email');
        $sms = $this->service->setupSmsDevice($this->userId, '13800138000', 'My Phone');

        $this->assertSame('email', $email->type);
        $this->assertSame('sms', $sms->type);
        // TOTP 占据唯一约束，但 email/sms 类型不同，可共存
        $this->assertCount(2, $this->service->listDevices($this->userId));
    }

    public function test_delete_device_promotes_next_primary(): void
    {
        $this->service->setupTotpDevice($this->userId, $this->service->generateTotpSecret(), 'TOTP');
        $email = $this->service->setupEmailDevice($this->userId, 'Email');

        // 删除主设备（TOTP 为首个 = 主设备）
        $totp = MfaDevice::where('user_id', $this->userId)->where('type', 'totp')->first();
        $this->service->deleteDevice($this->userId, $totp->mfa_device_id);

        $email->refresh();
        $this->assertTrue($email->is_primary);
    }

    public function test_rename_device(): void
    {
        $device = $this->service->setupTotpDevice($this->userId, $this->service->generateTotpSecret(), 'Old');

        $renamed = $this->service->renameDevice($this->userId, $device->mfa_device_id, 'New Name');

        $this->assertSame('New Name', $renamed->label);
    }

    public function test_set_primary_device(): void
    {
        $totp = $this->service->setupTotpDevice($this->userId, $this->service->generateTotpSecret(), 'TOTP');
        $email = $this->service->setupEmailDevice($this->userId, 'Email');

        $this->service->setPrimaryDevice($this->userId, $email->mfa_device_id);

        $totp->refresh();
        $email->refresh();

        $this->assertFalse($totp->is_primary);
        $this->assertTrue($email->is_primary);
    }

    public function test_delete_nonexistent_device_returns_false(): void
    {
        $this->assertFalse($this->service->deleteDevice($this->userId, 999999));
    }

    // ---------- 综合挑战校验 ----------

    public function test_verify_challenge_totp(): void
    {
        $secret = $this->service->generateTotpSecret();
        $this->service->setupTotpDevice($this->userId, $secret, 'TOTP');
        $code = $this->service->generateTotp($secret);

        $this->assertTrue($this->service->verifyChallenge($this->userId, $code, 'totp'));
    }

    public function test_verify_challenge_email(): void
    {
        $this->service->setupEmailDevice($this->userId, 'Email');
        $code = $this->service->generateEmailCode($this->userId);

        $this->assertTrue($this->service->verifyChallenge($this->userId, $code, 'email'));
    }

    public function test_verify_challenge_recovery(): void
    {
        $codes = $this->service->regenerateRecoveryCodes($this->userId, 3);

        $this->assertTrue($this->service->verifyChallenge($this->userId, $codes[0], 'recovery'));
    }

    public function test_get_available_challenge_types(): void
    {
        $this->service->setupTotpDevice($this->userId, $this->service->generateTotpSecret(), 'TOTP');
        $this->service->regenerateRecoveryCodes($this->userId, 3);

        $types = $this->service->getAvailableChallengeTypes($this->userId);

        $this->assertContains('totp', $types);
        $this->assertContains('recovery', $types);
    }

    public function test_has_mfa_enabled_false_when_no_device(): void
    {
        $this->assertFalse($this->service->hasMfaEnabled($this->userId));
    }

    public function test_verify_challenge_unknown_type_fails(): void
    {
        $this->assertFalse($this->service->verifyChallenge($this->userId, '123456', 'unknown'));
    }
}
