<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Mockery;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\SchedulerService;
use MultiTenantSaas\Modules\SSL\Services\TenantSslService;

/**
 * ssl:auto-issue 自动签发命令测试
 *
 * 场景：租户在域名设置页开启「自动签发证书」，域名审批通过后
 * 调度器经 acme.sh 自动签发部署证书（HTTP-01），到期前兜底重签。
 */
class AutoIssueSslCommandTest extends TestCase
{
    private const TENANT_ID = 6801;

    private const DOMAIN = 'ssl-tenant.example.com';

    private string $certsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certsPath = sys_get_temp_dir() . '/mts-ssl-test-' . uniqid();
        @mkdir($this->certsPath, 0755, true);

        config([
            'ssl.certs_path' => $this->certsPath,
            'ssl.nginx_map_file' => $this->certsPath . '/ssl-map.conf',
            // 委托生成方（NginxConfigService）读 domain 侧证书路径，须同源
            'domain.ssl_certs_path' => $this->certsPath,
        ]);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'SSL Tenant',
            'slug' => 'ssl-tenant',
            'slug_status' => 'active',
            'status' => 'active',
            'domain' => self::DOMAIN,
        ]);

        // 默认：域名已审批 + 已开启自动签发
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_APPROVED);
        TenantSetting::set(self::TENANT_ID, TenantSslService::GROUP_SSL, 'auto_issue', 1);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->certsPath . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->certsPath);

        parent::tearDown();
    }

    /** 命令级 mock：可用 + 可断言 issueCertificate 是否被调用 */
    private function bindService(bool $issueSuccess = true): TenantSslService
    {
        $service = Mockery::mock(TenantSslService::class, [])->makePartial();
        $service->shouldReceive('acmeAvailable')->andReturn(true);
        $service->shouldReceive('issueCertificate')->andReturnUsing(function ($tenant) use ($issueSuccess) {
            return $issueSuccess
                ? ['success' => true, 'message' => '证书签发并部署成功']
                : ['success' => false, 'message' => '签发失败'];
        });
        $this->app->instance(TenantSslService::class, $service);

        return $service;
    }

    public function test_issues_certificate_for_approved_auto_issue_tenant(): void
    {
        $service = $this->bindService();

        $this->artisan('ssl:auto-issue', ['--no-nginx' => true])->assertSuccessful();

        $service->shouldHaveReceived('issueCertificate')->once();
    }

    public function test_skips_tenant_when_domain_not_approved(): void
    {
        // pending/rejected 域名不在 nginx 白名单，LE 挑战到不了，不得签发
        TenantSetting::set(self::TENANT_ID, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_PENDING);
        $service = $this->bindService();

        $this->artisan('ssl:auto-issue', ['--no-nginx' => true])->assertSuccessful();

        $service->shouldNotHaveReceived('issueCertificate');
    }

    public function test_skips_tenant_without_auto_issue_enabled(): void
    {
        TenantSetting::set(self::TENANT_ID, TenantSslService::GROUP_SSL, 'auto_issue', 0);
        $service = $this->bindService();

        $this->artisan('ssl:auto-issue', ['--no-nginx' => true])->assertSuccessful();

        $service->shouldNotHaveReceived('issueCertificate');
    }

    public function test_skips_tenant_with_valid_certificate_far_from_expiry(): void
    {
        // 有效证书且距到期 > 30 天：续期交给 acme.sh cron，不重复签发
        $tenant = Tenant::query()->where('tenant_id', self::TENANT_ID)->first();
        $tenant->ssl_uploaded_at = now()->subDays(10);
        $tenant->ssl_cert_expires_at = now()->addDays(60);
        $tenant->save();
        file_put_contents("{$this->certsPath}/" . self::DOMAIN . '.crt', 'dummy');
        file_put_contents("{$this->certsPath}/" . self::DOMAIN . '.key', 'dummy');

        $service = $this->bindService();

        $this->artisan('ssl:auto-issue', ['--no-nginx' => true])->assertSuccessful();

        $service->shouldNotHaveReceived('issueCertificate');
    }

    public function test_reissues_when_domain_changed_and_cert_file_missing_for_current_domain(): void
    {
        // 域名变更后：DB 元数据（到期时间）属于旧域名，当前域名无落盘证书 → 不得沿用旧元数据跳过，必须重签。
        // 场景：租户把 club.example.com 改为新域名，旧证书文件仍在但新域名无证书。
        $tenant = Tenant::query()->where('tenant_id', self::TENANT_ID)->first();
        $tenant->ssl_uploaded_at = now()->subDays(10);
        $tenant->ssl_cert_expires_at = now()->addDays(60);
        $tenant->save();
        // 只存在旧域名的证书文件（当前域名 self::DOMAIN 无落盘证书）
        file_put_contents("{$this->certsPath}/old-domain.example.com.crt", 'dummy');
        file_put_contents("{$this->certsPath}/old-domain.example.com.key", 'dummy');

        $service = $this->bindService();

        $this->artisan('ssl:auto-issue', ['--no-nginx' => true])->assertSuccessful();

        $service->shouldHaveReceived('issueCertificate')->once();
    }

    public function test_reissues_when_certificate_expiring_soon(): void
    {
        // 30 天内到期：平台兜底重签（与 acme.sh cron 双保险）
        $tenant = Tenant::query()->where('tenant_id', self::TENANT_ID)->first();
        $tenant->ssl_uploaded_at = now()->subDays(80);
        $tenant->ssl_cert_expires_at = now()->addDays(10);
        $tenant->save();
        file_put_contents("{$this->certsPath}/" . self::DOMAIN . '.crt', 'dummy');
        file_put_contents("{$this->certsPath}/" . self::DOMAIN . '.key', 'dummy');

        $service = $this->bindService();

        $this->artisan('ssl:auto-issue', ['--no-nginx' => true])->assertSuccessful();

        $service->shouldHaveReceived('issueCertificate')->once();
    }

    public function test_tenant_option_targets_single_tenant(): void
    {
        $service = $this->bindService();

        $this->artisan('ssl:auto-issue', ['--tenant' => 9999, '--no-nginx' => true])->assertSuccessful();

        $service->shouldNotHaveReceived('issueCertificate');
    }

    public function test_service_issue_certificate_writes_files_and_records_method(): void
    {
        // 服务级：mock acme.sh 执行，验证签发后的落盘/元数据/记录清理
        $service = Mockery::mock(TenantSslService::class, [])->makePartial();
        $service->shouldReceive('acmeAvailable')->andReturn(true);
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('runAcme')->andReturnUsing(function (array $args) {
            if (in_array('--install-cert', $args, true)) {
                // 模拟 acme.sh --install-cert 落盘
                file_put_contents("{$this->certsPath}/" . self::DOMAIN . '.crt', 'dummy-cert');
                file_put_contents("{$this->certsPath}/" . self::DOMAIN . '.key', 'dummy-key');

                return ['ok' => true, 'output' => 'installed'];
            }

            return ['ok' => true, 'output' => 'issued'];
        });

        TenantSetting::set(self::TENANT_ID, TenantSslService::GROUP_SSL, 'last_issue_error', 'old-error');
        $tenant = Tenant::query()->where('tenant_id', self::TENANT_ID)->first();

        $result = $service->issueCertificate($tenant);

        $this->assertTrue($result['success']);
        $this->assertFileExists("{$this->certsPath}/" . self::DOMAIN . '.crt');
        $this->assertFileExists("{$this->certsPath}/" . self::DOMAIN . '.key');
        $this->assertSame(TenantSslService::METHOD_ACME, TenantSetting::get(self::TENANT_ID, TenantSslService::GROUP_SSL, 'method'));
        $this->assertNull(TenantSetting::get(self::TENANT_ID, TenantSslService::GROUP_SSL, 'last_issue_error'));
        // nginx ssl map 已生成且包含该域名
        $this->assertStringContainsString(self::DOMAIN, (string) file_get_contents($this->certsPath . '/ssl-map.conf'));
    }

    public function test_service_issue_failure_records_last_issue_error(): void
    {
        $service = Mockery::mock(TenantSslService::class, [])->makePartial();
        $service->shouldReceive('acmeAvailable')->andReturn(true);
        $service->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('runAcme')->andReturn(['ok' => false, 'output' => 'Challenge failed: DNS not pointing']);

        $tenant = Tenant::query()->where('tenant_id', self::TENANT_ID)->first();

        $result = $service->issueCertificate($tenant);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Challenge failed', (string) TenantSetting::get(self::TENANT_ID, TenantSslService::GROUP_SSL, 'last_issue_error'));
    }

    public function test_toggle_auto_issue_sets_setting(): void
    {
        $service = new TenantSslService;

        $service->setAutoIssue(self::TENANT_ID, true);
        $this->assertTrue($service->isAutoIssue(self::TENANT_ID));

        $service->setAutoIssue(self::TENANT_ID, false);
        $this->assertFalse($service->isAutoIssue(self::TENANT_ID));
        // 关闭时清除历史错误
        $this->assertNull(TenantSetting::get(self::TENANT_ID, TenantSslService::GROUP_SSL, 'last_issue_error'));
    }

    public function test_scheduler_registers_ssl_auto_issue_task(): void
    {
        $scheduler = app(SchedulerService::class);
        $scheduler->register(new Schedule);
        $tasks = $scheduler->getTasks();

        $this->assertArrayHasKey('ssl-auto-issue', $tasks);
        $this->assertSame('ssl:auto-issue', $tasks['ssl-auto-issue']['command']);
    }
}
