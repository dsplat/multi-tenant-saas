<?php

namespace MultiTenantSaas\Modules\Domain\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Domain\Services\SlugService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 为既有「无 slug」的租户回填自动子域名（t-xxxxxx 免费兜底）。
 *
 * 背景：自动子域名策略上线前创建的租户可能 slug 为空（仅能 /{tenant_id}/ 访问）。
 * 本命令为其生成 t-<随机码> 写入 slug 字段并置 slug_status=active，使其获得
 * 免费的 {slug}.<wildcard_base> 二级域名访问能力（与新建租户一致）。
 *
 * 幂等：仅处理 slug 为空（null 或 ''）的租户；已有 slug（含 t- 自动码）者跳过。
 */
class BackfillAutoSlug extends Command
{
    protected $signature = 'domains:backfill-auto-slug
                          {--dry-run : 仅列出待回填租户，不实际写入}';

    protected $description = '为既有无 slug 的租户回填自动子域名（t-xxxxxx 免费兜底）';

    public function handle(SlugService $slugService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $tenants = Tenant::query()
            ->where(function ($q) {
                $q->whereNull('slug')->orWhere('slug', '');
            })
            ->orderBy('tenant_id')
            ->get(['tenant_id', 'name', 'slug']);

        if ($tenants->isEmpty()) {
            $this->info('无需回填：所有租户均已具备 slug。');

            return self::SUCCESS;
        }

        $this->info(sprintf('发现 %d 个无 slug 租户%s', $tenants->count(), $dryRun ? '（dry-run）' : ''));

        $filled = 0;
        foreach ($tenants as $tenant) {
            $autoSlug = $slugService->generateUniqueAutoSlug();

            if ($dryRun) {
                $this->line(sprintf('  [dry-run] tenant_id=%s name=%s → %s', $tenant->tenant_id, $tenant->name, $autoSlug));

                continue;
            }

            $tenant->slug = $autoSlug;
            $tenant->slug_status = SlugService::STATUS_ACTIVE;
            $tenant->save();
            $filled++;

            $this->line(sprintf('  ✓ tenant_id=%s name=%s → %s', $tenant->tenant_id, $tenant->name, $autoSlug));
        }

        if ($dryRun) {
            $this->newLine();
            $this->comment('dry-run 未写入。确认后去掉 --dry-run 执行；随后重新生成 nginx 产物并 reload。');
        } else {
            $this->newLine();
            $this->info(sprintf('已回填 %d 个租户。请重新生成 nginx 产物：php artisan domains:generate-nginx', $filled));
        }

        return self::SUCCESS;
    }
}
