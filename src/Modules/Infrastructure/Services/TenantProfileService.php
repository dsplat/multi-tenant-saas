<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Billing\Models\FinancialRecord;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;

/**
 * 租户画像服务
 *
 * 提供租户使用统计、资源配额、账单信息、健康状态以及生命周期管理。
 *
 * 租户隔离：所有方法均接受显式 $tenantId 参数；
 * 跨租户汇总方法（如 backupAll）需在 admin 域名上下文调用。
 */
class TenantProfileService
{
    /**
     * 获取租户使用统计
     *
     * @param  int  $tenantId  目标租户 ID
     * @return array{
     *   tenant: Tenant,
     *   users: int,
     *   credit_balance: int,
     *   credit_used: int,
     *   storage_used_mb: int,
     *   api_calls_30d: int,
     *   payment_count_30d: int,
     *   last_activity: string|null
     * }
     *
     * @throws ModelNotFoundException
     */
    public function getUsageStats(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);

        $userCount = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $creditAccount = CreditAccount::where('tenant_id', $tenantId)
            ->whereNull('user_id')
            ->first();

        $storageUsed = (int) DB::table('file_uploads')
            ->where('tenant_id', $tenantId)
            ->sum('size');

        $apiCalls = AuditLog::where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $paymentCount = DB::table('payment_orders')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $lastActivity = AuditLog::where('tenant_id', $tenantId)
            ->latest()
            ->value('created_at');

