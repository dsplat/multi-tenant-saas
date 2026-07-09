<?php

namespace MultiTenantSaas\Modules\Conversation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class Attachment extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'attachment_id';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'message_id',
        'file_name', 'file_url', 'file_type', 'file_size', 'mime_type',
    ];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id', 'message_id');
    }
}
