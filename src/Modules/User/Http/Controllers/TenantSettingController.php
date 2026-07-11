<?php

namespace MultiTenantSaas\Modules\User\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\SystemSetting;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Modules\Sms\Services\SmsService;
use MultiTenantSaas\Services\AuditService;

class TenantSettingController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, int $tenantId, ?string $group = null)
    {
        $this->ensureTenantAccess($request, $tenantId);

        if ($group) {
            if ($group === 'sms') {
                return response()->json(['success' => true, 'data' => [
                    'driver' => config('services.sms.driver', 'log'),
                    'ww_endpoint' => config('services.sms.ww_endpoint', ''),
                    'ww_account' => config('services.sms.ww_account', ''),
                    'ww_sign' => config('services.sms.ww_sign', ''),
                    'mtedu_endpoint' => config('services.sms.mtedu_endpoint', ''),
                ]]);
            }
            $data = TenantSetting::getGroup($tenantId, $group);
        } else {
            $data = TenantSetting::getAll($tenantId);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function update(Request $request, int $tenantId, string $group)
    {
        $this->ensureTenantAccess($request, $tenantId);

        if ($group === 'sms') {
            $allowed = ['driver', 'ww_endpoint', 'ww_account', 'ww_password', 'ww_sign', 'ww_product_id', 'mtedu_endpoint'];
            foreach ($request->only($allowed) as $key => $value) {
                SystemSetting::updateOrCreate(
                    ['group' => 'sms', 'key' => $key],
                    ['value' => $value]
                );
            }

            return response()->json(['success' => true, 'message' => trans('common.updated')]);
        }

        $allowedGroups = ['info', 'oauth', 'auth', 'mail', 'registration'];
        if (! in_array($group, $allowedGroups)) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 400);
        }

        // 白名单：每个配置组只允许特定 key
        $allowedKeys = [
            'info' => ['name', 'description', 'logo', 'contact_name', 'contact_email', 'contact_phone'],
            'oauth' => ['wechat_enabled', 'wechat_corp_id', 'wechat_agent_id', 'wechat_secret',
                'dingtalk_enabled', 'dingtalk_app_key', 'dingtalk_app_secret',
                'feishu_enabled', 'feishu_app_id', 'feishu_app_secret'],
            'auth' => ['allow_phone_login', 'allow_password_login', 'email_domains'],
            'mail' => ['driver', 'host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name'],
            'registration' => ['allow_register', 'welcome_credits'],
        ];

        $keys = $allowedKeys[$group] ?? [];
        $changes = [];
        foreach ($request->only($keys) as $key => $value) {
            $oldValue = TenantSetting::get($tenantId, $group, $key);
            TenantSetting::set($tenantId, $group, $key, $value);
            if ($oldValue !== $value) {
                $changes[$key] = ['old' => $oldValue, 'new' => $value];
            }
        }

        if (! empty($changes)) {
            AuditService::log('update', 'tenant_settings', $tenantId, null, ['group' => $group, 'changes' => $changes]);
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    public function testSms(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate(['phone' => 'required|string|regex:/^1[3-9]\d{9}$/']);
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $result = SmsService::send($request->phone, $code, 'test');

        if ($result) {
            return response()->json(['success' => true, 'message' => trans('common.success')]);
        }

        return response()->json(['success' => false, 'message' => trans('common.failed')], 500);
    }
}
