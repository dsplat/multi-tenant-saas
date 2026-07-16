<?php

namespace MultiTenantSaas\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\Auth\Models\User;

class TicketComment extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'comment_id';

    protected $fillable = ['content'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
