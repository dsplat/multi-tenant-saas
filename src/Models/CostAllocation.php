<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 成本分摊记录
 *
 * 记录租户级成本分摊数据，按月聚合。
 *
 * 成本类型（cost_type）：
 *  - infrastructure: 基础设施成本（计算/存储/带宽）
 *  - ai_usage:       AI 用量成本（由 AiUsageService 联动归入）
 *  - third_party:    第三方服务成本
 *
 * 分摊依据（allocation_basis）描述成本分摊规则，如 by_users / by_storage / by_requests。
 */
class CostAllocation extends Model
{
    use HasGlobalId, BelongsToTenant;

    /** 成本类型：基础设施 */
    public const TYPE_INFRASTRUCTURE = 'infrastructure';

    /** 成本类型：AI 用量 */
    public const TYPE_AI_USAGE = 'ai_usage';

    /** 成本类型：第三方服务 */
    public const TYPE_THIRD_PARTY = 'third_party';

    /** 基础设施子类型：计算 */
    public const SUBTYPE_COMPUTE = 'compute';

    /** 基础设施子类型：存储 */
    public const SUBTYPE_STORAGE = 'storage';

    /** 基础设施子类型：带宽 */
    public const SUBTYPE_BANDWIDTH = 'bandwidth';

    protected $primaryKey = 'cost_allocation_id';

    protected $fillable = [
        'cost_allocation_id',
        'tenant_id',
        'cost_type',
        'cost_subtype',
        'amount',
        'currency',
        'period',
        'allocation_basis',
        'allocation_value',
        'metadata',
    ];

    protected $attributes = [
        'currency' => 'CNY',
        'cost_type' => self::TYPE_INFRASTRUCTURE,
    ];

    protected function casts(): array
    {
        return [
            'cost_allocation_id' => 'integer',
            'tenant_id' => 'integer',
            'amount' => 'float',
            'allocation_value' => 'float',
            'metadata' => 'array',
        ];
    }

    /**
     * 查询指定周期内的成本分摊记录
     *
     * @param  string  $period  计费周期（YYYY-MM）
     * @param  int|null  $tenantId  租户 ID（NULL 表示系统级）
     * @return \Illuminate\Support\Collection
     */
    public static function periodRecords(string $period, ?int $tenantId = null): \Illuminate\Support\Collection
    {
        $query = \Illuminate\Support\Facades\DB::table('cost_allocations')
            ->where('period', $period);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->get();
    }
}
