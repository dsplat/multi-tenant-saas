<?php

namespace MultiTenantSaas\Models\Form;

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
        'tenant_id', 'title', 'slug', 'description', 'status',
        'settings', 'submit_count', 'submit_limit', 'submit_text',
        'success_message', 'is_public', 'require_login', 'metadata',
        'start_at', 'end_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'metadata' => 'array',
            'submit_count' => 'integer',
            'submit_limit' => 'integer',
            'is_public' => 'boolean',
            'require_login' => 'boolean',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class, 'form_id', 'form_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'form_id', 'form_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('end_at')->orWhere('end_at', '>=', now());
            });
    }
}
