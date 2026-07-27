<?php

namespace MultiTenantSaas\Modules\User\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\SocialiteService;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\Sms\Services\SmsService;

class TenantSettingController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, ?int $tenantId = null, ?string $group = null)
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        if ($group) {
            if ($group === 'sms') {
                return response()->json(['success' => true, 'data' => TenantSetting::getGroup($tenantId, 'sms')]);
            }
            $data = TenantSetting::getGroup($tenantId, $group);

            // oauth 组：附加嵌套 idp 结构供前端消费
            if ($group === 'oauth') {
                $data['idp'] = [
                    'enabled' => ($data['oauth_mode'] ?? 'direct') === 'delegated',
                    'base_url' => $data['idp_base_url'] ?? '',
                    'protocol' => $data['idp_protocol'] ?? 'standard',
                    'client_id' => $data['idp_client_id'] ?? '',
                    'client_secret' => $data['idp_client_secret'] ?? '',
                    'login_path' => $data['idp_login_path'] ?? '',
                    'redirect_uri' => $data['idp_redirect_uri'] ?? '',
                    // 未配置覆盖时的自动推导值（供前端展示占位）
                    'redirect_uri_default' => app(SocialiteService::class)
                        ->resolveRedirectUrl($tenantId, '{provider}'),
                    'field_mapping' => $data['idp_field_mapping'] ?? '',
                ];
            }
        } else {
            $data = TenantSetting::getAll($tenantId);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function update(Request $request, ?int $tenantId = null, string $group = '')
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        if ($group === 'sms') {
            $allowed = ['driver', 'sms_endpoint', 'sms_access_key', 'sms_secret_key', 'sms_sign'];
            $changes = [];
            foreach ($request->only($allowed) as $key => $value) {
                $oldValue = TenantSetting::get($tenantId, 'sms', $key);
                TenantSetting::set($tenantId, 'sms', $key, $value);
                if ($oldValue !== $value) {
                    $changes[$key] = ['old' => $oldValue, 'new' => $value];
                }
            }

            if (! empty($changes)) {
                app(AuditService::class)->log('update', 'tenant_settings', $tenantId, null, ['group' => 'sms', 'changes' => $changes]);
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
            'oauth' => ['wechat_work_corp_id', 'wechat_work_agent_id', 'wechat_work_secret',
                'wechat_client_id', 'wechat_client_secret',
                'dingtalk_client_id', 'dingtalk_client_secret',
                'feishu_client_id', 'feishu_client_secret',
                'oauth_mode', 'idp_base_url', 'idp_protocol', 'idp_client_id', 'idp_client_secret',
                'idp_login_path', 'idp_redirect_uri', 'idp_field_mapping'],
            'auth' => ['allow_phone_login', 'allow_password_login', 'email_domains'],
            'mail' => ['driver', 'host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name'],
            'registration' => ['allow_register', 'welcome_credits'],
        ];

        // oauth 组：处理前端嵌套的 idp 对象 → 扁平 tenant_settings key
        $changes = [];
        if ($group === 'oauth' && $request->has('idp')) {
            $idp = $request->input('idp', []);
            $idpMap = [
                'oauth_mode' => ! empty($idp['enabled']) ? 'delegated' : 'direct',
                'idp_base_url' => $idp['base_url'] ?? '',
                'idp_protocol' => $idp['protocol'] ?? 'standard',
                'idp_client_id' => $idp['client_id'] ?? '',
                'idp_client_secret' => $idp['client_secret'] ?? '',
                'idp_login_path' => $idp['login_path'] ?? '',
                'idp_redirect_uri' => $idp['redirect_uri'] ?? '',
                'idp_field_mapping' => $idp['field_mapping'] ?? '',
            ];
            foreach ($idpMap as $key => $value) {
                $oldValue = TenantSetting::get($tenantId, 'oauth', $key);
                TenantSetting::set($tenantId, 'oauth', $key, $value);
                if ($oldValue !== $value) {
                    $changes[$key] = ['old' => $oldValue, 'new' => $key === 'idp_client_secret' ? '***' : $value];
                }
            }
        }

        $keys = $allowedKeys[$group] ?? [];
        foreach ($request->only($keys) as $key => $value) {
            $oldValue = TenantSetting::get($tenantId, $group, $key);
            TenantSetting::set($tenantId, $group, $key, $value);
            if ($oldValue !== $value) {
                $changes[$key] = ['old' => $oldValue, 'new' => $value];
            }
        }

        if (! empty($changes)) {
            app(AuditService::class)->log('update', 'tenant_settings', $tenantId, null, ['group' => $group, 'changes' => $changes]);
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    public function testSms(Request $request, ?int $tenantId = null)
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate(['phone' => 'required|string|regex:/^1[3-9]\d{9}$/']);
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $result = app(SmsService::class)->send($request->phone, $code, 'test');

        if ($result) {
            return response()->json(['success' => true, 'message' => trans('common.success')]);
        }

        return response()->json(['success' => false, 'message' => trans('common.failed')], 500);
    }
}
