<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * MCP 工具访问日志模型
 *
 * 记录每次工具调用的审计信息。
 */
class McpToolAccessLog extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'log_id';

    protected $fillable = [
        'mcp_client_id',
        'mcp_client_token_id',
        'tenant_id',
        'tool_name',
        'arguments',
        'result',
        'status',
        'duration_ms',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'mcp_client_id', 'mcp_client_id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(McpClientToken::class, 'mcp_client_token_id', 'mcp_client_token_id');
    }

    public function scopeByTool($query, string $toolName)
    {
        return $query->where('tool_name', $toolName);
    }

    public function scopeByTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
