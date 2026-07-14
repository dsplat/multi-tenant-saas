<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowService;

Route::prefix('tenant/workflows')->group(function () {
    Route::get('/', function (Request $request) {
        $service = app(WorkflowService::class);

        return response()->json(['success' => true, 'data' => $service->listForTenant()]);
    });
    Route::get('/{id}', function (string $id) {
        $service = app(WorkflowService::class);

        return response()->json(['success' => true, 'data' => $service->find($id)]);
    });
    Route::post('/{id}/execute', function (Request $request, string $id) {
        $service = app(WorkflowService::class);
        $result = $service->startExecution($id, $request->all());

        return response()->json(['success' => true, 'data' => $result]);
    });
});
