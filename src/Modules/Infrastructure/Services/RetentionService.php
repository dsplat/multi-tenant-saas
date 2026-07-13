<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Infrastructure\Models\DataRetentionPolicy;

/**
 * 数据保留服务
 *
 * 功能：
 *  - 数据保留期限配置（按数据类型）
 *  - 自动清理过期数据（定时任务）
 *  - 清理前通知
 *  - 豁免标记（法律/合规保留）
 */
class RetentionService
{
    /**
     * 数据类型配置：表名、日期字段、匿名化字段
     *
     * @var array<string, array{table: string, date_field: string, anonymize_fields: array<int, string>}>
     */
    protected array $dataTypeConfigs = [
        'user_sessions' => [
            'table' => 'user_sessions',
            'date_field' => 'last_active_at',
            'anonymize_fields' => ['ip_address', 'device_info', 'device_fingerprint', 'location'],
        ],
        'audit_logs' => [
            'table' => 'audit_logs',
            'date_field' => 'created_at',
            'anonymize_fields' => ['ip_address', 'user_agent'],
        ],
        'ai_requests' => [
            'table' => 'ai_requests',
            'date_field' => 'created_at',
            'anonymize_fields' => ['prompt_summary'],
        ],
        'password_histories' => [
            'table' => 'password_histories',
            'date_field' => 'created_at',
            'anonymize_fields' => [],
        ],
        'structured_logs' => [
            'table' => 'structured_logs',
            'date_field' => 'created_at',
            'anonymize_fields' => ['ip_address', 'user_agent'],
        ],
        'consents' => [
            'table' => 'consents',
            'date_field' => 'created_at',
            'anonymize_fields' => ['ip_address', 'user_agent'],
        ],
        'trusted_devices' => [
            'table' => 'trusted_devices',
            'date_field' => 'updated_at',
            'anonymize_fields' => ['ip_address', 'user_agent'],
        ],
        'oauth_accounts' => [
            'table' => 'oauth_accounts',
            'date_field' => 'created_at',
            'anonymize_fields' => ['access_token', 'refresh_token', 'provider_email', 'provider_name'],
        ],
        'file_uploads' => [
            'table' => 'file_uploads',
            'date_field' => 'created_at',
            'anonymize_fields' => [],
        ],
        'credit_transactions' => [
            'table' => 'credit_transactions',
            'date_field' => 'created_at',
            'anonymize_fields' => [],
        ],
    ];

    /**
     * 获取数据保留策略（租户级优先，回退到系统级）
     *
     * @param  string  $dataType  数据类型
     * @param  int|null  $tenantId  租户 ID（null 表示系统级）
     */
    public function getPolicy(string $dataType, ?int $tenantId = null): ?DataRetentionPolicy
    {
        if ($tenantId !== null) {
            $policy = DataRetentionPolicy::where('data_type', $dataType)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($policy) {
                return $policy;
            }
        }

        return DataRetentionPolicy::where('data_type', $dataType)
            ->whereNull('tenant_id')
            ->first();
    }

    /**
     * 创建或更新数据保留策略
     *
     * @param  string  $dataType  数据类型
     * @param  int  $retentionDays  保留天数
     * @param  bool  $autoCleanup  是否自动清理
     * @param  string  $cleanupStrategy  清理策略（delete/anonymize）
     * @param  int|null  $tenantId  租户 ID（null 表示系统级）
     * @param  string|null  $description  描述
     */
    public function createOrUpdatePolicy(
        string $dataType,
        int $retentionDays,
        bool $autoCleanup = true,
        string $cleanupStrategy = DataRetentionPolicy::STRATEGY_ANONYMIZE,
        ?int $tenantId = null,
        ?string $description = null,
    ): DataRetentionPolicy {
        $query = DataRetentionPolicy::where('data_type', $dataType);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        } else {
            $query->whereNull('tenant_id');
        }

        $policy = $query->first();

        if ($policy) {
            $policy->update([
                'retention_days' => $retentionDays,
                'auto_cleanup' => $autoCleanup,
                'cleanup_strategy' => $cleanupStrategy,
                'description' => $description ?? $policy->description,
            ]);

            return $policy->fresh();
        }

