<?php

namespace MultiTenantSaas\Models\Lottery;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class LotteryDrawLog extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $table = 'lottery_records';

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'lottery_id', 'prize_id', 'user_id', 'tenant_id',
        'is_winner', 'prize_name', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'is_winner' => 'boolean',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LotteryActivity::class, 'lottery_id', 'lottery_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(LotteryPrize::class, 'prize_id', 'prize_id');
    }

    public function scopeWinners($query)
    {
        return $query->where('is_winner', true);
    }
}
