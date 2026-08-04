<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Workflow\Http\Controllers\WorkflowTenantController;

Route::prefix('tenant/workflows')->group(function () {
    Route::get('/', [WorkflowTenantController::class, 'index']);
    Route::get('/{id}', [WorkflowTenantController::class, 'show']);
    Route::post('/{id}/execute', [WorkflowTenantController::class, 'execute']);
});
