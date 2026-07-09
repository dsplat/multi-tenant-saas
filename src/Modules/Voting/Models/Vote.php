<?php

namespace MultiTenantSaas\Modules\Voting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class Vote extends Model
{
    use HasFactory, HasGlobalId, BelongsToTenant;

    protected $primaryKey = 'vote_id';

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'vote_type',
        'status',
        'start_at',
        'end_at',
        'daily_limit',
        'total_limit',
        'daily_limit_per_user',
        'total_limit_per_user',
        'anti_cheat_ip',
        'show_result',
        'show_rank',
        'total_votes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'daily_limit' => 'integer',
            'total_limit' => 'integer',
            'daily_limit_per_user' => 'integer',
            'total_limit_per_user' => 'integer',
            'anti_cheat_ip' => 'boolean',
            'show_result' => 'boolean',
            'show_rank' => 'boolean',
            'total_votes' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(VoteOption::class, 'vote_id', 'vote_id')->orderBy('sort_order');
    }

    public function records(): HasMany
    {
        return $this->hasMany(VoteRecord::class, 'vote_id', 'vote_id');
    }
}