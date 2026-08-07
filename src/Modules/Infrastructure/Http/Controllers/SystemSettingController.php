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

        // 加密项脱敏后返回（避免明文密钥泄漏到前端）
        $settings = $query->orderBy('group')->orderBy('key')->get()
            ->map(function (SystemSetting $setting) {
                if ($setting->is_encrypted) {
                    $setting->setRawAttributes(array_merge($setting->getAttributes(), [
                        'value' => $setting->getRawOriginal('value') ? '********' : '',
                        'is_encrypted' => false,
                    ]));
                }

                return $setting;
            });

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

        // 加密键传掩码/空串时保留原值（避免前端回显的掩码覆盖真实密钥）
        foreach ($validated['settings'] as $key => $config) {
            if (is_array($config)
                && ($config['is_encrypted'] ?? false)
                && in_array($config['value'] ?? '', ['', '********'], true)) {
                unset($validated['settings'][$key]);
            }
        }

        if (empty($validated['settings'])) {
            return $this->successResponse(SystemSetting::getGroupMasked($group), 'Nothing to update');
        }

        // 审计与响应均用掩码版本，密文永不出现在日志/输出中
        $oldSettings = SystemSetting::getGroupMasked($group);

        SystemSetting::setGroup($group, $validated['settings']);

        $maskedNew = collect($validated['settings'])
            ->map(fn ($config) => is_array($config) && ($config['is_encrypted'] ?? false)
                ? array_merge($config, ['value' => '********'])
                : $config)
            ->toArray();

        app(AuditService::class)->log('update', 'system_setting', null, ['group' => $group, 'old' => $oldSettings], ['group' => $group, 'new' => $maskedNew]);

        $updated = SystemSetting::getGroupMasked($group);

        return $this->successResponse($updated, 'Settings updated');
    }
}
