<?php

namespace MultiTenantSaas\Modules\Campaign\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 排期任务（由 PlanCompiler 编译产出）
 *
 * trigger_type=at_time：scheduled_at 到点后由 campaign:process-due 触发；
 * trigger_type=on_event：listen_event 字段预留，Phase 0 不实现监听。
 */
class CampaignTask extends Model
{
    use BelongsToTenant, HasGlobalId;

    // 触发类型
    const TRIGGER_AT_TIME = 'at_time';

    const TRIGGER_ON_EVENT = 'on_event';

    // 执行模式
    const MODE_AUTO = 'auto';

    const MODE_REQUIRE_CONFIRM = 'require_confirm';

    // 状态
    const STATUS_PENDING = 'pending';

    const STATUS_AWAITING_CONFIRM = 'awaiting_confirm';

    const STATUS_RUNNING = 'running';

    const STATUS_DONE = 'done';

    const STATUS_FAILED = 'failed';

    const STATUS_SKIPPED = 'skipped';

    const STATUS_CANCELLED = 'cancelled';

    protected $table = 'campaign_tasks';

    protected $primaryKey = 'task_id';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'task_key',
        'title',
        'phase_key',
        'trigger_type',
        'scheduled_at',
        'listen_event',
        'assignee_type',
        'assignee_ref',
        'action',
        'execution_mode',
        'depends_on',
        'status',
        'output',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => 'array',
            'depends_on' => 'array',
            'output' => 'array',
            'scheduled_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CampaignPlan::class, 'plan_id', 'plan_id');
    }

    /**
     * 是否处于终态（不可再变更）
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_DONE,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
