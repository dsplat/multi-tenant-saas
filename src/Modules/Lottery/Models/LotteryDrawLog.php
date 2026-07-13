<?php

namespace MultiTenantSaas\Modules\Lottery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class LotteryDrawLog extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'log_id';

    protected $fillable = [
        'tenant_id', 'activity_id', 'prize_id', 'user_id',
        'user_ip', 'user_agent', 'result', 'draw_at',
    ];

    protected function casts(): array
    {
        return [
            'draw_at' => 'datetime',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LotteryActivity::class, 'activity_id', 'activity_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(LotteryActivityPrize::class, 'prize_id', 'prize_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function scopeWin($query)
    {
        return $query->where('result', 'win');
    }

    public function scopeMiss($query)
    {
        return $query->where('result', 'miss');
    }
}
