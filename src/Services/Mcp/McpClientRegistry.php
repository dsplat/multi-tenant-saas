<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Mcp;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use MultiTenantSaas\Models\McpClient;
use MultiTenantSaas\Models\McpClientToken;

/**
 * MCP 客户端注册表
 *
 * 管理多种 AI 客户端（WorkBuddy/Hermers/OpenClaw）的注册、认证与配置。
 * 每个客户端对应一种输出格式：
 * - WorkBuddy: Markdown Skill 格式
 * - Hermers: JSON 配置格式
 * - OpenClaw: JSON 配置格式
 */
class McpClientRegistry
{
    private array $defaultClients = [
        'workbuddy' => [
            'name' => 'WorkBuddy',
            'output_format' => 'markdown_skill',
            'description' => 'WorkBuddy AI 客户端',
        ],
        'hermers' => [
            'name' => 'Hermers',
            'output_format' => 'json_config',
            'description' => 'Hermers AI 客户端',
        ],
        'openclaw' => [
            'name' => 'OpenClaw',
            'output_format' => 'json_config',
            'description' => 'OpenClaw AI 客户端',
        ],
    ];

    /**
     * 获取所有预设客户端类型
     */
    public function getDefaultClients(): array
    {
        return $this->defaultClients;
    }

    /**
     * 注册客户端
     */
    public function registerClient(string $slug, string $name, string $outputFormat, string $description = ''): McpClient
    {
        return McpClient::create([
            'slug' => $slug,
            'name' => $name,
            'output_format' => $outputFormat,
            'description' => $description,
            'is_enabled' => true,
        ]);
    }

    /**
     * 获取已注册的客户端列表
     */
    public function getClients(): Collection
    {
        return McpClient::where('is_enabled', true)->get();
    }

    /**
     * 获取客户端
     */
    public function getClient(string $slug): ?McpClient
    {
        return McpClient::where('slug', $slug)->where('is_enabled', true)->first();
    }

    /**
     * 为客户端生成 API Token
     */
    public function generateToken(string $clientSlug, int $tenantId, ?string $expiresAt = null, array $abilities = ['*']): McpClientToken
    {
        $client = $this->getClient($clientSlug);

        if (!$client) {
            throw new \RuntimeException("Client {$clientSlug} not found or disabled");
        }

        $tokenValue = Str::random(64);

        return McpClientToken::create([
            'mcp_client_id' => $client->getKey(),
            'tenant_id' => $tenantId,
            'token' => hash('sha256', $tokenValue),
            'token_plain' => $tokenValue,
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);
    }

    /**
     * 验证 Token
     */
    public function validateToken(string $tokenValue): ?McpClientToken
    {
        $hashed = hash('sha256', $tokenValue);

        $token = McpClientToken::where('token', $hashed)
            ->where('is_active', true)
            ->first();

        if (!$token) {
            return null;
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return null;
        }

        $token->increment('last_used_count');
        $token->touch('last_used_at');

        return $token;
    }

    /**
     * 吊销 Token
     */
    public function revokeToken(int $tokenId): bool
    {
        return McpClientToken::where('id', $tokenId)->update(['is_active' => false]) > 0;
    }

    /**
     * 获取客户端输出格式
     */
    public function getOutputFormat(string $slug): string
    {
        $client = $this->getClient($slug);

        return $client ? $client->output_format : 'json_config';
    }
}