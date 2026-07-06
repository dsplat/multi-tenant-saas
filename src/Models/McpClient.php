<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * MCP 客户端模型
 *
 * 管理接入 MCP 服务的 AI 客户端（WorkBuddy/Hermers/OpenClaw）。
 */
class McpClient extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'mcp_client_id';

    protected $fillable = [
        'slug',
        'name',
        'output_format',
        'description',
        'is_enabled',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(McpClientToken::class, 'mcp_client_id', 'mcp_client_id');
    }

    public function activeTokens(): HasMany
    {
        return $this->tokens()->where('is_active', true);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}