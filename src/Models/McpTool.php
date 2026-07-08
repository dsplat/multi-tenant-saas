<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class McpTool extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'mcp_tool_id';

    protected $fillable = [
        'client_id',
        'tenant_id',
        'name',
        'description',
        'input_schema',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'client_id', 'mcp_client_id');
    }
}
