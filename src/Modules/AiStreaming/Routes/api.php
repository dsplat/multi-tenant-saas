<?php

/**
 * AiStreaming 契约 API 路由
 *
 * 由 ModuleServiceProvider 自动加载：
 *   前缀 api/v1 + 中间件 auth:sanctum / throttle:api / tenant.identify / VerifyOperatorTenant
 *
 * 调用方为 Node SSE 引擎（透传浏览器的 Authorization Bearer token），
 * 因此鉴权语义与浏览器直连 PHP API 完全一致。
 */

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\AiStreaming\Http\Controllers\ResolveController;
use MultiTenantSaas\Modules\AiStreaming\Http\Controllers\ToolExecuteController;
use MultiTenantSaas\Modules\AiStreaming\Http\Controllers\UsageReportController;

Route::prefix('ai-streaming')->group(function () {
    Route::post('/resolve', ResolveController::class);
    Route::post('/tools/execute', ToolExecuteController::class);
    Route::post('/usage/report', UsageReportController::class);
});
