<?php

declare(strict_types=1);

namespace MultiTenantSaas\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\McpException;
use MultiTenantSaas\Services\Mcp\McpClientRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP 认证中间件
 *
 * 通过 Sanctum Token 或 MCP Client Token 认证请求，
 * 并完成租户上下文识别。
 */
class McpMiddleware
{
    public function __construct(
        private McpClientRegistry $clientRegistry
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');
        $tenantId = $request->header('X-Tenant-ID') ? (int) $request->header('X-Tenant-ID') : null;

        if (! $authHeader) {
            throw new McpException(
                'Authorization required',
                McpException::AUTH_REQUIRED
            );
        }

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);

            if (str_starts_with($token, 'mcp_')) {
                return $this->handleMcpToken($request, $next, $token, $tenantId);
            }

            return $this->handleSanctumToken($request, $next, $token, $tenantId);
        }

        throw new McpException(
            'Invalid authorization header format',
            McpException::AUTH_REQUIRED
        );
    }

    private function handleMcpToken(Request $request, Closure $next, string $token, ?int $tenantId): Response
    {
        $validated = $this->clientRegistry->validateToken($token);

        if (! $validated) {
            throw new McpException(
                'Invalid or expired MCP token',
                McpException::TOKEN_EXPIRED
            );
        }

        if ($validated->tenant_id) {
            TenantContext::setTenantId((string) $validated->tenant_id);
        } elseif ($tenantId) {
            TenantContext::setTenantId((string) $tenantId);
        }

        $request->attributes->set('mcp_client_id', $validated->mcp_client_id);
        $request->attributes->set('mcp_token_id', $validated->getKey());
        $request->attributes->set('mcp_abilities', $validated->abilities ?? ['*']);

        return $next($request);
    }

    private function handleSanctumToken(Request $request, Closure $next, string $token, ?int $tenantId): Response
    {
        if ($tenantId) {
            TenantContext::setTenantId((string) $tenantId);
        }

        $user = null;
        try {
            $sanctumGuard = auth('sanctum');
            $user = $sanctumGuard->authenticate();
        } catch (\Throwable $e) {
            throw new McpException(
                'Invalid Sanctum token',
                McpException::TOKEN_EXPIRED
            );
        }

        if (! $user) {
            throw new McpException(
                'Invalid Sanctum token',
                McpException::TOKEN_EXPIRED
            );
        }

        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }
}
