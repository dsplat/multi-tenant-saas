<?php

namespace MultiTenantSaas\Models\Lottery;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class LotteryPrize extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'prize_id';

    protected $fillable = [
        'lottery_id', 'name', 'image', 'prize_type', 'probability',
        'stock', 'sort_order', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'probability' => 'integer',
            'stock' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(LotteryActivity::class, 'lottery_id', 'lottery_id');
    }

    public function hasStock(): bool
    {
        return $this->stock > 0;
    }

    public function decrementStock(): bool
    {
        if (!$this->hasStock()) {
            return false;
        }

        return $this->decrement('stock') > 0;
    }
}
