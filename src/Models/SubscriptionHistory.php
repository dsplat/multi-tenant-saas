<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class SubscriptionHistory extends Model
{
    use BelongsToTenant, HasGlobalId, HasFactory;

    protected $primaryKey = 'subscription_history_id';

    protected $table = 'subscription_histories';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'action',
        'from_plan',
        'to_plan',
        'billing_cycle',
        'amount',
        'proration_amount',
        'starts_at',
        'expires_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'proration_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public const ACTIONS = [
        'subscribe',
        'cancel',
        'change',
        'trial',
        'renew',
        'downgrade',
        'upgrade',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * 记录订阅历史
     */
    public static function record(
        string $tenantId,
        string $action,
        ?string $fromPlan = null,
        ?string $toPlan = null,
        ?string $billingCycle = null,
        float $amount = 0,
        float $prorationAmount = 0,
        ?string $startsAt = null,
        ?string $expiresAt = null,
        ?string $notes = null,
        array $metadata = []
    ): self {
        return static::create([
            'tenant_id' => $tenantId,
            'action' => $action,
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'billing_cycle' => $billingCycle,
            'amount' => $amount,
            'proration_amount' => $prorationAmount,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'notes' => $notes,
            'metadata' => $metadata,
        ]);
    }
}
