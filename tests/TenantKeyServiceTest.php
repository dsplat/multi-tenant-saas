<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantKey;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantKeyService;
use MultiTenantSaas\Tests\Schema\MiscModule;

class TenantKeyServiceTest extends TestCase
{
    protected array $uses = [MiscModule::class];

    use DatabaseTransactions;

    private TenantKeyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 测试用系统主密钥
        config(['tenancy.encryption.master_key' => 'test-master-key-32-bytes-xxxx']);

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->service = new TenantKeyService;
    }

    public function test_can_generate_key(): void
    {
        $key = $this->service->generateKey(1001);

        $this->assertInstanceOf(TenantKey::class, $key);
        $this->assertSame('system', $key->key_type);
        $this->assertSame('active', $key->status);
        $this->assertNotEmpty($key->encrypted_key);
    }

    public function test_generate_key_throws_if_active_exists(): void
    {
        $this->service->generateKey(1001);

        $this->expectException(\RuntimeException::class);
        $this->service->generateKey(1001);
    }

    public function test_key_is_stored_encrypted_and_decryptable(): void
    {
        $key = $this->service->generateKey(1001);

        // 加密存储的密钥不应等于 32 字节明文
        $plain = $this->service->decryptKey($key);
        $this->assertSame(32, strlen($plain));
        $this->assertNotSame($plain, $key->encrypted_key);
    }

    public function test_keys_are_isolated_by_tenant(): void
    {
        $keyA = $this->service->generateKey(1001);
        $keyB = $this->service->generateKey(1002);

        $this->assertNotSame(
            $this->service->decryptKey($keyA),
            $this->service->decryptKey($keyB)
        );
    }

    public function test_can_encrypt_and_decrypt_data(): void
    {
        $this->service->generateKey(1001);

        $payload = $this->service->encryptData(1001, 'sensitive-data');

        $this->assertNotSame('sensitive-data', $payload);
        $this->assertSame('sensitive-data', $this->service->decryptData($payload, 1001));
    }

    public function test_byok_import_with_raw_key(): void
    {
        $raw = random_bytes(32);

        $key = $this->service->importByok(1001, $raw);

        $this->assertSame('byok', $key->key_type);
        $this->assertSame('active', $key->status);
        $this->assertSame($raw, $this->service->decryptKey($key));
    }

    public function test_byok_import_with_hex_key(): void
    {
        $raw = random_bytes(32);
        $hex = bin2hex($raw);

        $key = $this->service->importByok(1001, $hex);

        $this->assertSame('byok', $key->key_type);
        $this->assertSame($raw, $this->service->decryptKey($key));
    }

    public function test_byok_import_with_base64_key(): void
    {
        $raw = random_bytes(32);
        $b64 = base64_encode($raw);

        $key = $this->service->importByok(1001, $b64);

        $this->assertSame('byok', $key->key_type);
        $this->assertSame($raw, $this->service->decryptKey($key));
    }

    public function test_byok_invalid_format_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->importByok(1001, 'too-short');
    }

    public function test_rotate_key_creates_new_active_and_retires_old(): void
    {
        $oldKey = $this->service->generateKey(1001);

        $newKey = $this->service->rotateKey(1001);

        $this->assertSame('active', $newKey->status);
        $this->assertSame($oldKey->tenant_key_id, $newKey->previous_key_id);

        $oldKey->refresh();
        $this->assertSame('retired', $oldKey->status);
        $this->assertNotNull($oldKey->rotated_at);
    }

    public function test_decrypt_data_works_after_rotation_via_retired_key(): void
    {
        $this->service->generateKey(1001);

        $payload = $this->service->encryptData(1001, 'secret');

        // 轮换后新密钥无法解密旧数据，但应回退到 retired 密钥解密
        $this->service->rotateKey(1001);

        $this->assertSame('secret', $this->service->decryptData($payload, 1001));
    }

    public function test_reencrypt_data_rotates_existing_field_values(): void
    {
        $this->service->generateKey(1001);

        // 构造临时表存放已加密数据
        Schema::create('tenant_secrets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->text('secret_payload');
        });

        $payload = $this->service->encryptData(1001, 'plain-value');
        DB::table('tenant_secrets')->insert([
            'tenant_id' => 1001,
            'secret_payload' => $payload,
        ]);

        // 轮换并对该字段执行 re-encrypt
        $this->service->rotateKey(1001, [
            [
                'table' => 'tenant_secrets',
                'column' => 'secret_payload',
                'id_column' => 'id',
                'tenant_column' => 'tenant_id',
            ],
        ]);

        $row = DB::table('tenant_secrets')->where('tenant_id', 1001)->first();

        // 值已被重新加密
        $this->assertNotSame($payload, $row->secret_payload);
        // 新密钥可解密
        $this->assertSame('plain-value', $this->service->decryptData($row->secret_payload, 1001));

        Schema::drop('tenant_secrets');
    }

    public function test_rotate_key_throws_when_no_active_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->rotateKey(1001);
    }

    public function test_master_key_missing_throws(): void
    {
        config(['tenancy.encryption.master_key' => null]);

        $this->expectException(\RuntimeException::class);
        $this->service->generateKey(1001);
    }
}
