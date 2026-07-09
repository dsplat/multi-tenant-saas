<?php

namespace MultiTenantSaas\Modules\Form\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class FormSubmission extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'submission_id';

    protected $fillable = [
        'form_id', 'tenant_id', 'user_id', 'data', 'ip_address',
        'user_agent', 'status', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class, 'form_id', 'form_id');
    }

    public function detailData(): HasMany
    {
        return $this->hasMany(FormSubmissionData::class, 'submission_id', 'submission_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
