<?php

namespace MultiTenantSaas\Modules\Form\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class Form extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'form_id';

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'status',
        'submit_limit',
        'start_at',
        'end_at',
        'submit_text',
        'success_message',
        'is_public',
        'require_login',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'submit_limit' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_public' => 'boolean',
            'require_login' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class, 'form_id', 'form_id')->orderBy('sort_order');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'form_id', 'form_id');
    }
}
