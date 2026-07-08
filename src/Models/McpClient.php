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
        'tenant_id',
        'name',
        'base_url',
        'api_key',
        'status',
    ];

    public function tools(): HasMany
    {
        return $this->hasMany(McpTool::class, 'client_id', 'mcp_client_id');
    }
}