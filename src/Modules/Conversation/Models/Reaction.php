<?php

namespace MultiTenantSaas\Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class Reaction extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'reaction_id';

    protected $fillable = [
        'tenant_id', 'message_id', 'user_id', 'emoji',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id', 'message_id');
    }
}
