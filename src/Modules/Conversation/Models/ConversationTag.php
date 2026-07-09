<?php

namespace MultiTenantSaas\Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class ConversationTag extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'conversation_tag_id';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'tag', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'conversation_id');
    }
}
