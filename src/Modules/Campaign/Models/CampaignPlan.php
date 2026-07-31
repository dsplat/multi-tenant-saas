<?php

namespace MultiTenantSaas\Modules\Campaign\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 排期计划（docs/event-plan.md 第四节）
 *
 * plan_doc 为 campaign.plan/v1 schema 的 JSON 文档，
 * 经 PlanCompiler::compile() 编译后生成 campaign_tasks 记录。
 */
class CampaignPlan extends Model
{
    use BelongsToTenant, HasGlobalId;

    // 状态
    const STATUS_PLANNING = 'planning';

    const STATUS_SCHEDULED = 'scheduled';

    const STATUS_RUNNING = 'running';

    const STATUS_REVIEWING = 'reviewing';

    const STATUS_CLOSED = 'closed';

    const STATUS_CANCELLED = 'cancelled';

    protected $table = 'campaign_plans';

    protected $primaryKey = 'plan_id';

    protected $fillable = [
        'tenant_id',
        'anchor_type',
        'anchor_id',
        'plan_doc',
        'status',
        'playbook_key',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'plan_doc' => 'array',
            'metadata' => 'array',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CampaignTask::class, 'plan_id', 'plan_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_PLANNING, self::STATUS_SCHEDULED], true);
    }
}
