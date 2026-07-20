<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\DeveloperPortal\Services\SandboxService;

Route::prefix('developer-portal')->group(function () {
    Route::get('/sandbox', function () {
        $service = app(SandboxService::class);

        return response()->json(['success' => true, 'data' => $service->listSandboxes()]);
    });
    Route::post('/sandbox', function (Request $request) {
        $service = app(SandboxService::class);
        $developerId = $request->user()?->user_id ?? $request->user()?->id;

        return response()->json(['success' => true, 'data' => $service->createSandbox((int) $developerId)]);
    });
});
