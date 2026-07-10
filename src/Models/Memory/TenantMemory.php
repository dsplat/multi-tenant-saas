<?php

namespace MultiTenantSaas\Models\Memory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class TenantMemory extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'memory_id';

    protected $fillable = ['tenant_id', 'type', 'key', 'value', 'weight', 'last_accessed_at'];

    protected function casts(): array
    {
        return ['value' => 'array', 'weight' => 'float', 'last_accessed_at' => 'datetime'];
    }
}
