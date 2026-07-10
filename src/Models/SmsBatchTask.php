<?php

namespace MultiTenantSaas\Models;

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

    protected $primaryKey = 'task_id';

    protected $fillable = [
        'tenant_id',
        'template_id',
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
