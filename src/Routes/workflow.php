<?php

use MultiTenantSaas\Http\Controllers\WorkflowController;

// ========== Workflow Engine ==========
Route::prefix('/workflows')->group(function () {
    Route::get('/', [WorkflowController::class, 'index']);
    Route::post('/', [WorkflowController::class, 'store']);
    Route::get('/{workflowId}', [WorkflowController::class, 'show']);
    Route::put('/{workflowId}', [WorkflowController::class, 'update']);
    Route::delete('/{workflowId}', [WorkflowController::class, 'destroy']);
    Route::post('/{workflowId}/activate', [WorkflowController::class, 'activate']);
    Route::post('/{workflowId}/pause', [WorkflowController::class, 'pause']);
    Route::post('/{workflowId}/execute', [WorkflowController::class, 'execute']);
    Route::get('/{workflowId}/executions', [WorkflowController::class, 'executions']);
    Route::get('/executions/{executionId}', [WorkflowController::class, 'showExecution']);
    Route::post('/executions/{executionId}/confirm', [WorkflowController::class, 'resumeExecution']);
    Route::post('/executions/{executionId}/cancel', [WorkflowController::class, 'cancelExecution']);
});
