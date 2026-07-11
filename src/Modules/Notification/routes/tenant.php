<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Notification\Http\Controllers\NotificationController;

Route::prefix('tenant/notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/{id}', [NotificationController::class, 'destroy']);
    Route::get('/preferences', [NotificationController::class, 'getPreferences']);
    Route::post('/preferences', [NotificationController::class, 'updatePreferences']);
});
