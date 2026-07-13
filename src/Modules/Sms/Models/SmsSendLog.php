<?php

namespace MultiTenantSaas\Modules\Sms\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 短信发送记录模型
 *
 * 记录每次短信发送的详细信息，用于到达率统计。
 */
class SmsSendLog extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'log_id';

    protected $fillable = [
        'task_id',
        'tenant_id',
        'phone',
        'content',
        'template_id',
        'status',
        'provider',
        'provider_response',
        'error_message',
        'sent_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(SmsBatchTask::class, 'task_id', 'task_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'template_id', 'template_id');
    }
}
