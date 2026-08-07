<?php

namespace MultiTenantSaas\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;

class AdminSettingsController extends Controller
{
    /** 各分组需加密存储的敏感键 */
    private const ENCRYPTED_KEYS = [
        'mail' => ['password'],
        'storage' => ['access_key_secret'],
        'external_kb' => ['api_key'],
    ];

    public function index(Request $request)
    {
        if (! app(RbacService::class)->check('system.settings')) {
            return response()->json(['success' => false, 'message' => trans('common.forbidden')], 403);
        }

        // 加密项脱敏后返回（前端以掩码回显）
        $settings = SystemSetting::all()
            ->map(function (SystemSetting $setting) {
                if ($setting->is_encrypted) {
                    $setting->setRawAttributes(array_merge($setting->getAttributes(), [
                        'value' => $setting->getRawOriginal('value') ? '********' : '',
                        'is_encrypted' => false,
                    ]));
                }

                return $setting;
            })
            ->groupBy('group');

        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function update(Request $request, string $group)
    {
        if (! app(RbacService::class)->check('system.settings')) {
            return response()->json(['success' => false, 'message' => trans('common.forbidden')], 403);
        }

        $allowedGroups = ['system', 'mail', 'credit', 'sms', 'storage', 'external_kb'];
        if (! in_array($group, $allowedGroups)) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 400);
        }

        $encryptedKeys = self::ENCRYPTED_KEYS[$group] ?? [];

        foreach ($request->all() as $key => $value) {
            // key 必须是字母数字下划线
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }

            // 跳过非标量值
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $isEncrypted = in_array($key, $encryptedKeys, true);

            // 敏感键传掩码或空时保留原值
            if ($isEncrypted && ($value === '' || $value === null || $value === '********')) {
                continue;
            }

            SystemSetting::set($group, $key, $value, $isEncrypted);
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    /**
     * 发送平台级测试邮件（验证 system_settings mail 组配置）
     *
     * POST /api/v1/admin/settings/mail/test
     */
    public function sendTestMail(Request $request)
    {
        if (! app(RbacService::class)->check('system.settings')) {
            return response()->json(['success' => false, 'message' => trans('common.forbidden')], 403);
        }

        $request->validate(['email' => 'required|email']);

        // tenantId 为 null → 走平台 SMTP（system_settings）或 env 全局通道
        $ok = app(MailerService::class)->sendTest($request->input('email'));

        return $ok
            ? response()->json(['success' => true, 'message' => trans('common.test_email_sent')])
            : response()->json(['success' => false, 'message' => trans('common.email_send_failed')], 500);
    }
}
