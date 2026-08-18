<?php

namespace MultiTenantSaas\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

class UsageRecord extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'usage_record_id';

    protected $fillable = [
        'tenant_id',
        'metric_type',
        'value',
        'period',
        'recorded_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'recorded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
