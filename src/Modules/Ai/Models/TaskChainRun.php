<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 任务链运行实例（docs/task-chain.md 第四节）
 *
 * steps_state 结构：{steps: [{name, type, status, output_key, output_summary?}], context: {output_key: value}}
 * 链在会话内推进（conversation_id），可中断续跑。
 */
class TaskChainRun extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    // 运行状态
    const STATUS_RUNNING = 'running';

    const STATUS_WAITING_INPUT = 'waiting_input';

    const STATUS_WAITING_CONFIRM = 'waiting_confirm';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    /** 未完成状态集合（可续跑） */
    const UNFINISHED_STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_WAITING_INPUT,
        self::STATUS_WAITING_CONFIRM,
        self::STATUS_FAILED,
    ];

    protected $table = 'task_chain_runs';

    protected $primaryKey = 'run_id';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'chain_key',
        'steps_state',
        'current_step',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'steps_state' => 'array',
            'current_step' => 'integer',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }
}
