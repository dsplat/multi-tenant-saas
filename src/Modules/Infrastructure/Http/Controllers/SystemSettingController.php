<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class SystemSettingController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = SystemSetting::query();

        if ($request->has('group')) {
            $query->where('group', $request->query('group'));
        }

        $settings = $query->orderBy('group')->orderBy('key')->get();

        return $this->successResponse($settings);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array|min:1',
            'settings.*' => 'required|array',
            'settings.*.value' => 'required',
            'settings.*.is_encrypted' => 'sometimes|boolean',
            'settings.*.description' => 'nullable|string|max:500',
        ]);

        $oldSettings = SystemSetting::getGroup($group);

        SystemSetting::setGroup($group, $validated['settings']);

        AuditService::log('update', 'system_setting', null, ['group' => $group, 'old' => $oldSettings], ['group' => $group, 'new' => $validated['settings']]);

        $updated = SystemSetting::getGroup($group);

        return $this->successResponse($updated, 'Settings updated');
    }
}
