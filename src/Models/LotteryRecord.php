<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class LotteryRecord extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'lottery_id',
        'prize_id',
        'user_id',
        'tenant_id',
        'is_winner',
        'prize_name',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'is_winner' => 'boolean',
        ];
    }

    public function lottery(): BelongsTo
    {
        return $this->belongsTo(Lottery::class, 'lottery_id', 'lottery_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(LotteryPrize::class, 'prize_id', 'prize_id');
    }
}