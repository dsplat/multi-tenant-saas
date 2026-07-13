<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Plugin\Services\PluginService;

Route::prefix('admin/plugins')->group(function () {
    Route::get('/', function () {
        return response()->json(['success' => true, 'data' => app(PluginService::class)->listPlugins()]);
    });
    Route::post('/{name}/install', function (string $name) {
        app(PluginService::class)->install($name);

        return response()->json(['success' => true, 'message' => trans('plugin.installed')]);
    });
    Route::post('/{name}/uninstall', function (string $name) {
        app(PluginService::class)->uninstall($name);

        return response()->json(['success' => true, 'message' => trans('plugin.uninstalled')]);
    });
    Route::post('/{name}/enable', function (string $name) {
        app(PluginService::class)->enable($name);

        return response()->json(['success' => true, 'message' => trans('plugin.enabled')]);
    });
    Route::post('/{name}/disable', function (string $name) {
        app(PluginService::class)->disable($name);

        return response()->json(['success' => true, 'message' => trans('plugin.disabled')]);
    });
});
