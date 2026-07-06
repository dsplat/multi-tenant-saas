<?php

namespace MultiTenantSaas\Models\Voting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class VotingTopic extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'topic_id';

    protected $fillable = [
        'tenant_id', 'title', 'slug', 'description', 'status',
        'rules', 'start_at', 'end_at', 'total_votes',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'total_votes' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(VotingOption::class, 'topic_id', 'topic_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(VotingRecord::class, 'topic_id', 'topic_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
