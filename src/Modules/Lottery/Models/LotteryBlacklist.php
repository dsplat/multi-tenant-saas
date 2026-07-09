<?php

namespace MultiTenantSaas\Modules\Lottery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Models\Tenant;

class LotteryBlacklist extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'blacklist_id';

    protected $fillable = [
        'tenant_id', 'activity_id', 'identifier_type', 'identifier', 'reason',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LotteryActivity::class, 'activity_id', 'activity_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }
}
