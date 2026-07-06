<?php

declare(strict_types=1);

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\McpToolRegistryContract;
use MultiTenantSaas\Mcp\Exceptions\McpException;
use MultiTenantSaas\Models\McpToolAccessLog;
use MultiTenantSaas\Services\Mcp\McpClientRegistry;
use MultiTenantSaas\Services\Mcp\McpSkillGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class McpServerController extends Controller
{
    public function __construct(
        protected McpToolRegistryContract $toolRegistry,
        protected McpSkillGenerator $skillGenerator,
        protected McpClientRegistry $clientRegistry,
    ) {}

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
                'notifications/initialized' => $this->handleNotificationsInitialized(),
                'ping' => $this->handlePing(),
                'skill' => $this->generateSkill($params),
                default => throw McpException::methodNotFound($method),
            };

            return $this->successResponse($id, $result);
        } catch (McpException $e) {
            return $this->errorResponse($id, $e->getErrorCode(), $e->getMessage(), $e->getErrorData());
        } catch (\Throwable $e) {
            return $this->errorResponse($id, McpException::INTERNAL_ERROR, $e->getMessage());
        }
    }

    public function skill(Request $request, string $clientSlug): JsonResponse
    {
        $format = $request->query('format');

        $result = $this->skillGenerator->generate($clientSlug, $format);

        return response()->json([
            'client' => $clientSlug,
            'format' => $format ?? $this->skillGenerator->getClientRegistry()->getOutputFormat($clientSlug),
            'content' => $result,
        ]);
    }

    public function config(Request $request, string $clientSlug): JsonResponse
    {
        $client = $this->clientRegistry->getClient($clientSlug);

        if (!$client) {
            return response()->json(['error' => 'Client not found'], 404);
        }

        $config = $this->skillGenerator->generateJsonConfig($clientSlug);

        return response()->json($config);
    }

    public function clients(): JsonResponse
    {
        $clients = $this->clientRegistry->getClients();

        $defaultClients = $this->clientRegistry->getDefaultClients();

        return response()->json([
            'registered' => $clients->toArray(),
            'available' => $defaultClients,
        ]);
    }

    protected function initialize(array $params): array
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

    protected function toolsList(): array
    {
        return [
            'tools' => $this->toolRegistry->listTools(),
        ];
    }

    protected function handleToolCall(array $body): JsonResponse|StreamedResponse
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
            return $this->errorResponse($id, $e->getErrorCode(), $e->getMessage(), $e->getErrorData());
        } catch (\Throwable $e) {
            return $this->errorResponse($id, McpException::TOOL_EXECUTION_FAILED, $e->getMessage());
        }
    }

    protected function streamToolCall(?string $id, string $toolName, array $arguments, ?int $tenantId): StreamedResponse
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
                    'code' => $e instanceof McpException ? $e->getErrorCode() : McpException::TOOL_EXECUTION_FAILED,
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

    protected function sendSseEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        ob_flush();
        flush();
    }

    protected function resourcesList(): array
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

    protected function resourcesRead(array $params): array
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

    protected function handleNotificationsInitialized(): array
    {
        return ['status' => 'ok'];
    }

    protected function handlePing(): array
    {
        return ['pong' => true, 'timestamp' => now()->toIso8601String()];
    }

    protected function generateSkill(array $params): array
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

    protected function logToolAccess(string $toolName, ?int $tenantId): void
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

    protected function successResponse(?string $id, array $result): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ]);
    }

    protected function errorResponse(?string $id, int $code, string $message, mixed $data = null): JsonResponse
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