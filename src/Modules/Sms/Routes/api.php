<?php

use MultiTenantSaas\Modules\Sms\Http\Controllers\SmsController;

// ========== SMS 短信管理 ==========
Route::prefix('/tenants/{tenantId}/sms')->group(function () {
    Route::get('/templates', [SmsController::class, 'indexTemplates'])->middleware('rbac.permission:setting.view');
    Route::post('/templates', [SmsController::class, 'storeTemplate'])->middleware('rbac.permission:setting.update');
    Route::get('/templates/{templateId}', [SmsController::class, 'showTemplate'])->middleware('rbac.permission:setting.view');
    Route::put('/templates/{templateId}', [SmsController::class, 'updateTemplate'])->middleware('rbac.permission:setting.update');
    Route::delete('/templates/{templateId}', [SmsController::class, 'destroyTemplate'])->middleware('rbac.permission:setting.update');
    Route::post('/templates/{templateId}/submit-approval', [SmsController::class, 'submitForApproval'])->middleware('rbac.permission:setting.update');
    Route::post('/templates/{templateId}/render', [SmsController::class, 'renderContent'])->middleware('rbac.permission:setting.view');
    Route::post('/batch-send', [SmsController::class, 'batchSend'])->middleware('rbac.permission:setting.update');
    Route::post('/scheduled-send', [SmsController::class, 'scheduledSend'])->middleware('rbac.permission:setting.update');
    Route::get('/batch-tasks/{taskId}', [SmsController::class, 'showBatchTask'])->middleware('rbac.permission:setting.view');
    Route::post('/batch-tasks/{taskId}/cancel', [SmsController::class, 'cancelBatchTask'])->middleware('rbac.permission:setting.update');
    Route::get('/batch-tasks/{taskId}/delivery-stats', [SmsController::class, 'deliveryStats'])->middleware('rbac.permission:setting.view');
    Route::get('/overall-stats', [SmsController::class, 'overallStats'])->middleware('rbac.permission:setting.view');
    Route::get('/unsubscribes', [SmsController::class, 'indexUnsubscribes'])->middleware('rbac.permission:setting.view');
    Route::post('/unsubscribes', [SmsController::class, 'storeUnsubscribe'])->middleware('rbac.permission:setting.update');
    Route::post('/unsubscribes/check', [SmsController::class, 'checkUnsubscribed'])->middleware('rbac.permission:setting.view');
});
