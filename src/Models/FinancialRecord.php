<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class FinancialRecord extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'financial_record_id';

    protected $fillable = [
        'tenant_id',
        'type',
        'amount',
        'status',
        'payment_method',
        'payment_order_no',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function isSubscription(): bool
    {
        return $this->type === 'subscription';
    }

    public function isRecharge(): bool
    {
        return $this->type === 'recharge';
    }

    public function isCommission(): bool
    {
        return $this->type === 'commission';
    }

    public function isRefund(): bool
    {
        return $this->type === 'refund';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    public function markAsPaid(string $paymentOrderNo, ?string $paymentMethod = null): void
    {
        $this->update([
            'status' => 'completed',
            'payment_order_no' => $paymentOrderNo,
            'payment_method' => $paymentMethod,
            'paid_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    public function markAsRefunded(): void
    {
        $this->update(['status' => 'refunded']);
    }
}
