<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Plugin\Http\Controllers\Admin\PluginAdminController;

Route::prefix('plugins')->group(function () {
    Route::get('/', [PluginAdminController::class, 'index']);
    Route::post('/{name}/install', [PluginAdminController::class, 'install']);
    Route::post('/{name}/uninstall', [PluginAdminController::class, 'uninstall']);
    Route::post('/{name}/enable', [PluginAdminController::class, 'enable']);
    Route::post('/{name}/disable', [PluginAdminController::class, 'disable']);
});
