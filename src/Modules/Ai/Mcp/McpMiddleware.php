<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\McpClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP 请求中间件
 *
 * 处理 MCP 协议的认证和请求路由：
 * 1. 从请求中提取 API Key（Header 或 Query）
 * 2. 验证 McpClient 存在且状态为 active
 * 3. 设置租户上下文
 * 4. 处理 JSON-RPC 2.0 错误响应
 */
class McpMiddleware
{
    /**
     * 处理 MCP 请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $apiKey = $this->extractApiKey($request);

            if (empty($apiKey)) {
                return $this->jsonRpcError(
                    'API key is required.',
                    McpException::CODE_INVALID_REQUEST,
                    null,
                    401,
                );
            }

            $client = $this->resolveClient($apiKey);

            if ($client === null) {
                return $this->jsonRpcError(
                    'Invalid API key.',
                    McpException::CODE_FORBIDDEN,
                    null,
                    403,
                );
            }

            if (!$client->isActive()) {
                return $this->jsonRpcError(
                    'MCP client is inactive.',
                    McpException::CODE_FORBIDDEN,
                    null,
                    403,
                );
            }

            if ($client->tenant_id === null) {
                return $this->jsonRpcError(
                    'MCP client has no tenant.',
                    McpException::CODE_INTERNAL_ERROR,
                    null,
                    500,
                );
            }

            TenantContext::setTenantId((string) $client->tenant_id);

            $request->attributes->set('mcp_client', $client);

            return $next($request);
        } catch (McpException $e) {
            return $this->jsonRpcError(
                $e->getMessage(),
                $e->getErrorCode(),
                $e->getErrorData(),
            );
        } catch (\Throwable $e) {
            Log::error('McpMiddleware: unhandled exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->jsonRpcError(
                'Internal server error.',
                McpException::CODE_INTERNAL_ERROR,
                null,
                500,
            );
        }
    }

    /**
     * 从请求中提取 API Key
     */
    protected function extractApiKey(Request $request): ?string
    {
        $apiKey = $request->header('X-MCP-API-Key');

        if ($apiKey === null) {
            $authHeader = $request->header('Authorization');

            if ($authHeader !== null) {
                if (!str_starts_with($authHeader, 'Bearer ')) {
                    return null;
                }

                $apiKey = substr($authHeader, 7);
            }
        }

        if ($apiKey === null) {
            $queryParam = $request->query('api_key');
            $apiKey = is_string($queryParam) ? $queryParam : null;
        }

        return $apiKey !== null ? trim($apiKey) : null;
    }

    /**
     * 根据 API Key 查找 McpClient
     *
     * api_key 使用 Crypt::encryptString 加密存储（随机 IV），
     * 无法通过 where 直接比较，需遍历解密后比对明文。
     */
    protected function resolveClient(string $apiKey): ?McpClient
    {
        return McpClient::query()
            ->get()
            ->first(fn(McpClient $client) => $client->api_key === $apiKey);
    }

    /**
     * 返回 JSON-RPC 2.0 错误响应
     */
    protected function jsonRpcError(
        string $message,
        int $code,
        mixed $data = null,
        int $httpStatus = 400,
    ): JsonResponse {
        $error = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => null,
        ];

        if ($data !== null) {
            $error['error']['data'] = $data;
        }

        return response()->json($error, $httpStatus);
    }
}
