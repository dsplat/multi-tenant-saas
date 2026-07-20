<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowService;

Route::prefix('workflows')->group(function () {
    Route::get('/', function () {
        $service = app(WorkflowService::class);

        return response()->json(['success' => true, 'data' => $service->listForTenant()]);
    });
    Route::post('/', function (Request $request) {
        $service = app(WorkflowService::class);
        $request->validate(['name' => 'required|string', 'definition' => 'required|array']);
        $workflow = $service->create($request->all());

        return response()->json(['success' => true, 'data' => $workflow], 201);
    });
    Route::put('/{id}', function (Request $request, string $id) {
        $service = app(WorkflowService::class);
        $workflow = $service->update($id, $request->all());

        return response()->json(['success' => true, 'data' => $workflow]);
    });
    Route::delete('/{id}', function (string $id) {
        $service = app(WorkflowService::class);
        $service->delete($id);

        return response()->json(['success' => true, 'message' => trans('workflow.deleted')]);
    });
});
