<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * MCP 客户端 Token 模型
 *
 * 管理 AI 客户端与服务端的认证 Token。
 */
class McpClientToken extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'mcp_client_token_id';

    protected $fillable = [
        'mcp_client_id',
        'tenant_id',
        'token',
        'token_plain',
        'abilities',
        'expires_at',
        'is_active',
        'last_used_at',
        'last_used_count',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
            'last_used_count' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'mcp_client_id', 'mcp_client_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(McpToolAccessLog::class, 'mcp_client_token_id', 'mcp_client_token_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}