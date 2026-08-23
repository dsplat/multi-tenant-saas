<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MultiTenantSaas\Modules\Domain\Http\Controllers\VerificationFileController;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 域名验证文件动态服务测试
 *
 * 覆盖 VerificationFileController 两类验证文件的裸路由服务逻辑：
 *   1. 平台归属验证：/.well-known/tenant-verify/{token}.txt → 内容为 token
 *   2. 第三方平台验证：/{WW_verify|MP_verify|alipay_verify|verify_}_{rand}.txt
 *     → 内容为去掉 .txt 的文件名（微信/企微/支付宝统一规则）
 *
 * 关键约束（回归重点）：
 *  - 按真实 Host 手动解析租户（自定义域名精确匹配 → {slug}.{base} 通配兜底），
 *    验证文件必须在域名 pending 时即可访问（nginx 层已特判转发 PHP）
 *  - 未命中一律 404（不区分「无此租户/无此文件」，防探测）
 */
class VerificationFileControllerTest extends TestCase
{
    private const TENANT_ID = 7701;

    protected function setUp(): void
    {
        parent::setUp();

        config(['domain.wildcard_base' => 'neihang.com']);
        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Club',
            'slug' => 'club',
            'slug_status' => 'active',
            'status' => 'active',
            'domain' => 'club.lanyantu.com',
        ]);
    }

    /**
     * 构造绑定真实 Host 的请求
     */
    private function requestTo(string $host, string $path): Request
    {
        $request = Request::create("http://{$host}{$path}");
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_token_file_served_on_custom_domain(): void
    {
        $token = 'gX5272EDLvHAwG8AAiAsBPWSWuHV3aYQ';
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_token', $token);

        $response = (new VerificationFileController)->token(
            $this->requestTo('club.lanyantu.com', "/.well-known/tenant-verify/{$token}.txt"),
            $token
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($token, $response->getContent());
    }

    public function test_token_file_mismatch_returns_404(): void
    {
        $token = 'gX5272EDLvHAwG8AAiAsBPWSWuHV3aYQ';
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_token', $token);

        try {
            (new VerificationFileController)->token(
                $this->requestTo('club.lanyantu.com', '/.well-known/tenant-verify/wrong.txt'),
                'wrong'
            );
            $this->fail('Expected 404 for mismatched token');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_third_party_file_served_with_filename_content(): void
    {
        $files = ['WW_verify_mLUxXhK2fEC6jPsB.txt', 'MP_verify_AbCdEfGh123.txt'];
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, DomainService::SETTING_THIRD_PARTY_VERIFY_FILES, $files);

        // 微信要求的根路径地址：http://club.lanyantu.com/WW_verify_mLUxXhK2fEC6jPsB.txt
        $response = (new VerificationFileController)->file(
            $this->requestTo('club.lanyantu.com', '/WW_verify_mLUxXhK2fEC6jPsB.txt'),
            'WW_verify_mLUxXhK2fEC6jPsB'
        );

        $this->assertSame(200, $response->getStatusCode());
        // 三大平台统一规则：文件内容 = 去掉 .txt 的文件名
        $this->assertSame('WW_verify_mLUxXhK2fEC6jPsB', $response->getContent());
    }

    public function test_third_party_file_not_registered_returns_404(): void
    {
        try {
            (new VerificationFileController)->file(
                $this->requestTo('club.lanyantu.com', '/WW_verify_NotSaved000.txt'),
                'WW_verify_NotSaved000'
            );
            $this->fail('Expected 404 for unregistered file');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_unknown_host_returns_404(): void
    {
        try {
            (new VerificationFileController)->token(
                $this->requestTo('evil.example.com', '/.well-known/tenant-verify/whatever1234567890.txt'),
                'whatever1234567890'
            );
            $this->fail('Expected 404 for unknown host');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_slug_wildcard_subdomain_fallback_serves_files(): void
    {
        // 未绑定自定义域名时，{slug}.{base} 形态也要能服务验证文件（域名未生效前即可访问）
        $token = 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6';
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_token', $token);

        $response = (new VerificationFileController)->token(
            $this->requestTo('club.neihang.com', "/.well-known/tenant-verify/{$token}.txt"),
            $token
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($token, $response->getContent());
    }

    public function test_inactive_slug_wildcard_returns_404(): void
    {
        Tenant::where('tenant_id', self::TENANT_ID)->update(['slug_status' => 'rejected']);

        try {
            (new VerificationFileController)->token(
                $this->requestTo('club.neihang.com', '/.well-known/tenant-verify/abc1234567890XYZabc1234567890XYZ.txt'),
                'abc1234567890XYZabc1234567890XYZ'
            );
            $this->fail('Expected 404 for rejected slug');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertTrue(true);
        }
    }
}
