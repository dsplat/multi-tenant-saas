<?php

namespace MultiTenantSaas\Modules\Voting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class VoteOption extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'vote_option_id';

    protected $appends = ['percentage'];

    protected $fillable = [
        'vote_id',
        'title',
        'image',
        'description',
        'vote_count',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'vote_count' => 'integer',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function vote(): BelongsTo
    {
        return $this->belongsTo(Vote::class, 'vote_id', 'vote_id');
    }

    public function getPercentageAttribute(): float
    {
        $total = $this->vote?->total_votes ?? 0;

        return $total > 0 ? round($this->vote_count / $total * 100, 2) : 0;
    }
}