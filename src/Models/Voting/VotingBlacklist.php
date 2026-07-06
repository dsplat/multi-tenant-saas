<?php

namespace MultiTenantSaas\Models\Voting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class VotingBlacklist extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    public $timestamps = false;

    protected $primaryKey = 'blacklist_id';

    protected $fillable = [
        'tenant_id', 'topic_id', 'identifier_type', 'identifier', 'reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(VotingTopic::class, 'topic_id', 'topic_id');
    }

    public function scopeForIdentifier($query, string $type, string $identifier)
    {
        return $query->where('identifier_type', $type)
            ->where('identifier', $identifier);
    }
}
