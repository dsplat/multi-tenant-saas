<?php

namespace MultiTenantSaas\Modules\Domain\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 租户自定义域名归属自动验证（后台轮询）。
 *
 * 背景：租户绑定自定义域名后需完成 DNS 解析（指向平台）才能访问
 * /.well-known/tenant-verify/{token}.txt 验证文件。在租户完成解析前，
 * 手动点「验证」必然失败且消耗次数上限。本命令由调度器周期性主动
 * GET 验证链接，一旦可达且内容匹配即自动 approve，免去人工审批。
 *
 * 幂等：仅扫描 domain_status=pending 的租户；已 approved 不再触碰。
 */
class AutoVerifyDomains extends Command
{
    protected $signature = 'domains:auto-verify
                          {--tenant= : 仅检测指定租户（tenant_id）}
                          {--dry-run : 仅检测并输出结果，不写入审批状态}
                          {--no-nginx : 审批通过后不重新生成/重载 nginx}';

    protected $description = '轮询检测 pending 域名的验证文件，可达即自动审批通过';

    public function handle(DomainService $domainService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Tenant::query()
            ->whereNotNull('domain')
            ->where('domain', '<>', '')
            ->where('status', 'active')
            ->orderBy('tenant_id');

        if ($only = $this->option('tenant')) {
            $query->where('tenant_id', $only);
        }

        $approved = [];
        $checked = 0;

        foreach ($query->get(['tenant_id', 'name', 'domain']) as $tenant) {
            $tenantId = (int) $tenant->tenant_id;

            // 只轮询 pending：rejected（管理员驳回）不应被自动翻转，
            // approved 已无需检测（TenantSetting 无记录时默认 pending）
            if ($domainService->getDomainStatus($tenantId) !== DomainService::STATUS_PENDING) {
                continue;
            }

            // 过期保护：域名配置超期仍未解析则停止轮询（避免无限扫描僵尸域名）
            if ($this->expired($tenantId)) {
                $this->comment(sprintf('  · 跳过（超期未解析）：%s（%s）', $tenant->domain, $tenant->name));

                continue;
            }

            $checked++;

            if ($dryRun) {
                $this->line(sprintf('  [dry-run] tenant_id=%s domain=%s → 未写入', $tenantId, $tenant->domain));

                continue;
            }

            try {
                if ($domainService->verifyDomainOwnership($tenantId, true)) {
                    $approved[] = $tenant;
                    $this->info(sprintf('  ✓ 自动审批通过：%s（%s）', $tenant->domain, $tenant->name));
                }
            } catch (\Throwable $e) {
                // 单租户失败不中断整轮（日志已记录）
                $this->warn(sprintf('  ✗ 检测异常：%s → %s', $tenant->domain, $e->getMessage()));
            }
        }

        if (! $dryRun && $approved !== [] && ! $this->option('no-nginx')) {
            // 审批后刷新接入层产物（白名单 map/基桩等）：域名可能是审批前一刻新配置的
            $exitCode = Artisan::call('domains:generate-nginx', ['--reload' => true]);
            if ($exitCode === 0) {
                $this->info('nginx 产物已重新生成并 reload');
            } else {
                $this->warn('nginx 产物生成失败，请人工执行：php artisan domains:generate-nginx --reload');
            }
        }

        $this->info(sprintf('本轮检测 %d 个 pending 域名，自动审批 %d 个', $checked, count($approved)));

        return self::SUCCESS;
    }

    /**
     * 域名配置是否已超期（超过轮询窗口即停止检测）
     */
    protected function expired(int $tenantId): bool
    {
        $maxAgeDays = (int) config('domain.verification.auto_verify_max_age_days', 90);

        if ($maxAgeDays <= 0) {
            return false;
        }

        $generatedAt = TenantSetting::get($tenantId, DomainService::GROUP_DOMAIN, 'verification_token_generated_at');

        if (empty($generatedAt)) {
            return false;
        }

        return (int) \Illuminate\Support\Carbon::parse($generatedAt)->diffInDays(now()) > $maxAgeDays;
    }
}
