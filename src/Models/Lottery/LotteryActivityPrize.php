<?php

namespace MultiTenantSaas\Models\Lottery;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Models\Tenant;

class LotteryActivityPrize extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'prize_id';

    protected $fillable = [
        'tenant_id', 'activity_id', 'name', 'image_url', 'type',
        'value', 'total_count', 'remaining_count', 'version',
        'probability', 'weight', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'total_count' => 'integer',
            'remaining_count' => 'integer',
            'version' => 'integer',
            'probability' => 'decimal:6',
            'weight' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LotteryActivity::class, 'activity_id', 'activity_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function drawLogs(): HasMany
    {
        return $this->hasMany(LotteryDrawLog::class, 'prize_id', 'prize_id');
    }

    public function isAvailable(): bool
    {
        return $this->remaining_count > 0;
    }
}
