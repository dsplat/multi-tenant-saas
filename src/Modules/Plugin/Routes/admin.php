<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Plugin\Services\PluginService;

Route::prefix('plugins')->group(function () {
    Route::get('/', function () {
        return response()->json(['success' => true, 'data' => app(PluginService::class)->listInstalled()]);
    });
    Route::post('/{name}/install', function (string $name) {
        try {
            app(PluginService::class)->install($name);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('plugin.installed')]);
    });
    Route::post('/{name}/uninstall', function (string $name) {
        try {
            app(PluginService::class)->uninstall($name);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('plugin.uninstalled')]);
    });
    Route::post('/{name}/enable', function (string $name) {
        try {
            app(PluginService::class)->enable($name);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('plugin.enabled')]);
    });
    Route::post('/{name}/disable', function (string $name) {
        try {
            app(PluginService::class)->disable($name);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('plugin.disabled')]);
    });
});
