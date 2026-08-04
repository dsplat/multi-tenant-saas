<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Plugin\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use MultiTenantSaas\Modules\Plugin\Services\PluginService;

/**
 * 平台管理端：插件生命周期管理
 */
class PluginAdminController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => app(PluginService::class)->listInstalled()]);
    }

    public function install(string $name)
    {
        try {
            app(PluginService::class)->install($name);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('plugin.installed')]);
    }

    public function uninstall(string $name)
    {
        try {
            app(PluginService::class)->uninstall($name);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('plugin.uninstalled')]);
    }

    public function enable(string $name)
    {
        try {
            app(PluginService::class)->enable($name);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('plugin.enabled')]);
    }

    public function disable(string $name)
    {
        try {
            app(PluginService::class)->disable($name);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => trans('plugin.disabled')]);
    }
}
