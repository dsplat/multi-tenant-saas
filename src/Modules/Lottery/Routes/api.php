<?php

use MultiTenantSaas\Modules\Lottery\Http\Controllers\LotteryController;

// ========== Lottery 抽奖 ==========
Route::prefix('/tenants/{tenantId}/lottery')->group(function () {
    Route::get('/', [LotteryController::class, 'index'])->middleware('rbac.permission:lottery.view');
    Route::post('/', [LotteryController::class, 'store'])->middleware('rbac.permission:lottery.create');
    Route::get('/{activityId}', [LotteryController::class, 'show'])->middleware('rbac.permission:lottery.view');
    Route::put('/{activityId}', [LotteryController::class, 'update'])->middleware('rbac.permission:lottery.update');
    Route::delete('/{activityId}', [LotteryController::class, 'destroy'])->middleware('rbac.permission:lottery.delete');
    Route::put('/{activityId}/status', [LotteryController::class, 'updateStatus'])->middleware('rbac.permission:lottery.update');
    Route::get('/{activityId}/prizes', [LotteryController::class, 'indexPrizes'])->middleware('rbac.permission:lottery.view');
    Route::post('/{activityId}/prizes', [LotteryController::class, 'storePrize'])->middleware('rbac.permission:lottery.create');
    Route::put('/{activityId}/prizes/{prizeId}', [LotteryController::class, 'updatePrize'])->middleware('rbac.permission:lottery.update');
    Route::delete('/{activityId}/prizes/{prizeId}', [LotteryController::class, 'destroyPrize'])->middleware('rbac.permission:lottery.delete');
    Route::post('/{activityId}/draw', [LotteryController::class, 'draw'])->middleware(['rbac.permission:lottery.draw', 'throttle:10,1']);
    Route::get('/{activityId}/blacklist', [LotteryController::class, 'indexBlacklist'])->middleware('rbac.permission:lottery.view');
    Route::post('/{activityId}/blacklist', [LotteryController::class, 'storeBlacklist'])->middleware('rbac.permission:lottery.create');
    Route::delete('/{activityId}/blacklist', [LotteryController::class, 'destroyBlacklist'])->middleware('rbac.permission:lottery.delete');
    Route::get('/{activityId}/statistics', [LotteryController::class, 'statistics'])->middleware('rbac.permission:lottery.view');
    Route::get('/{activityId}/my-logs', [LotteryController::class, 'userDrawLogs'])->middleware('rbac.permission:lottery.view');
    Route::get('/{activityId}/win-logs', [LotteryController::class, 'winLogs'])->middleware('rbac.permission:lottery.view');
    Route::get('/{activityId}/export', [LotteryController::class, 'export'])->middleware('rbac.permission:lottery.view');
});
