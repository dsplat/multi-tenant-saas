<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class LotteryPrize extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'prize_id';

    protected $fillable = [
        'lottery_id',
        'name',
        'image',
        'prize_type',
        'probability',
        'stock',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'stock' => 'integer',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function lottery(): BelongsTo
    {
        return $this->belongsTo(Lottery::class, 'lottery_id', 'lottery_id');
    }
}