<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * AI 长任务模型（task/queue 跟踪机制）
 *
 * 重模型/长耗时生成（策划、图片、视频等）统一「快速提交 → queue 后台执行
 * → 短连接轮询取结果」，任务生命周期独立于前端连接（断连不杀任务）。
 *
 * 主键遵循框架铁律：task_id 由 IdGenerator 生成 16 位随机数字
 * （见 .qoder/rules/id-model.md），禁止 snowflake/UUID/自增。
 */
class AiTask extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $primaryKey = 'task_id';

    protected $table = 'ai_tasks';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'agent_id',
        'type',
        'status',
        'payload',
        'result',
        'error',
        'metadata',
        'attempts',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'metadata' => 'array',
            'attempts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * 孤儿任务防御：worker 被 SIGKILL（如 queue timeout）或 job 丢失时，
     * 任务永卡非终态无人推进。ExecuteAiTaskJob timeout=600s，故滞留超
     * 660s 的 pending/processing 必已孤儿——落 failed 让轮询拿到终态。
     *
     * @return bool 是否被判定为孤儿并已落 failed
     */
    public function failIfOrphaned(): bool
    {
        if ($this->isTerminal() || $this->updated_at->gte(now()->subSeconds(660))) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => '后台任务执行超时（工作进程被中断），请重新发起',
            'completed_at' => now(),
        ]);

        return true;
    }
}
