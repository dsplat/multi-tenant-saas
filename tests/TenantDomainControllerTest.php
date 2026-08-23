<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

class TenantDomainControllerTest extends TestCase
{
    private const TENANT_ID = 6601;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Verify Tenant',
            'slug' => 'verify-tenant',
            'slug_status' => 'active',
            'status' => 'active',
            'domain' => 'verify.example.com',
        ]);
    }

    public function test_service_exists(): void
    {
        $this->assertInstanceOf(DomainService::class, app(DomainService::class));
    }

    public function test_save_third_party_verify_files_normalizes_names(): void
    {
        $service = new DomainService;

        // 容错：带/不带 .txt 后缀均可，去重后统一为完整文件名
        $saved = $service->saveThirdPartyVerifyFiles(self::TENANT_ID, [
            'WW_verify_mLUxXhK2fEC6jPsB',
            'MP_verify_AbCdEfGh123.txt',
            'alipay_verify_12345678',
        ]);

        $this->assertSame([
            'WW_verify_mLUxXhK2fEC6jPsB.txt',
            'MP_verify_AbCdEfGh123.txt',
            'alipay_verify_12345678.txt',
        ], $saved);

        // 持久化可回读
        $this->assertSame($saved, $service->getThirdPartyVerifyFiles(self::TENANT_ID));
    }

    public function test_save_third_party_verify_files_deduplicates(): void
    {
        $service = new DomainService;

        $saved = $service->saveThirdPartyVerifyFiles(self::TENANT_ID, [
            'WW_verify_AbCdEfGh123',
            'WW_verify_AbCdEfGh123.txt',
        ]);

        $this->assertSame(['WW_verify_AbCdEfGh123.txt'], $saved);
    }

    public function test_save_third_party_verify_files_rejects_invalid_name(): void
    {
        $service = new DomainService;

        // 路径穿越/任意前缀均拒绝
        $this->expectException(ValidationException::class);
        $service->saveThirdPartyVerifyFiles(self::TENANT_ID, ['../../etc/passwd']);
    }

    public function test_verify_info_returns_third_party_files(): void
    {
        $service = new DomainService;
        $service->saveThirdPartyVerifyFiles(self::TENANT_ID, ['WW_verify_mLUxXhK2fEC6jPsB']);

        $info = $service->getVerificationInstructions(self::TENANT_ID);

        $this->assertSame('verify.example.com', $info['domain']);
        $this->assertSame(['WW_verify_mLUxXhK2fEC6jPsB.txt'], $info['third_party_verify_files']);
        // 平台归属验证文件信息（与第三方验证并列返回，供前端分区展示）
        $this->assertStringContainsString('.well-known/tenant-verify/', $info['file_path']);
        $this->assertSame($info['token'], $info['file_content']);
    }

    public function test_verify_info_auto_generates_token_when_missing(): void
    {
        $service = new DomainService;

        $info = $service->getVerificationInstructions(self::TENANT_ID);

        $this->assertNotEmpty($info['token']);
        $this->assertSame(32, strlen($info['token']));
        $this->assertSame($info['token'], TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_token'));
    }
}
