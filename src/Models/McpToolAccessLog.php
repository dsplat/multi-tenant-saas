<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

class McpToolAccessLog extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    public $timestamps = false;

    protected $primaryKey = 'mcp_tool_access_log_id';

    protected $fillable = [
        'tenant_id', 'mcp_client_id', 'tool_slug', 'request_id',
        'input_summary', 'status', 'duration_ms', 'error_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'input_summary' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'mcp_client_id', 'mcp_client_id');
    }
}
