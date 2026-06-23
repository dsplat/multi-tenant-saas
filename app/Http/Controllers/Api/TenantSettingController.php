<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\SystemSetting;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Services\SmsService;

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
            return response()->json(['success' => true, 'message' => '短信配置已更新']);
        }

        $allowedGroups = ['info', 'oauth', 'auth', 'mail', 'registration'];
        if (!in_array($group, $allowedGroups)) {
            return response()->json(['success' => false, 'message' => '未知配置组'], 400);
        }

        foreach ($request->all() as $key => $value) {
            TenantSetting::set($tenantId, $group, $key, $value);
        }

        return response()->json(['success' => true, 'message' => '配置已更新']);
    }

    public function testSms(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate(['phone' => 'required|string|regex:/^1[3-9]\d{9}$/']);
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $result = SmsService::send($request->phone, $code, 'test');

        if ($result) {
            return response()->json(['success' => true, 'message' => '测试短信已发送']);
        }

        return response()->json(['success' => false, 'message' => '短信发送失败'], 500);
    }
}
