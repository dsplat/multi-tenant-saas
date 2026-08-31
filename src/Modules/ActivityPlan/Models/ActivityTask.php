<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 排期任务（由 PlanCompiler 编译产出）
 *
 * trigger_type=at_time：scheduled_at 到点后由 activity_plan:process-due 触发；
 * trigger_type=on_event：listen_event 字段预留，Phase 0 不实现监听。
 *
 * assignee_type=user（学员侧扩展）：任务语义为"学员学习任务"（每日打卡任务/
 * 训练营营期任务），不自动执行，学员完成记录落 activity_task_completions。
 */
class ActivityTask extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    // 触发类型
    const TRIGGER_AT_TIME = 'at_time';

    const TRIGGER_ON_EVENT = 'on_event';

    // 执行者类型（user = 学员侧学习任务，完成记录见 activity_task_completions）
    const ASSIGNEE_SYSTEM = 'system';

    const ASSIGNEE_HUMAN = 'human';

    const ASSIGNEE_AGENT = 'agent';

    const ASSIGNEE_USER = 'user';

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

    protected $table = 'activity_tasks';

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
        return $this->belongsTo(ActivityPlan::class, 'plan_id', 'plan_id');
    }

    /**
     * 学员完成记录（仅 assignee_type=user 有意义）
     */
    public function completions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ActivityTaskCompletion::class, 'task_id', 'task_id');
    }

    /**
     * 学员侧任务：指定学员是否已完成（幂等查询）
     */
    public function isCompletedBy(int $userId): bool
    {
        return $this->completions()
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * 学员侧任务：批量查询学员完成状态 → [task_id => completion]
     *
     * @param array<int> $taskIds
     * @return array<int, ActivityTaskCompletion>
     */
    public static function completionMapFor(int $tenantId, int $userId, array $taskIds): array
    {
        return ActivityTaskCompletion::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('task_id', $taskIds)
            ->get()
            ->keyBy('task_id')
            ->all();
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
