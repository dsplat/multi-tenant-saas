<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\SystemSetting;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Services\SmsService;

class TenantSettingController extends Controller
{
    public function index(Request $request, int $tenantId, ?string $group = null)
    {
        if ($error = $this->ensureTenantAccess($request, $tenantId)) {
            return $error;
        }

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
        if ($error = $this->ensureTenantAccess($request, $tenantId)) {
            return $error;
        }

        if ($group === 'sms') {
            $allowed = ['driver', 'ww_endpoint', 'ww_account', 'ww_password', 'ww_sign', 'ww_product_id', 'mtedu_endpoint'];
            foreach ($request->only($allowed) as $key => $value) {
                SystemSetting::updateOrCreate(
                    ['group' => 'sms', 'key' => $key],
                    ['value' => $value]
                );
            }
            return response()->json(['success' => true, 'message' => '短信配置已更新']);
        }

        $allowedGroups = ['info', 'oauth', 'auth', 'mail', 'registration'];
        if (!in_array($group, $allowedGroups)) {
            return response()->json(['success' => false, 'message' => '未知配置组'], 400);
        }

        // 白名单过滤：每个配置组只允许特定的 key
        $allowedKeys = [
            'info' => ['name', 'description', 'logo', 'contact_name', 'contact_email', 'contact_phone'],
            'oauth' => ['wechat_app_id', 'wechat_app_secret', 'github_client_id', 'github_client_secret'],
            'auth' => ['allow_register', 'allow_oauth', 'require_email_verify'],
            'mail' => ['driver', 'host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name'],
            'registration' => ['allow_register', 'require_approval', 'default_role'],
        ];

        $keys = $allowedKeys[$group] ?? [];
        foreach ($request->only($keys) as $key => $value) {
            TenantSetting::set($tenantId, $group, $key, $value);
        }

        return response()->json(['success' => true, 'message' => '配置已更新']);
    }

    public function testSms(Request $request, int $tenantId)
    {
        if ($error = $this->ensureTenantAccess($request, $tenantId)) {
            return $error;
        }

        $request->validate(['phone' => 'required|string|regex:/^1[3-9]\d{9}$/']);
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $result = SmsService::send($request->phone, $code, 'test');

        if ($result) {
            return response()->json(['success' => true, 'message' => '测试短信已发送']);
        }

        return response()->json(['success' => false, 'message' => '短信发送失败'], 500);
    }

    protected function ensureTenantAccess(Request $request, int $tenantId)
    {
        $user = $request->user();

        if ($user->role === 'super_admin') {
            return response()->json(['success' => false, 'message' => '系统管理员不能访问租户数据'], 403);
        }

        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();

        if (!$tenantUser) {
            return response()->json(['success' => false, 'message' => '您不属于该租户'], 403);
        }

        return null;
    }
}
