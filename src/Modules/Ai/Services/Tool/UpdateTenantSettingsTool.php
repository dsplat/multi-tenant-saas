<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * update_tenant_settings — 小助手直接更新当前租户配置（邮件/认证/注册/短信）
 *
 * 与「租户设置」页 TenantSettingController::update 走同一套分组与 key 白名单，
 * 避免小助手只能生成表单填充建议、让用户手动保存。
 *
 * 注册为 L2：系统会先下发确认卡片（展示将写入的分组、字段与值），
 * 用户确认后才真正执行，满足「先跟用户确认要设置的信息和操作」。
 */
class UpdateTenantSettingsTool implements ToolHandlerContract
{
    /** 与 TenantSettingController::update 的 $allowedKeys 保持一致（含 sms 组） */
    private const ALLOWED_KEYS = [
        'auth' => ['allow_phone_login', 'allow_password_login', 'email_domains'],
        // mail 键名与 MailerService 租户级读取口径一致（smtp_*）
        'mail' => ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'from_address', 'from_name'],
        'registration' => ['allow_register', 'welcome_credits'],
        // sms 键名与 SmsService 租户级读取口径一致（access_key_*）
        'sms' => ['driver', 'access_key_id', 'access_key_secret', 'sign_name', 'template_code'],
    ];

    /** 敏感 key：加密存储（读方 TenantSetting::get 自动解密），确认卡片与返回结果中脱敏展示 */
    private const SENSITIVE_KEYS = ['smtp_password', 'access_key_secret'];

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        if (! app(RbacService::class)->check('setting.update')) {
            return ['error' => true, 'message' => '当前账号没有租户设置修改权限（setting.update）'];
        }

        $group = trim((string) ($arguments['group'] ?? ''));
        if (! array_key_exists($group, self::ALLOWED_KEYS)) {
            return ['error' => true, 'message' => '不支持的配置组，仅支持 mail / auth / registration / sms'];
        }

        $settings = $arguments['settings'] ?? null;
        if (! is_array($settings) || empty($settings)) {
            return ['error' => true, 'message' => '未提供 settings（字段键值对）'];
        }

        $allowed = self::ALLOWED_KEYS[$group];
        $unknown = array_diff(array_keys($settings), $allowed);
        if (! empty($unknown)) {
            return ['error' => true, 'message' => '组 ' . $group . ' 不支持字段：' . implode('、', $unknown) . '（允许：' . implode('、', $allowed) . '）'];
        }

        $changes = [];
        foreach ($settings as $key => $value) {
            $sensitive = in_array($key, self::SENSITIVE_KEYS, true);
            $oldValue = TenantSetting::get($tenantId, $group, $key);
            TenantSetting::set($tenantId, $group, $key, $value, $sensitive);
            if ($oldValue !== $value) {
                $changes[$key] = ['old' => $this->mask($group, $key, $oldValue), 'new' => $this->mask($group, $key, $value)];
            }
        }

        if (empty($changes)) {
            return ['success' => true, 'group' => $group, 'updated' => [], 'message' => '配置与现有值一致，无需变更'];
        }

        app(AuditService::class)->log('update', 'tenant_settings', $tenantId, null, ['group' => $group, 'changes' => $changes, 'source' => 'ai_tool']);

        return [
            'success' => true,
            'group' => $group,
            'updated' => $changes,
            'message' => '配置组 ' . $group . ' 已更新：' . implode('、', array_keys($changes)),
        ];
    }

    private function mask(string $group, string $key, mixed $value): mixed
    {
        return in_array($key, self::SENSITIVE_KEYS, true) && ! in_array($value, [null, ''], true) ? '***' : $value;
    }
}