        return [
            'tenant' => $tenant,
            'users' => $userCount,
            'credit_balance' => $creditAccount?->balance ?? 0,
            'credit_used' => $tenant->used_credits ?? 0,
            'storage_used_mb' => (int) ceil($storageUsed / 1024 / 1024),
            'api_calls_30d' => $apiCalls,
            'payment_count_30d' => $paymentCount,
            'last_activity' => optional($lastActivity)?->toIso8601String(),
        ];
    }

    /**
     * 获取租户资源配额
     *
     * 配额来源：subscription_plan 的 limits 配置；可由 TenantSetting 覆盖。
     *
     * @param  int  $tenantId  目标租户 ID
     * @return array{
     *   plan: string,
     *   limits: array{max_users: int, max_storage_mb: int},
     *   usage: array{users: int, storage_mb: int},
     *   exceeded: array<string,bool>
     * }
     */
    public function getResourceQuota(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $plan = $tenant->subscription_plan ?? 'free';

        $planLimits = config("tenancy.plans.{$plan}.limits", [
            'max_users' => 5,
            'max_storage_mb' => 1024,
        ]);

        // TenantSetting 可覆盖配额
        $overrideUsers = TenantSetting::get($tenantId, 'quota', 'max_users');
        $overrideStorage = TenantSetting::get($tenantId, 'quota', 'max_storage_mb');

        $limits = [
            'max_users' => $overrideUsers !== null ? (int) $overrideUsers : (int) $planLimits['max_users'],
            'max_storage_mb' => $overrideStorage !== null ? (int) $overrideStorage : (int) $planLimits['max_storage_mb'],
        ];

        $usage = [
            'users' => (int) DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->count(),
            'storage_mb' => (int) ceil((int) DB::table('file_uploads')->where('tenant_id', $tenantId)->sum('size') / 1024 / 1024),
        ];

        $exceeded = [
            'users' => $usage['users'] >= $limits['max_users'],
            'storage' => $usage['storage_mb'] >= $limits['max_storage_mb'],
        ];

        return [
            'plan' => $plan,
            'limits' => $limits,
            'usage' => $usage,
            'exceeded' => $exceeded,
        ];
    }

    /**
     * 获取租户账单信息
     *
     * @param  int  $tenantId  目标租户 ID
     * @return array{
     *   total_revenue: int,
     *   total_expense: int,
     *   net_balance: int,
     *   recent_records: Collection
     * }
     */
    public function getBillingInfo(int $tenantId): array
    {
        $revenue = (int) FinancialRecord::where('tenant_id', $tenantId)
            ->whereIn('type', ['recharge', 'commission'])
            ->sum('amount');

        $expense = (int) FinancialRecord::where('tenant_id', $tenantId)
            ->where('type', 'refund')
            ->sum('amount');

        $recent = FinancialRecord::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'total_revenue' => $revenue,
            'total_expense' => $expense,
            'net_balance' => $revenue - $expense,
            'recent_records' => $recent,
        ];
    }

    /**
     * 获取租户健康状态
     *
     * @param  int  $tenantId  目标租户 ID
     * @return array{
     *   status: string,
     *   subscription_active: bool,
     *   quota_exceeded: bool,
     *   ssl_expiring: bool,
     *   issues: array<int,string>
     * }
     */
    public function getHealthStatus(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $issues = [];

        $subscriptionActive = $tenant->isSubscriptionActive();
        if (! $subscriptionActive) {
            $issues[] = trans('common.subscription_inactive');
        }

        $quota = $this->getResourceQuota($tenantId);
        $quotaExceeded = ! empty(array_filter($quota['exceeded']));
        if ($quotaExceeded) {
            $issues[] = trans('common.quota_exceeded');
        }

        $sslExpiring = false;
        if ($tenant->ssl_cert_expires_at && $tenant->ssl_cert_expires_at->isPast()) {
            $sslExpiring = true;
            $issues[] = trans('common.ssl_expired');
        } elseif ($tenant->ssl_cert_expires_at && $tenant->ssl_cert_expires_at->diffInDays(now()) <= 30) {
            $sslExpiring = true;
            $issues[] = trans('common.ssl_expiring');
        }

        $status = empty($issues) ? 'healthy' : 'warning';
        if (! $tenant->isActive()) {
            $status = 'inactive';
            $issues[] = trans('common.tenant_inactive');
        }

        return [
            'status' => $status,
            'subscription_active' => $subscriptionActive,
            'quota_exceeded' => $quotaExceeded,
            'ssl_expiring' => $sslExpiring,
            'issues' => $issues,
        ];
    }

    /**
     * 管理租户试用期
     *
     * @param  int  $tenantId  目标租户 ID
     * @param  int  $days  试用天数
     *
     * @throws ModelNotFoundException
     * @throws \RuntimeException 写入失败
     */
    public function startTrial(int $tenantId, int $days = 14): Tenant
    {
        DB::beginTransaction();
        try {
            $tenant = Tenant::findOrFail($tenantId);
            $tenant->trial_ends_at = now()->addDays($days);
            $tenant->save();

            AuditService::log(
                action: 'trial_started',
                resourceType: 'tenant',
                resourceId: $tenantId,
                newValues: ['days' => $days, 'ends_at' => $tenant->trial_ends_at?->toIso8601String()]
            );

            DB::commit();

            return $tenant->fresh();
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException(trans('common.trial_start_failed') . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 检查试用期是否已过期
     *
     * @param  int  $tenantId  目标租户 ID
     */
    public function isTrialExpired(int $tenantId): bool
    {
        $tenant = Tenant::findOrFail($tenantId);

        if (! $tenant->trial_ends_at) {
            return false;
        }

        return $tenant->trial_ends_at->isPast();
    }

    /**
     * 迁移租户数据到新租户（仅元数据迁移；具体业务数据迁移由派生项目实现）
     *
     * @param  int  $sourceTenantId  源租户 ID
     * @param  int  $targetTenantId  目标租户 ID
     * @param  array<string>  $resources  待迁移资源类型（默认 users, files, settings）
     * @return array{migrated: array<string,int>}
     *
     * @throws \RuntimeException
     */
    public function migrateData(int $sourceTenantId, int $targetTenantId, array $resources = ['users', 'files', 'settings']): array
    {
        if ($sourceTenantId === $targetTenantId) {
            throw new \RuntimeException(trans('common.migration_same_tenant'));
        }

        $migrated = [];
        DB::beginTransaction();
        try {
            if (in_array('users', $resources)) {
                $migrated['users'] = DB::table('tenant_users')
                    ->where('tenant_id', $sourceTenantId)
                    ->update(['tenant_id' => $targetTenantId]);
            }

            if (in_array('files', $resources)) {
                $migrated['files'] = DB::table('file_uploads')
                    ->where('tenant_id', $sourceTenantId)
                    ->update(['tenant_id' => $targetTenantId]);
            }

            if (in_array('settings', $resources)) {
                $migrated['settings'] = DB::table('tenant_settings')
                    ->where('tenant_id', $sourceTenantId)
                    ->update(['tenant_id' => $targetTenantId]);
            }

            AuditService::log(
                action: 'tenant_data_migrated',
                resourceType: 'tenant',
                resourceId: $targetTenantId,
                oldValues: ['source_tenant_id' => $sourceTenantId],
                newValues: ['migrated' => $migrated]
            );

            DB::commit();

            return $migrated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException(trans('common.data_migration_failed') . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 备份租户配置（导出为 JSON）
     *
     * @param  int  $tenantId  目标租户 ID
     * @return array{
     *   tenant: array,
     *   settings: Collection,
     *   billing: array,
     *   exported_at: string
     * }
     */
    public function backupTenant(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);

        $settings = TenantSetting::where('tenant_id', $tenantId)->get();

        return [
            'tenant' => $tenant->only([
                'name', 'slug', 'custom_domain', 'subscription_plan', 'status',
                'settings', 'branding', 'contact_name', 'contact_email', 'contact_phone',
            ]),
            'settings' => $settings,
            'billing' => $this->getBillingInfo($tenantId),
            'exported_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 清理租户数据（保留 tenant 主记录，清理业务数据）
     *
     * @param  int  $tenantId  目标租户 ID
     * @param  bool  $dryRun  仅统计不实际删除
     * @return array<string,int>
     *
     * @throws \RuntimeException
     */
    public function cleanupData(int $tenantId, bool $dryRun = false): array
    {
        $tables = ['tenant_users', 'file_uploads', 'tenant_settings', 'audit_logs', 'credit_accounts'];
        $counts = [];

        DB::beginTransaction();
        try {
            foreach ($tables as $table) {
                $count = DB::table($table)->where('tenant_id', $tenantId)->count();
                $counts[$table] = $count;

                if (! $dryRun && $count > 0) {
                    DB::table($table)->where('tenant_id', $tenantId)->delete();
                }
            }

            if (! $dryRun) {
                AuditService::log(
                    action: 'tenant_data_cleaned',
                    resourceType: 'tenant',
                    resourceId: $tenantId,
                    newValues: ['cleaned' => $counts]
                );
            }

            DB::commit();

            return $counts;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException(trans('common.cleanup_failed') . ': ' . $e->getMessage(), 0, $e);
        }
    }
}
