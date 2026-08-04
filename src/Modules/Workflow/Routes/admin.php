<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Workflow\Http\Controllers\Admin\WorkflowAdminController;

Route::prefix('workflows')->group(function () {
    Route::get('/', [WorkflowAdminController::class, 'index']);
    Route::post('/', [WorkflowAdminController::class, 'store']);
    Route::put('/{id}', [WorkflowAdminController::class, 'update']);
    Route::delete('/{id}', [WorkflowAdminController::class, 'destroy']);
});
