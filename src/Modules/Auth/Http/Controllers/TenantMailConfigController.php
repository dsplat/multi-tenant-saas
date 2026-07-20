<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;

/**
 * 租户邮件配置控制器
 *
 * 管理租户级 SMTP 配置（存储在 tenant_settings, group='mail'）。
 */
class TenantMailConfigController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 获取租户 SMTP 配置（密码遮罩）
     *
     * GET /api/v1/tenant/auth/mail/config
     */
    public function getConfig(Request $request)
    {
        $tenantId = TenantContext::getId();

        $config = [
            'smtp_host' => TenantSetting::get($tenantId, 'mail', 'smtp_host', ''),
            'smtp_port' => TenantSetting::get($tenantId, 'mail', 'smtp_port', 465),
            'smtp_encryption' => TenantSetting::get($tenantId, 'mail', 'smtp_encryption', 'ssl'),
            'smtp_username' => TenantSetting::get($tenantId, 'mail', 'smtp_username', ''),
            'smtp_password' => TenantSetting::get($tenantId, 'mail', 'smtp_password', '') ? '********' : '',
            'from_address' => TenantSetting::get($tenantId, 'mail', 'from_address', ''),
            'from_name' => TenantSetting::get($tenantId, 'mail', 'from_name', ''),
        ];

        $config['configured'] = ! empty($config['smtp_host']);

        return response()->json(['success' => true, 'data' => $config]);
    }

    /**
     * 更新租户 SMTP 配置
     *
     * PUT /api/v1/tenant/auth/mail/config
     */
    public function updateConfig(Request $request)
    {
        $tenantId = TenantContext::getId();

        $fields = [
            'smtp_host' => false,
            'smtp_port' => false,
            'smtp_encryption' => false,
            'smtp_username' => false,
            'smtp_password' => true, // 加密存储
            'from_address' => false,
            'from_name' => false,
        ];

        foreach ($fields as $key => $encrypted) {
            $value = $request->input($key);

            if ($value === null) {
                continue;
            }

            // 跳过遮罩占位符（密码未修改）
            if ($encrypted && $value === '********') {
                continue;
            }

            TenantSetting::set($tenantId, 'mail', $key, $value, $encrypted);
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    /**
     * 发送测试邮件
     *
     * POST /api/v1/tenant/auth/mail/test
     */
    public function sendTest(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $tenantId = TenantContext::getId();
        $mailerService = app(MailerService::class);

        $result = $mailerService->sendTest($request->input('email'), (int) $tenantId);

        if ($result) {
            return response()->json(['success' => true, 'message' => trans('common.test_email_sent')]);
        }

        return response()->json(['success' => false, 'message' => trans('common.email_send_failed')], 500);
    }
}
