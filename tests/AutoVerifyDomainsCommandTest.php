<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * domains:auto-verify 轮询命令测试
 *
 * 场景：租户绑定自定义域名后未完成 DNS 解析（手动验证必然失败），
 * 后台调度周期性主动 GET 验证文件，可达即自动 approve。
 */
class AutoVerifyDomainsCommandTest extends TestCase
{
    private const TENANT_ID = 6701;

    private const DOMAIN = 'poll-tenant.example.com';

    private const TOKEN = 'fixedtoken1234567890abcdef12345678';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Poll Tenant',
            'slug' => 'poll-tenant',
            'slug_status' => 'active',
            'status' => 'active',
            'domain' => self::DOMAIN,
        ]);

        // 预置固定 token（域名保存时也会自动生成）
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_token', self::TOKEN);
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_token_generated_at', now()->toDateTimeString());
    }

    public function test_auto_verify_approves_pending_tenant_when_file_reachable(): void
    {
        Http::fake([
            'https://' . self::DOMAIN . '/*' => Http::response(self::TOKEN),
        ]);

        $this->artisan('domains:auto-verify', ['--no-nginx' => true])->assertSuccessful();

        $this->assertSame(DomainService::STATUS_APPROVED, TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status'));
        $this->assertSame('auto_polling', TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_method'));
        $this->assertNotNull(TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_verified_at'));
    }

    public function test_auto_verify_keeps_pending_when_file_unreachable(): void
    {
        // DNS 未解析/404：状态保持 pending，且不消耗手动验证次数
        Http::fake([
            'https://' . self::DOMAIN . '/*' => Http::response('not-found', 404),
            'http://' . self::DOMAIN . '/*' => Http::response('not-found', 404),
        ]);

        $this->artisan('domains:auto-verify', ['--no-nginx' => true])->assertSuccessful();

        $this->assertSame(DomainService::STATUS_PENDING, TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_PENDING));
        // 手动计数器不被轮询消耗
        $this->assertSame(0, (int) TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_attempts', 0));
        // 轮询独立计数器累计
        $this->assertSame(1, (int) TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'auto_verify_attempts', 0));
    }

    public function test_auto_verify_rejects_wrong_content(): void
    {
        // 文件可达但内容不匹配：不批准（防伪造）
        Http::fake([
            'https://' . self::DOMAIN . '/*' => Http::response('forged-token-content'),
        ]);

        $this->artisan('domains:auto-verify', ['--no-nginx' => true])->assertSuccessful();

        $this->assertSame(DomainService::STATUS_PENDING, TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_PENDING));
    }

    public function test_auto_verify_skips_rejected_tenant(): void
    {
        // 管理员已驳回：轮询不得自动翻转
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_REJECTED);

        Http::fake([
            'https://' . self::DOMAIN . '/*' => Http::response(self::TOKEN),
        ]);

        $this->artisan('domains:auto-verify', ['--no-nginx' => true])->assertSuccessful();

        $this->assertSame(DomainService::STATUS_REJECTED, TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status'));
    }

    public function test_auto_verify_skips_expired_domain(): void
    {
        // 配置超过轮询窗口仍未解析：停止检测（不消耗计数器）
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_token_generated_at', now()->subDays(91)->toDateTimeString());

        Http::fake([
            'https://' . self::DOMAIN . '/*' => Http::response(self::TOKEN),
        ]);

        $this->artisan('domains:auto-verify', ['--no-nginx' => true])->assertSuccessful();

        $this->assertSame(DomainService::STATUS_PENDING, TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_PENDING));
        $this->assertSame(0, (int) TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'auto_verify_attempts', 0));
    }

    public function test_auto_verify_tenant_option_targets_single_tenant(): void
    {
        Http::fake([
            'https://' . self::DOMAIN . '/*' => Http::response(self::TOKEN),
        ]);

        $this->artisan('domains:auto-verify', ['--tenant' => 9999, '--no-nginx' => true])->assertSuccessful();

        $this->assertSame(DomainService::STATUS_PENDING, TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_PENDING));
    }

    public function test_polling_mode_bypasses_manual_attempt_limit(): void
    {
        // 手动验证已达上限时，轮询模式仍继续检测（独立计数器）
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'verification_attempts', 5);

        Http::fake([
            'https://' . self::DOMAIN . '/*' => Http::response(self::TOKEN),
        ]);

        $this->artisan('domains:auto-verify', ['--no-nginx' => true])->assertSuccessful();

        $this->assertSame(DomainService::STATUS_APPROVED, TenantSetting::get(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status'));
    }

    public function test_scheduler_registers_domain_auto_verify_task(): void
    {
        $scheduler = app(\MultiTenantSaas\Modules\Infrastructure\Services\SchedulerService::class);
        $scheduler->register(new \Illuminate\Console\Scheduling\Schedule);
        $tasks = $scheduler->getTasks();

        $this->assertArrayHasKey('domain-auto-verify', $tasks);
        $this->assertSame('domains:auto-verify', $tasks['domain-auto-verify']['command']);
    }

    public function test_whitelist_stale_detection_triggers_self_heal_gate(): void
    {
        // 白名单死锁自愈的前置检测：已配置域名不在源站白名单 → 判为过期（命令随后重生产物）；
        // 补齐后不再判过期（避免每 15 分钟无谓 reload）。（Testbench Console Kernel 为 final
        // 无法 mock Artisan 门面，此处反射验证检测分支）
        $deployPath = sys_get_temp_dir() . '/auto-verify-whitelist-' . self::TENANT_ID;
        @mkdir($deployPath . '/maps', 0777, true);
        file_put_contents($deployPath . '/maps/tenant-auth.map', "map \$host \$domain_allowed {\n    default 0;\n}\n");
        config(['domain.nginx_deploy_path' => $deployPath]);

        $command = app(\MultiTenantSaas\Modules\Domain\Commands\AutoVerifyDomains::class);
        $method = new \ReflectionMethod($command, 'whitelistStale');
        $method->setAccessible(true);
        $tenants = Tenant::where('tenant_id', self::TENANT_ID)->get();

        // 域名不在白名单 → 过期（死锁态，需重生）
        $this->assertTrue($method->invoke($command, $tenants));

        // 补齐域名后 → 不再判过期（精确行匹配，带 1; 放行标记）
        file_put_contents(
            $deployPath . '/maps/tenant-auth.map',
            "map \$host \$domain_allowed {\n    " . self::DOMAIN . "              1;\n}\n"
        );
        $this->assertFalse($method->invoke($command, $tenants));

        // map 文件不存在 → 视为过期（产物未生成同样死锁）
        @unlink($deployPath . '/maps/tenant-auth.map');
        $this->assertTrue($method->invoke($command, $tenants));
    }
}
