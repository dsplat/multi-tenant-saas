<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class CouponShare extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'share_id';

    protected $fillable = [
        'tenant_id',
        'sharer_id',
        'receiver_id',
        'coupon_template_id',
        'share_code',
        'status',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function sharer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sharer_id', 'user_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id', 'user_id');
    }

    public function couponTemplate(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_template_id', 'coupon_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isAccepted(): bool
    {
        return $this->status === 'accepted';
    }
}
