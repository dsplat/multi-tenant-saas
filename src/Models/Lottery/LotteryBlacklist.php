<?php

namespace MultiTenantSaas\Models\Lottery;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class LotteryBlacklist extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'blacklist_id';

    protected $fillable = [
        'tenant_id', 'lottery_id', 'identifier_type', 'identifier', 'reason',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LotteryActivity::class, 'lottery_id', 'lottery_id');
    }

    public function scopeForIdentifier($query, string $type, string $identifier)
    {
        return $query->where('identifier_type', $type)
            ->where('identifier', $identifier);
    }
}
