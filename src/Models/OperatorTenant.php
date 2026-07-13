<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;

class OperatorTenant extends Model
{
    use BelongsToTenant;

    protected $table = 'operator_tenants';

    protected $fillable = [
        'operator_id',
        'tenant_id',
        'user_id',
        'role',
        'role_id',
        'is_active',
        'invited_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'operator_id', 'operator_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
