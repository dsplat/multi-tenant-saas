<?php

namespace MultiTenantSaas\Models\Sms;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Models\Tenant;

class SmsTemplate extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'sms_template_id';

    public const CHANNEL_MARKETING = 'marketing';

    public const CHANNEL_VERIFICATION = 'verification';

    public const CHANNEL_NOTIFICATION = 'notification';

    public const CHANNELS = [
        self::CHANNEL_MARKETING,
        self::CHANNEL_VERIFICATION,
        self::CHANNEL_NOTIFICATION,
    ];

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'tenant_id', 'name', 'content', 'variables', 'channel',
        'provider_template_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function batchTasks(): HasMany
    {
        return $this->hasMany(SmsBatchTask::class, 'sms_template_id', 'sms_template_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeOfChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }
}
