<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class McpClient extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'mcp_client_id';

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'type', 'config',
        'status', 'rate_limit',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'rate_limit' => 'integer',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(McpClientToken::class, 'mcp_client_id', 'mcp_client_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(McpToolAccessLog::class, 'mcp_client_id', 'mcp_client_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
