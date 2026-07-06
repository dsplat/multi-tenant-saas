<?php

namespace MultiTenantSaas\Models\Lottery;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class LotteryActivity extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $table = 'lotteries';

    protected $primaryKey = 'lottery_id';

    protected $fillable = [
        'tenant_id', 'title', 'description', 'status', 'start_at', 'end_at',
        'daily_limit', 'total_limit', 'daily_limit_per_user', 'total_limit_per_user',
        'anti_cheat_ip', 'no_prize_probability', 'prize_show_count',
        'total_draws', 'total_wins', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'daily_limit' => 'integer',
            'total_limit' => 'integer',
            'daily_limit_per_user' => 'integer',
            'total_limit_per_user' => 'integer',
            'anti_cheat_ip' => 'boolean',
            'no_prize_probability' => 'integer',
            'prize_show_count' => 'integer',
            'total_draws' => 'integer',
            'total_wins' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(LotteryPrize::class, 'lottery_id', 'lottery_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(LotteryDrawLog::class, 'lottery_id', 'lottery_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
