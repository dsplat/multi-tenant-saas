<?php

declare(strict_types=1);

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * MCP 工具模型
 *
 * 存储 MCP Tool 信息（名称、描述、输入参数 JSON Schema），
 * 每个 McpTool 归属于一个 McpClient，用于对外暴露 MCP 工具定义。
 *
 * 说明：本模型启用 BelongsToTenant 全局作用域实现租户隔离；
 * input_schema 以 JSON 格式存储，通过 casts 自动编解码为数组。
 */
class McpTool extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId;

    protected $primaryKey = 'mcp_tool_id';

    protected $keyType = 'int';

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
            'tenant_id' => 'integer',
            'input_schema' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(McpClient::class, 'client_id', 'mcp_client_id');
    }
}