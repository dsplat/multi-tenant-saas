<?php

namespace MultiTenantSaas\Models\Voting;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class VotingRecord extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    public $timestamps = false;

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'tenant_id', 'topic_id', 'option_id', 'user_id',
        'user_ip', 'user_agent', 'voted_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'voted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(VotingTopic::class, 'topic_id', 'topic_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(VotingOption::class, 'option_id', 'option_id');
    }
}
