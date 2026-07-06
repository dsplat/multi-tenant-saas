<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\McpToolRegistryContract;
use MultiTenantSaas\Exceptions\McpException;
use MultiTenantSaas\Models\McpToolAccessLog;
use MultiTenantSaas\Services\Mcp\McpSkillGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MCP Server Controller
 *
 * 实现 JSON-RPC 2.0 协议处理器，支持 SSE 流式响应。
 *
 * 核心方法:
 * - initialize: 协议握手，返回服务器能力
 * - tools/list: 返回可用工具列表
 * - tools/call: 调用指定工具
 * - resources/list: 返回资源列表
 * - resources/read: 读取资源
 * - notifications/initialized: 客户端初始化完成通知
 * - ping: 心跳检测
 * - skill: 生成客户端 Skill 文件
 */
class McpServerController extends Controller
{
    public function __construct(
        private McpToolRegistryContract $toolRegistry,
        private McpSkillGenerator $skillGenerator
    ) {}

    /**
     * JSON-RPC 2.0 主入口
     */
    public function handle(Request $request): JsonResponse|StreamedResponse
    {
        $body = $request->json()->all();

        if (!$body || !isset($body['jsonrpc']) || $body['jsonrpc'] !== '2.0') {
            return $this->errorResponse(null, McpException::INVALID_REQUEST, 'Invalid JSON-RPC 2.0 request');
        }

        if (isset($body['method']) && $body['method'] === 'tools/call') {
            return $this->handleToolCall($body);
        }

        $method = $body['method'] ?? null;
        $id = $body['id'] ?? null;
        $params = $body['params'] ?? [];

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'tools/list' => $this->toolsList(),
                'resources/list' => $this->resourcesList(),
                'resources/read' => $this->resourcesRead($params),
                'notifications/initialized' => $this->notificationsInitialized(),
                'ping' => $this->ping(),
                'skill' => $this->generateSkill($params),
                default => throw new McpException(
                    "Method not found: {$method}",
                    McpException::METHOD_NOT_FOUND
                ),
            };

            return $this->successResponse($id, $result);
        } catch (McpException $e) {
            return $this->errorResponse($id, $e->getCode(), $e->getMessage(), $e->getData());
        } catch (\Throwable $e) {
            return $this->errorResponse($id, McpException::INTERNAL_ERROR, $e->getMessage());
        }
    }

    /**
     * 初始化握手
     */
    private function initialize(array $params): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => true],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'logging' => [],
            ],
            'serverInfo' => [
                'name' => 'multi-tenant-saas-mcp',
                'version' => '1.0.0',
            ],
        ];
    }

    /**
     * 工具列表
     */
    private function toolsList(): array
    {
        return [
            'tools' => $this->toolRegistry->listTools(),
        ];
    }

    /**
     * 工具调用（支持 SSE 流式响应）
     */
    private function handleToolCall(array $body): JsonResponse|StreamedResponse
    {
        $id = $body['id'] ?? null;
        $params = $body['params'] ?? [];
        $toolName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        $stream = $params['stream'] ?? false;

        if (!$toolName) {
            return $this->errorResponse($id, McpException::INVALID_PARAMS, 'Missing tool name');
        }

        $tenantId = TenantContext::getId() ? (int) TenantContext::getId() : null;

        $this->logToolAccess($toolName, $tenantId);

        if ($stream) {
            return $this->streamToolCall($id, $toolName, $arguments, $tenantId);
        }

        try {
            $result = $this->toolRegistry->callTool($toolName, $arguments, $tenantId);

            return $this->successResponse($id, $result);
        } catch (McpException $e) {
            return $this->errorResponse($id, $e->getCode(), $e->getMessage(), $e->getData());
        } catch (\Throwable $e) {
            return $this->errorResponse($id, McpException::TOOL_EXECUTION_FAILED, $e->getMessage());
        }
    }

    /**
     * SSE 流式工具调用
     */
    private function streamToolCall(?string $id, string $toolName, array $arguments, ?int $tenantId): StreamedResponse
    {
        return response()->stream(function () use ($id, $toolName, $arguments, $tenantId) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            $this->sendSseEvent('start', ['id' => $id, 'tool' => $toolName]);

            try {
                $result = $this->toolRegistry->callTool($toolName, $arguments, $tenantId);

                $this->sendSseEvent('data', $result);
                $this->sendSseEvent('done', ['id' => $id]);
            } catch (\Throwable $e) {
                $this->sendSseEvent('error', [
                    'id' => $id,
                    'code' => $e instanceof McpException ? $e->getCode() : McpException::TOOL_EXECUTION_FAILED,
                    'message' => $e->getMessage(),
                ]);
            }

            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        ob_flush();
        flush();
    }

    /**
     * 资源列表
     */
    private function resourcesList(): array
    {
        return [
            'resources' => [
                [
                    'uri' => 'mcp://tools',
                    'name' => 'Available Tools',
                    'description' => 'List of all registered MCP tools',
                    'mimeType' => 'application/json',
                ],
            ],
        ];
    }

    /**
     * 资源读取
     */
    private function resourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? '';

        return match ($uri) {
            'mcp://tools' => [
                'contents' => [
                    [
                        'uri' => $uri,
                        'mimeType' => 'application/json',
                        'text' => json_encode($this->toolRegistry->listTools(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    ],
                ],
            ],
            default => [
                'contents' => [],
            ],
        };
    }

    /**
     * 客户端初始化完成通知
     */
    private function notificationsInitialized(): array
    {
        return ['status' => 'ok'];
    }

    /**
     * 心跳检测
     */
    private function ping(): array
    {
        return ['pong' => true, 'timestamp' => now()->toIso8601String()];
    }

    /**
     * 生成 Skill 文件
     */
    private function generateSkill(array $params): array
    {
        $clientSlug = $params['client'] ?? 'workbuddy';
        $format = $params['format'] ?? null;

        $result = $this->skillGenerator->generate($clientSlug, $format);

        return [
            'client' => $clientSlug,
            'format' => $format ?? $this->skillGenerator->getClientRegistry()->getOutputFormat($clientSlug),
            'content' => $result,
        ];
    }

    /**
     * 记录工具访问日志
     */
    private function logToolAccess(string $toolName, ?int $tenantId): void
    {
        try {
            $clientId = request()->attributes->get('mcp_client_id');
            $tokenId = request()->attributes->get('mcp_token_id');

            McpToolAccessLog::create([
                'mcp_client_id' => $clientId,
                'mcp_client_token_id' => $tokenId,
                'tenant_id' => $tenantId,
                'tool_name' => $toolName,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (\Throwable $e) {
            // 日志记录失败不影响主流程
        }
    }

    private function successResponse(?string $id, array $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ]);
    }

    private function errorResponse(?string $id, int $code, string $message, ?array $data = null): JsonResponse
    {
        $error = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => $id,
        ];

        if ($data !== null) {
            $error['error']['data'] = $data;
        }

        return response()->json($error);
    }
}