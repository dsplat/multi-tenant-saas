<?php

namespace MultiTenantSaas\Modules\Sms\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Models\Tenant;

class SmsBatchTask extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'batch_task_id';

    public const TYPE_BATCH_SEND = 'batch_send';

    public const TYPE_SCHEDULED = 'scheduled';

    public const TYPES = [
        self::TYPE_BATCH_SEND,
        self::TYPE_SCHEDULED,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'tenant_id', 'sms_template_id', 'type', 'target_type', 'target_ids',
        'phone_column', 'total_count', 'success_count', 'fail_count',
        'status', 'scheduled_at', 'started_at', 'completed_at', 'error_log',
    ];

    protected function casts(): array
    {
        return [
            'target_ids' => 'array',
            'total_count' => 'integer',
            'success_count' => 'integer',
            'fail_count' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id', 'sms_template_id');
    }

    public function deliveryStats(): HasMany
    {
        return $this->hasMany(SmsDeliveryStat::class, 'sms_batch_task_id', 'batch_task_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }
}
