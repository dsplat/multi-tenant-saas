<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

class FormField extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'field_id';

    protected $fillable = [
        'form_id',
        'field_key',
        'field_type',
        'label',
        'placeholder',
        'default_value',
        'options',
        'is_required',
        'sort_order',
        'validation_rules',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'validation_rules' => 'array',
            'metadata' => 'array',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id', 'form_id');
    }
}
