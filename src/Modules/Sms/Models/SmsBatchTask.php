<?php

namespace MultiTenantSaas\Modules\Sms\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 短信发送任务模型
 *
 * 批量短信发送任务，支持定时发送与到达率统计。
 */
class SmsBatchTask extends Model
{
    use HasFactory, HasGlobalId;

    // 任务类型
    const TYPE_BATCH_SEND = 'batch_send';

    const TYPE_SCHEDULED = 'scheduled';

    // 状态
    const STATUS_PENDING = 'pending';

    const STATUS_SENDING = 'sending';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    protected $primaryKey = 'batch_task_id';

    protected $fillable = [
        'tenant_id',
        'sms_template_id',
        'type',
        'target_type',
        'target_ids',
        'name',
        'status',
        'total_count',
        'sent_count',
        'success_count',
        'fail_count',
        'scheduled_at',
        'started_at',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'target_ids' => 'array',
            'total_count' => 'integer',
            'sent_count' => 'integer',
            'success_count' => 'integer',
            'fail_count' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
