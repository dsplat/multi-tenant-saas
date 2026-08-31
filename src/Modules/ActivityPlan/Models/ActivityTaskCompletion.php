<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 学员侧任务完成记录（ActivityTask assignee_type='user'）
 *
 * 唯一约束 (tenant_id, task_id, user_id)：一个学员对一个任务仅一条，幂等。
 */
class ActivityTaskCompletion extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    protected $table = 'activity_task_completions';

    protected $primaryKey = 'completion_id';

    protected $fillable = [
        'tenant_id',
        'task_id',
        'user_id',
        'completed_at',
        'output',
    ];

    protected function casts(): array
    {
        return [
            'output'       => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ActivityTask::class, 'task_id', 'task_id');
    }
}
