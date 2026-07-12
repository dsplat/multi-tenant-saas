<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Services\SandboxService;

Route::prefix('tenant/developer')->group(function () {
    Route::get('/docs', function () {
        return response()->json(['success' => true, 'data' => ['api_docs_url' => '/api/documentation']]);
    });
    Route::get('/sandbox', function () {
        $service = app(SandboxService::class);

        return response()->json(['success' => true, 'data' => $service->getTenantSandbox()]);
    });
});
