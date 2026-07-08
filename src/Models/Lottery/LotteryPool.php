<?php

namespace MultiTenantSaas\Models\Lottery;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 抽奖池模型（遗留）
 *
 * 映射到已有的 lottery_pools 表。
 * 注意：此为旧版表结构，缺少 tenant_id 字段。
 * 新功能请使用 LotteryActivity + LotteryActivityPrize。
 */
class LotteryPool extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $fillable = [
        'prize_config', 'probability_rules', 'anti_abuse_config',
    ];

    protected function casts(): array
    {
        return [
            'prize_config' => 'array',
            'probability_rules' => 'array',
            'anti_abuse_config' => 'array',
        ];
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(LotteryPrize::class, 'pool_id', 'id');
    }
}
