<?php

declare(strict_types=1);

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Modules\Ai\Mcp\McpException;
use MultiTenantSaas\Modules\Ai\Mcp\McpToolRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MCP JSON-RPC 2.0 服务器控制器
 *
 * 处理 MCP 协议请求，支持标准 JSON-RPC 响应和 SSE 流式响应。
 */
class McpServerController extends Controller
{
    /** MCP 协议版本 */
    private const PROTOCOL_VERSION = '2024-11-05';

    /** 服务器能力声明 */
    private const SERVER_CAPABILITIES = [
        'tools' => ['listChanged' => false],
    ];

    public function __construct(
        protected McpToolRegistry $registry,
    ) {}

    /**
     * 处理 MCP JSON-RPC 请求
     *
     * 支持的方法：
     * - initialize: 返回服务器能力
     * - tools/list: 列出可用工具
     * - tools/call: 调用工具
     * - notifications/initialized: 客户端初始化完成通知
     */
    public function handle(Request $request): JsonResponse|StreamedResponse
    {
        try {
            // 解析 JSON-RPC 请求
            $body = $request->getContent();
            $payload = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->jsonRpcError(null, McpException::CODE_PARSE_ERROR, 'Parse error: ' . json_last_error_msg());
            }

            // 验证 JSON-RPC 2.0 结构
            if (!is_array($payload) || !isset($payload['method'])) {
                return $this->jsonRpcError($payload['id'] ?? null, McpException::CODE_INVALID_REQUEST, 'Invalid Request');
            }

            // 校验 jsonrpc 版本
            if (($payload['jsonrpc'] ?? '') !== '2.0') {
                return $this->jsonRpcError($payload['id'] ?? null, McpException::CODE_INVALID_REQUEST, 'Invalid Request: missing or invalid jsonrpc version');
            }

            $id = $payload['id'] ?? null;
            $method = $payload['method'];
            $params = $payload['params'] ?? [];

            // 类型校验：method 必须为 string，params 必须为 array
            if (!is_string($method)) {
                return $this->jsonRpcError($id, McpException::CODE_INVALID_REQUEST, 'Invalid Request: method must be a string');
            }
            if (!is_array($params)) {
                return $this->jsonRpcError($id, McpException::CODE_INVALID_REQUEST, 'Invalid Request: params must be an object or array');
            }

            // SSE 流式响应
            if (str_contains($request->header('Accept', ''), 'text/event-stream')) {
                return $this->handleSse($id, $method, $params);
            }

            // 标准 JSON-RPC 响应
            return $this->handleMethod($id, $method, $params);

        } catch (McpException $e) {
            return $this->jsonRpcError($id ?? null, $e->getErrorCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return $this->jsonRpcError($id ?? null, McpException::CODE_INTERNAL_ERROR, 'Internal error');
        }
    }

    /**
     * 处理具体方法调用
     */
    protected function handleMethod(mixed $id, string $method, array $params): JsonResponse
    {
        return match ($method) {
            'initialize' => $this->initialize($id, $params),
            'tools/list' => $this->listTools($id),
            'tools/call' => $this->callTool($id, $params),
            'notifications/initialized' => $this->notificationsInitialized(),
            default => $this->jsonRpcError($id, McpException::CODE_METHOD_NOT_FOUND, "Method not found: {$method}"),
        };
    }

    /**
     * initialize: 返回服务器能力和协议版本
     */
    protected function initialize(mixed $id, array $params): JsonResponse
    {
        return $this->jsonRpcResult($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => self::SERVER_CAPABILITIES,
            'serverInfo' => [
                'name' => 'multi-tenant-saas-mcp',
                'version' => '1.0.0',
            ],
        ]);
    }

    /**
     * tools/list: 列出所有可用工具
     */
    protected function listTools(mixed $id): JsonResponse
    {
        $tools = $this->registry->listTools();

        return $this->jsonRpcResult($id, [
            'tools' => $tools,
        ]);
    }

    /**
     * tools/call: 调用指定工具
     */
    protected function callTool(mixed $id, array $params): JsonResponse
    {
        if (empty($params['name'])) {
            return $this->jsonRpcError($id, McpException::CODE_INVALID_PARAMS, 'Missing required param: name');
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            return $this->jsonRpcError($id, McpException::CODE_INVALID_PARAMS, 'Invalid params: arguments must be an object or array');
        }

        $result = $this->registry->callTool($params['name'], $arguments);

        return $this->jsonRpcResult($id, [
            'content' => [
                ['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_THROW_ON_ERROR)],
            ],
        ]);
    }

    /**
     * notifications/initialized: 客户端初始化完成（无响应）
     *
     * Notification 无 id，返回空 result 的 JSON-RPC 响应以保持类型一致。
     */
    protected function notificationsInitialized(): JsonResponse
    {
        return $this->jsonRpcResult(null, new \stdClass());
    }

    /**
     * SSE 流式响应
     */
    protected function handleSse(mixed $id, string $method, array $params): StreamedResponse
    {
        return response()->stream(function () use ($id, $method, $params) {
            try {
                $response = $this->handleMethod($id, $method, $params);
                $data = $response->getData(true);
                echo "data: " . json_encode($data, JSON_THROW_ON_ERROR) . "\n\n";
                ob_flush();
                flush();
            } catch (\Throwable $e) {
                $error = ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => McpException::CODE_INTERNAL_ERROR, 'message' => 'Internal error']];
                echo "data: " . json_encode($error) . "\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * JSON-RPC 2.0 成功响应
     */
    protected function jsonRpcResult(mixed $id, mixed $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    /**
     * JSON-RPC 2.0 错误响应
     */
    protected function jsonRpcError(mixed $id, int $code, string $message, mixed $data = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ]);
    }
}
