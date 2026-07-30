<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Campaign\Http\Controllers\CampaignAdminController;

// 租户后台 - Campaign 排期管理（与 Ibot 管理同级权限）
Route::prefix('tenant/campaign')->middleware('rbac.permission:setting.update')->group(function () {
    Route::get('/plans', [CampaignAdminController::class, 'index']);
    Route::get('/plans/{planId}', [CampaignAdminController::class, 'show'])->whereNumber('planId');
    Route::post('/plans', [CampaignAdminController::class, 'store']);
    Route::post('/plans/{planId}/compile', [CampaignAdminController::class, 'compile'])->whereNumber('planId');
    Route::post('/plans/{planId}/cancel', [CampaignAdminController::class, 'cancel'])->whereNumber('planId');
    Route::post('/tasks/{taskId}/approve', [CampaignAdminController::class, 'approveTask'])->whereNumber('taskId');
    Route::post('/tasks/{taskId}/reject', [CampaignAdminController::class, 'rejectTask'])->whereNumber('taskId');
    Route::post('/tasks/{taskId}/complete', [CampaignAdminController::class, 'completeTask'])->whereNumber('taskId');

    // 活动日历（极简排期）
    Route::get('/tasks', [CampaignAdminController::class, 'tasksIndex']);
    Route::post('/manual-plans', [CampaignAdminController::class, 'storeManualPlan']);
    Route::post('/plans/{planId}/tasks', [CampaignAdminController::class, 'addTask'])->whereNumber('planId');
    Route::patch('/tasks/{taskId}', [CampaignAdminController::class, 'updateTask'])->whereNumber('taskId');
    Route::delete('/tasks/{taskId}', [CampaignAdminController::class, 'deleteTask'])->whereNumber('taskId');
    Route::delete('/plans/{planId}', [CampaignAdminController::class, 'deletePlan'])->whereNumber('planId');
});
