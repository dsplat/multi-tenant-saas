<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Services\SandboxService;

Route::prefix('admin/developer-portal')->group(function () {
    Route::get('/sandbox', function () {
        $service = app(SandboxService::class);

        return response()->json(['success' => true, 'data' => $service->listSandboxes()]);
    });
    Route::post('/sandbox', function () {
        $service = app(SandboxService::class);

        return response()->json(['success' => true, 'data' => $service->createSandbox()]);
    });
});
