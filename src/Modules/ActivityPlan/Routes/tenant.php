<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\ActivityPlan\Http\Controllers\ActivityPlanAdminController;

// 租户后台 - 活动排期管理（与 Ibot 管理同级权限）
Route::prefix('tenant/activity-plan')->middleware('rbac.permission:setting.update')->group(function () {
    Route::get('/plans', [ActivityPlanAdminController::class, 'index']);
    Route::get('/plans/{planId}', [ActivityPlanAdminController::class, 'show'])->whereNumber('planId');
    Route::post('/plans', [ActivityPlanAdminController::class, 'store']);
    Route::post('/plans/{planId}/compile', [ActivityPlanAdminController::class, 'compile'])->whereNumber('planId');
    Route::post('/plans/{planId}/cancel', [ActivityPlanAdminController::class, 'cancel'])->whereNumber('planId');
    Route::post('/tasks/{taskId}/approve', [ActivityPlanAdminController::class, 'approveTask'])->whereNumber('taskId');
    Route::post('/tasks/{taskId}/reject', [ActivityPlanAdminController::class, 'rejectTask'])->whereNumber('taskId');
    Route::post('/tasks/{taskId}/complete', [ActivityPlanAdminController::class, 'completeTask'])->whereNumber('taskId');

    // 活动日历（极简排期）
    Route::get('/tasks', [ActivityPlanAdminController::class, 'tasksIndex']);
    Route::post('/manual-plans', [ActivityPlanAdminController::class, 'storeManualPlan']);
    Route::post('/plans/{planId}/tasks', [ActivityPlanAdminController::class, 'addTask'])->whereNumber('planId');
    Route::patch('/tasks/{taskId}', [ActivityPlanAdminController::class, 'updateTask'])->whereNumber('taskId');
    Route::delete('/tasks/{taskId}', [ActivityPlanAdminController::class, 'deleteTask'])->whereNumber('taskId');
    Route::delete('/plans/{planId}', [ActivityPlanAdminController::class, 'deletePlan'])->whereNumber('planId');
});
