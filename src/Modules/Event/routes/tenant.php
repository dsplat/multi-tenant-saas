<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Services\EventBusService;

Route::prefix('tenant/events')->group(function () {
    Route::get('/', function () {
        $service = app(EventBusService::class);

        return response()->json(['success' => true, 'data' => $service->getRecentEvents(100)]);
    });
});
