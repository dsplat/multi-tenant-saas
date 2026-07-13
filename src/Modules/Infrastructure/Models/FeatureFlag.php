<?php

namespace MultiTenantSaas\Modules\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 功能开关
 *
 * 系统级模型（不绑定租户），存储开关定义；通过 conditions 字段的
 * tenant_overrides / user_overrides 实现租户级与用户级覆盖。
 *
 * conditions 字段（JSON）结构：
 *  - ab_groups: A/B 测试分组配置，如 {"control": 50, "treatment": 50}
 *  - tenant_overrides: 租户级覆盖，如 {"1001": true, "1002": false}
 *  - user_overrides: 用户级覆盖，如 {"5001": true}
 *
 * dependencies 字段（JSON）：依赖的其他开关名列表，如 ["ai_text", "beta_features"]
 */
class FeatureFlag extends Model
{
    use HasGlobalId, SoftDeletes;

    /** 范围：全局 */
    public const SCOPE_GLOBAL = 'global';

    /** 范围：租户级 */
    public const SCOPE_TENANT = 'tenant';

    /** 范围：用户级 */
    public const SCOPE_USER = 'user';

    /** 状态：已启用 */
    public const STATUS_ACTIVE = 'active';

    /** 状态：未启用 */
    public const STATUS_INACTIVE = 'inactive';

    protected $primaryKey = 'feature_flag_id';

    protected $fillable = [
        'feature_flag_id',
        'name',
        'description',
        'scope',
        'conditions',
        'dependencies',
        'rollout_percentage',
        'status',
    ];

    protected $attributes = [
        'scope' => self::SCOPE_GLOBAL,
        'rollout_percentage' => 0,
        'status' => self::STATUS_INACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'dependencies' => 'array',
            'rollout_percentage' => 'integer',
        ];
    }

    /**
     * 按名称查找开关
     */
    public static function findByName(string $name): ?self
    {
        /** @var self|null $flag */
        $flag = static::where('name', $name)->first();

        return $flag;
    }

    /**
     * 获取开关变更历史（基于审计日志）
     */
    public function history(): Collection
    {
        return DB::table('audit_logs')
            ->where('resource_type', 'feature_flag')
            ->where('resource_id', $this->feature_flag_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }
}
