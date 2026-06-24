<?php

namespace MultiTenantSaas\Models;

use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditAccount extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'credit_account_id';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'account_type',
        'balance',
        'gift_balance',
        'recharge_balance',
        'total_recharged',
        'total_consumed',
        'expires_at',
        'expired_total',
        'last_warning_at',
        'auto_recharge_enabled',
        'auto_recharge_threshold',
        'auto_recharge_amount',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'gift_balance' => 'integer',
            'recharge_balance' => 'integer',
            'total_recharged' => 'integer',
            'total_consumed' => 'integer',
            'expires_at' => 'datetime',
            'expired_total' => 'integer',
            'last_warning_at' => 'datetime',
            'auto_recharge_enabled' => 'boolean',
            'auto_recharge_threshold' => 'integer',
            'auto_recharge_amount' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'account_id', 'credit_account_id');
    }

    public function isEnterpriseAccount(): bool
    {
        return $this->account_type === 'enterprise';
    }

    public function isPersonalAccount(): bool
    {
        return $this->account_type === 'personal';
    }

    public function hasEnoughBalance(int $amount): bool
    {
        return $this->balance >= $amount;
    }

    public function recharge(int $userId, int $amount, ?string $description = null, ?array $metadata = null): CreditTransaction
    {
        $this->increment('balance', $amount);
        $this->increment('recharge_balance', $amount);
        $this->increment('total_recharged', $amount);

        return $this->transactions()->create([
            'tenant_id' => $this->tenant_id,
            'user_id' => $userId,
            'type' => 'recharge',
            'amount' => $amount,
            'balance_after' => $this->balance,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    public function gift(int $userId, int $amount, int $expireDays = 30, ?string $description = null, ?array $metadata = null): CreditTransaction
    {
        $this->increment('balance', $amount);
        $this->increment('gift_balance', $amount);

        $expiresAt = $expireDays > 0 ? now()->addDays($expireDays) : null;

        $meta = array_merge($metadata ?? [], [
            'expire_days' => $expireDays,
        ]);

        return $this->transactions()->create([
            'tenant_id' => $this->tenant_id,
            'user_id' => $userId,
            'type' => 'gift',
            'amount' => $amount,
            'balance_after' => $this->balance,
            'description' => $description,
            'metadata' => $meta,
            'expires_at' => $expiresAt,
        ]);
    }

    public function consume(int $amount, ?string $relatedType = null, ?string $relatedId = null, ?string $description = null): CreditTransaction
    {
        if (! $this->hasEnoughBalance($amount)) {
            throw new \Exception('Insufficient balance');
        }

        $giftDeduct = min($amount, $this->gift_balance);
        $rechargeDeduct = $amount - $giftDeduct;

        if ($giftDeduct > 0) {
            $this->decrement('gift_balance', $giftDeduct);
        }
        if ($rechargeDeduct > 0) {
            $this->decrement('recharge_balance', $rechargeDeduct);
        }

        $this->decrement('balance', $amount);
        $this->increment('total_consumed', $amount);

        return $this->transactions()->create([
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'type' => 'consume',
            'amount' => -$amount,
            'balance_after' => $this->balance,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'description' => $description,
            'metadata' => [
                'gift_deducted' => $giftDeduct,
                'recharge_deducted' => $rechargeDeduct,
            ],
        ]);
    }

    public function refund(int $amount, ?string $description = null): CreditTransaction
    {
        $this->increment('balance', $amount);
        $this->decrement('total_consumed', $amount);

        return $this->transactions()->create([
            'tenant_id' => $this->tenant_id,
            'user_id' => $this->user_id,
            'type' => 'refund',
            'amount' => $amount,
            'balance_after' => $this->balance,
            'description' => $description,
        ]);
    }
}
