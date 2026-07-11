<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Notification\Http\Controllers\NotificationController;

Route::get('/notifications', [NotificationController::class, 'index']);
Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
Route::delete('/notifications/read/clear', [NotificationController::class, 'clearRead']);
Route::get('/notifications/preferences', [NotificationController::class, 'getPreferences']);
Route::post('/notifications/preferences', [NotificationController::class, 'updatePreferences']);
Route::post('/notifications/preferences/batch', [NotificationController::class, 'batchUpdatePreferences']);
