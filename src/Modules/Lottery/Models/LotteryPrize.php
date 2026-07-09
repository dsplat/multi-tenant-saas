<?php

namespace MultiTenantSaas\Modules\Lottery\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 抽奖奖品模型
 *
 * 映射到已有的 lottery_prizes 表。
 * 注意：当前表结构缺少 tenant_id、activity_id 等字段，
 * 需后续迁移补充完整字段后方可用于多租户场景。
 */
class LotteryPrize extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $fillable = [
        'pool_id', 'name', 'type', 'quantity', 'probability',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'probability' => 'decimal:4',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(LotteryPool::class, 'pool_id', 'id');
    }
}