        return DataRetentionPolicy::create([
            'tenant_id' => $tenantId,
            'data_type' => $dataType,
            'retention_days' => $retentionDays,
            'auto_cleanup' => $autoCleanup,
            'cleanup_strategy' => $cleanupStrategy,
            'description' => $description,
        ]);
    }

    /**
     * 删除数据保留策略
     *
     * @param  int  $policyId  策略 ID
     * @return bool 是否删除成功
     */
    public function deletePolicy(int $policyId): bool
    {
        $policy = DataRetentionPolicy::find($policyId);

        if (! $policy) {
            return false;
        }

        return $policy->delete();
    }

    /**
     * 标记策略为豁免（法律/合规保留）
     *
     * @param  int  $policyId  策略 ID
     * @param  bool  $exempt  是否豁免
     */
    public function markExempt(int $policyId, bool $exempt = true): ?DataRetentionPolicy
    {
        $policy = DataRetentionPolicy::find($policyId);

        if (! $policy) {
            return null;
        }

        $policy->is_exempt = $exempt;
        $policy->save();

        return $policy;
    }

    /**
     * 检查策略是否豁免
     *
     * @param  int  $policyId  策略 ID
     */
    public function isExempt(int $policyId): bool
    {
        $policy = DataRetentionPolicy::find($policyId);

        return $policy?->is_exempt ?? false;
    }

    /**
     * 查找过期数据统计
     *
     * @return array{total: int, details: array<string, int>}
     */
    public function findExpiredData(): array
    {
        $details = [];
        $total = 0;

        $policies = $this->getCleanupablePolicies();

        foreach ($policies as $policy) {
            $config = $this->getDataTypeConfig($policy->data_type);

            if (! $config) {
                continue;
            }

            $count = $this->buildExpiredQuery($policy, $config)->count();
            $details[$policy->data_type] = $count;
            $total += $count;
        }

        return [
            'total' => $total,
            'details' => $details,
        ];
    }

    /**
     * 查找即将过期的数据（用于清理前通知）
     *
     * @param  int  $noticeDays  通知天数（在此天数内将过期的数据）
     * @return array<string, int>
     */
    public function getExpiringData(int $noticeDays = 7): array
    {
        $results = [];

        $policies = $this->getCleanupablePolicies();

        foreach ($policies as $policy) {
            $config = $this->getDataTypeConfig($policy->data_type);

            if (! $config) {
                continue;
            }

            $cutoffDate = $policy->cutoffDate();
            $noticeCutoff = (clone $cutoffDate)->addDays($noticeDays);

            $query = DB::table($config['table'])
                ->where($config['date_field'], '>=', $cutoffDate)
                ->where($config['date_field'], '<', $noticeCutoff);

            if ($policy->tenant_id) {
                $query->where('tenant_id', $policy->tenant_id);
            }

            $count = $query->count();

            if ($count > 0) {
                $results[$policy->data_type] = $count;
            }
        }

        return $results;
    }

    /**
     * 清理前通知（记录即将过期的数据）
     *
     * @param  int  $noticeDays  通知天数
     * @return array<string, int> 即将过期的数据统计
     */
    public function notifyBeforeCleanup(int $noticeDays = 7): array
    {
        $expiring = $this->getExpiringData($noticeDays);

        if (! empty($expiring)) {
            Log::info('GDPR 数据保留：以下数据即将过期', $expiring);
        }

        return $expiring;
    }

    /**
     * 清理过期数据
     *
     * 遍历所有自动清理且未豁免的策略，对过期数据执行删除或匿名化。
     *
     * @return int 清理的记录总数
     */
    public function cleanupExpiredData(): int
    {
        $totalCleaned = 0;

        $policies = $this->getCleanupablePolicies();

        foreach ($policies as $policy) {
            $config = $this->getDataTypeConfig($policy->data_type);

            if (! $config) {
                continue;
            }

            $query = $this->buildExpiredQuery($policy, $config);

            if ($policy->cleanup_strategy === DataRetentionPolicy::STRATEGY_DELETE || empty($config['anonymize_fields'])) {
                $totalCleaned += $query->delete();
            } else {
                // 匿名化策略
                $anonymizeData = [];
                foreach ($config['anonymize_fields'] as $field) {
                    $anonymizeData[$field] = null;
                }

                $totalCleaned += $query->update($anonymizeData);
            }
        }

        return $totalCleaned;
    }

    /**
     * 获取所有可清理的策略（自动清理 + 未豁免）
     *
     * @return Collection<int, DataRetentionPolicy>
     */
    protected function getCleanupablePolicies(): Collection
    {
        return DataRetentionPolicy::where('auto_cleanup', true)
            ->where('is_exempt', false)
            ->get();
    }

    /**
     * 构建过期数据查询
     *
     * @param  DataRetentionPolicy  $policy  保留策略
     * @param  array{table: string, date_field: string, anonymize_fields: array<int, string>}  $config  数据类型配置
     */
    protected function buildExpiredQuery(DataRetentionPolicy $policy, array $config): Builder
    {
        $query = DB::table($config['table'])
            ->where($config['date_field'], '<', $policy->cutoffDate());

        if ($policy->tenant_id) {
            $query->where('tenant_id', $policy->tenant_id);
        }

        return $query;
    }

    /**
     * 获取数据类型配置
     *
     * @param  string  $dataType  数据类型
     * @return array{table: string, date_field: string, anonymize_fields: array<int, string>}|null
     */
    public function getDataTypeConfig(string $dataType): ?array
    {
        return $this->dataTypeConfigs[$dataType] ?? null;
    }

    /**
     * 获取所有支持的数据类型
     *
     * @return array<int, string>
     */
    public function getSupportedDataTypes(): array
    {
        return array_keys($this->dataTypeConfigs);
    }

    /**
     * 获取所有策略列表
     *
     * @param  int|null  $tenantId  租户 ID（null 表示全部）
     * @return Collection<int, DataRetentionPolicy>
     */
    public function listPolicies(?int $tenantId = null): Collection
    {
        $query = DataRetentionPolicy::query();

        if ($tenantId !== null) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        return $query->orderBy('data_type')->get();
    }
}
