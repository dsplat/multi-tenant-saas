<?php

namespace MultiTenantSaas\Modules\Sms\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Models\Tenant;

class SmsUnsubscribe extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'unsubscribe_id';

    protected $fillable = [
        'tenant_id', 'phone', 'user_id', 'reason', 'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }
}
