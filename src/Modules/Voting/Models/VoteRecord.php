<?php

namespace MultiTenantSaas\Modules\Voting\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class VoteRecord extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'vote_record_id';

    protected $fillable = [
        'vote_id',
        'vote_option_id',
        'user_id',
        'tenant_id',
        'ip_address',
        'user_agent',
        'fingerprint',
    ];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(Vote::class, 'vote_id', 'vote_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(VoteOption::class, 'vote_option_id', 'vote_option_id');
    }
}