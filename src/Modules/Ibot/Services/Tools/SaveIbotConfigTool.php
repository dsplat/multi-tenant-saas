<?php

namespace MultiTenantSaas\Modules\Ibot\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\WechatWorkSuiteGuard;

/**
 * save_ibot_config — 按频道白名单保存机器人凭证（L2 写，运行时确认后执行）
 *
 * 创建或更新当前租户对应频道的 ibot：字段白名单过滤（与 IbotAdminController
 * 一致），空值/掩码值不覆盖既有明文，响应不回明文凭证。
 */
class SaveIbotConfigTool implements ToolHandlerContract
{
    private const MASK_PREFIX = '****';

    // 各频道允许写入的凭证字段白名单
    private const CREDENTIAL_FIELDS = [
        Ibot::CHANNEL_WECHAT_WORK => ['corp_id', 'corp_secret', 'agent_id', 'token', 'encoding_aes_key'],
        Ibot::CHANNEL_TELEGRAM => ['bot_token', 'bot_username'],
    ];

    private const CHANNEL_LABELS = [
        Ibot::CHANNEL_WECHAT_WORK => '企业微信',
        Ibot::CHANNEL_TELEGRAM => 'Telegram',
    ];

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $channelType = trim((string) ($arguments['channel_type'] ?? ''));

        if (! isset(self::CREDENTIAL_FIELDS[$channelType])) {
            return [
                'error' => true,
                'message' => 'channel_type 仅支持：' . implode('、', array_keys(self::CREDENTIAL_FIELDS)),
            ];
        }

        $credentials = is_array($arguments['credentials'] ?? null) ? $arguments['credentials'] : [];
        $filtered = $this->filterCredentials($channelType, $credentials);

        $ibot = Ibot::where('tenant_id', $tenantId)
            ->where('channel_type', $channelType)
            ->orderBy('ibot_id')
            ->first();

        if (! $ibot && $filtered === []) {
            return ['error' => true, 'message' => 'credentials 不能为空，请提供有效凭证字段。'];
        }

        $label = self::CHANNEL_LABELS[$channelType];

        if ($ibot) {
            // 局部合并：仅覆盖有效新值
            $ibot->credentials = array_merge($ibot->credentials ?? [], $filtered);

            if (! empty($arguments['name']) && is_string($arguments['name'])) {
                $ibot->name = trim($arguments['name']);
            }

            $ibot->save();
            $action = 'updated';
        } else {
            $ibot = Ibot::create([
                'tenant_id' => $tenantId,
                'channel_type' => $channelType,
                'transport' => Ibot::TRANSPORT_WEBHOOK,
                'name' => trim((string) ($arguments['name'] ?? '')) ?: "{$label}小助手",
                'credentials' => $filtered,
                'status' => Ibot::STATUS_ACTIVE,
            ]);
            $action = 'created';
        }

        $requiredFields = $channelType === Ibot::CHANNEL_WECHAT_WORK
            ? self::CREDENTIAL_FIELDS[$channelType]
            : ['bot_token'];

        // 9.3 双轨：套件授权租户的 corp_secret 由永久授权码充当，不要求自填
        $suiteAuthorized = $channelType === Ibot::CHANNEL_WECHAT_WORK
            ? WechatWorkSuiteGuard::authorized($tenantId)
            : false;
        if ($suiteAuthorized) {
            $requiredFields = array_values(array_diff($requiredFields, ['corp_secret']));
        }

        $saved = $ibot->credentials ?? [];
        $missing = array_values(array_filter(
            $requiredFields,
            fn (string $field) => trim((string) ($saved[$field] ?? '')) === ''
        ));

        $webhookUrl = $channelType === Ibot::CHANNEL_WECHAT_WORK
            ? url("/api/v1/ibot/webhook/wechat-work/{$ibot->ibot_id}")
            : null;

        $configuredMessage = match (true) {
            $missing !== [] => "{$label}配置已保存，但仍缺少字段：" . implode('、', $missing) . '，补齐后才能使用。',
            $suiteAuthorized => "{$label}配置已保存（平台代开发授权模式）。消息将经平台统一回调自动接入，无需填写 corp_secret，也无需在企微后台配置回调 URL。下一步：生成绑定码。",
            default => $channelType === Ibot::CHANNEL_WECHAT_WORK
                ? "{$label}配置已保存。下一步：把回调 URL {$webhookUrl} 填入企微后台「接收消息」完成 URL 验证，然后生成绑定码。"
                : "{$label}配置已保存。下一步：生成绑定码，在 IM 中发给机器人完成绑定。",
        };

        return [
            'action' => $action,
            'ibot_id' => (string) $ibot->ibot_id,
            'channel_type' => $channelType,
            'name' => $ibot->name,
            'status' => $ibot->status,
            'mode' => $channelType === Ibot::CHANNEL_WECHAT_WORK ? ($suiteAuthorized ? 'suite' : 'self') : null,
            'saved_fields' => array_keys($filtered),
            'missing_fields' => $missing,
            'webhook_url' => $webhookUrl,
            'message' => $configuredMessage,
        ];
    }

    /**
     * 凭证白名单过滤：剔除空值与掩码值（不覆盖既有）
     *
     * @return array<string, string>
     */
    private function filterCredentials(string $channelType, array $credentials): array
    {
        $filtered = [];

        foreach (self::CREDENTIAL_FIELDS[$channelType] as $field) {
            $value = $credentials[$field] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '' || str_starts_with($value, self::MASK_PREFIX)) {
                continue;
            }

            $filtered[$field] = $value;
        }

        return $filtered;
    }
}
