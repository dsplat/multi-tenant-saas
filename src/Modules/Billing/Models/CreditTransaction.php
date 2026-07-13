<?php

namespace MultiTenantSaas\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class CreditTransaction extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'transaction_id';

    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id',
        'tenant_id',
        'user_id',
        'type',
        'amount',
        'balance_after',
        'related_type',
        'related_id',
        'description',
        'metadata',
        'expires_at',
        'expired',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'expired' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CreditAccount::class, 'account_id', 'credit_account_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'related_type', 'related_id');
    }

    public function isRecharge(): bool
    {
        return $this->type === 'recharge';
    }

    public function isConsume(): bool
    {
        return $this->type === 'consume';
    }

    public function isRefund(): bool
    {
        return $this->type === 'refund';
    }

    public function isTransfer(): bool
    {
        return $this->type === 'transfer';
    }

    public function isGift(): bool
    {
        return $this->type === 'gift';
    }
}
