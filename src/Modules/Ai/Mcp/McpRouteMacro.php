<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Mcp;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Http\Controllers\McpServerController;

/**
 * Route::mcp() 宏注册
 *
 * 一行代码注册 MCP 端点。
 *
 * 用法:
 * Route::mcp('scrm', ScrmMcpToolRegistry::class)
 *   → POST /api/v1/scrm (JSON-RPC 2.0 入口)
 *   → GET /api/v1/scrm/sse (SSE 流式入口)
 *   → POST /api/v1/scrm/sse (SSE 流式入口)
 *   → GET /api/v1/scrm/{client}/skill (Skill 文件)
 *   → GET /api/v1/scrm/{client}/config (JSON 配置)
 *   → GET /api/v1/scrm/clients (客户端列表)
 */
class McpRouteMacro
{
    public static function register(): void
    {
        Route::macro('mcp', function (string $prefix = 'mcp') {
            $mcp = Route::prefix("v1/{$prefix}")
                ->middleware(['mcp.auth', 'throttle:mcp'])
                ->group(function () {
                    Route::post('/', [McpServerController::class, 'handle']);
                    Route::get('/sse', [McpServerController::class, 'handle']);
                    Route::post('/sse', [McpServerController::class, 'handle']);
                    Route::get('/clients', [McpServerController::class, 'clients']);
                    Route::get('/{client}/skill', [McpServerController::class, 'skill']);
                    Route::get('/{client}/config', [McpServerController::class, 'config']);
                });

            return $mcp;
        });
    }
}
