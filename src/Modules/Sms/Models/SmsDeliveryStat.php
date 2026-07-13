<?php

namespace MultiTenantSaas\Modules\Sms\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class SmsDeliveryStat extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'stat_id';

    protected $fillable = [
        'tenant_id', 'sms_batch_task_id', 'sent_count', 'delivered_count',
        'failed_count', 'clicked_count', 'unsubscribed_count',
        'delivery_rate', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_count' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'clicked_count' => 'integer',
            'unsubscribed_count' => 'integer',
            'delivery_rate' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function batchTask(): BelongsTo
    {
        return $this->belongsTo(SmsBatchTask::class, 'sms_batch_task_id', 'batch_task_id');
    }
}
