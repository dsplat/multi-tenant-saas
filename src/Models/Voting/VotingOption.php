<?php

namespace MultiTenantSaas\Models\Voting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class VotingOption extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'option_id';

    protected $fillable = [
        'tenant_id', 'topic_id', 'title', 'image_url',
        'description', 'vote_count', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'vote_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(VotingTopic::class, 'topic_id', 'topic_id');
    }

    public function incrementVote(): bool
    {
        return $this->increment('vote_count') > 0;
    }
}
