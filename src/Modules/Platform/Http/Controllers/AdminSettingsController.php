<?php

namespace MultiTenantSaas\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\SystemSetting;
use MultiTenantSaas\Services\RbacService;

class AdminSettingsController extends Controller
{
    public function index(Request $request)
    {
        if (! RbacService::check('system.settings')) {
            return response()->json(['success' => false, 'message' => trans('common.forbidden')], 403);
        }

        $settings = SystemSetting::all()->groupBy('group');

        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function update(Request $request, string $group)
    {
        if (! RbacService::check('system.settings')) {
            return response()->json(['success' => false, 'message' => trans('common.forbidden')], 403);
        }

        $allowedGroups = ['system', 'mail', 'credit', 'sms'];
        if (! in_array($group, $allowedGroups)) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 400);
        }

        foreach ($request->all() as $key => $value) {
            // key 必须是字母数字下划线
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }

            // 跳过非标量值
            if (is_array($value) || is_object($value)) {
                continue;
            }

            SystemSetting::updateOrCreate(
                ['group' => $group, 'key' => $key],
                ['value' => $value]
            );
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }
}
