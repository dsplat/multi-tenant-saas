<?php

namespace MultiTenantSaas\Modules\SSL\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\SSL\Services\TenantSslService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * SSL 证书自动签发（ACME / Let's Encrypt）。
 *
 * 租户在域名设置页开启「自动签发证书」后，本命令由调度器周期执行：
 * 扫描已审批（approved）且无有效证书、开了自动签发的租户域名，
 * 经 acme.sh HTTP-01 签发并部署到证书目录（目录变更触发 nginx reload）。
 * 续期由 acme.sh 自身 cron 全自动接管。
 *
 * 前置：域名必须已 approved（80 端口白名单放行，LE 挑战可达）。
 */
class AutoIssueSsl extends Command
{
    protected $signature = 'ssl:auto-issue
                          {--tenant= : 仅处理指定租户（tenant_id）}
                          {--dry-run : 仅列出待签发域名，不实际执行}
                          {--no-nginx : 签发后不重新生成/重载 nginx}';

    protected $description = '为开启自动签发的已审批租户域名签发并部署 SSL 证书（acme.sh）';

    public function handle(TenantSslService $sslService): int
    {
        if (! $sslService->acmeAvailable()) {
            $this->warn('ACME 环境不可用（acme.sh 未安装或已禁用），跳过。');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        // 开启了自动签发的租户集合
        $enabledIds = TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('group', TenantSslService::GROUP_SSL)
            ->where('key', 'auto_issue')
            ->whereIn('value', [1, '1', true])
            ->pluck('tenant_id')
            ->map(fn ($id) => (int) $id);

        if ($only = $this->option('tenant')) {
            $enabledIds = $enabledIds->intersect([(int) $only]);
        }

        if ($enabledIds->isEmpty()) {
            $this->info('无开启自动签发的租户。');

            return self::SUCCESS;
        }

        $tenants = Tenant::query()
            ->whereIn('tenant_id', $enabledIds)
            ->whereNotNull('domain')
            ->where('domain', '<>', '')
            ->where('status', 'active')
            ->get();

        $issued = 0;
        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant->tenant_id;

            // 域名必须已审批：pending/rejected 域名不在白名单，LE 挑战到不了
            if ($this->domainStatus($tenantId) !== DomainService::STATUS_APPROVED) {
                continue;
            }

            // 已有有效证书且距到期 > 30 天：跳过；否则重新签发兜底续期（acme.sh cron 为主，此处双保险）
            // Carbon 3：now()->diffInDays($future) 为正，反向相减避免带符号陷阱
            if ($sslService->getCertInfo($tenant)['has_certificate']
                && $tenant->ssl_cert_expires_at
                && ! $tenant->ssl_cert_expires_at->isPast()
                && (int) now()->diffInDays($tenant->ssl_cert_expires_at) > 30) {
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf('  [dry-run] tenant_id=%s domain=%s → 待签发', $tenantId, $tenant->domain));

                continue;
            }

            $result = $sslService->issueCertificate($tenant);

            if ($result['success']) {
                $issued++;
                $this->info(sprintf('  ✓ 已签发并部署：%s', $tenant->domain));
            } else {
                $this->warn(sprintf('  ✗ %s：%s', $tenant->domain, mb_substr($result['message'], 0, 200)));
            }
        }

        $this->info(sprintf('本轮签发 %d 个证书', $issued));

        // 有新证书落盘 → 重生成全部 nginx 产物（含 ssl.map）并 reload，使 SNI 生效，并推送边缘节点
        if ($issued > 0 && ! $this->option('no-nginx')) {
            Artisan::call('domains:generate-nginx', ['--reload' => true]);
            $this->info('  nginx 产物已重新生成并 reload');
            $sslService->pushToEdge();
        }

        return self::SUCCESS;
    }

    protected function domainStatus(int $tenantId): string
    {
        return (string) TenantSetting::get($tenantId, DomainService::GROUP_DOMAIN, 'domain_status', DomainService::STATUS_PENDING);
    }
}
