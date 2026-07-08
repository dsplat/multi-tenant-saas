<?php

namespace MultiTenantSaas\Models\Lottery;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Models\Tenant;

class LotteryActivity extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'activity_id';

    protected $fillable = [
        'tenant_id', 'title', 'slug', 'description', 'status',
        'rules', 'start_at', 'end_at',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(LotteryActivityPrize::class, 'activity_id', 'activity_id');
    }

    public function drawLogs(): HasMany
    {
        return $this->hasMany(LotteryDrawLog::class, 'activity_id', 'activity_id');
    }

    public function blacklists(): HasMany
    {
        return $this->hasMany(LotteryBlacklist::class, 'activity_id', 'activity_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }
}
