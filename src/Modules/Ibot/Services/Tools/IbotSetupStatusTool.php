<?php

namespace MultiTenantSaas\Modules\Ibot\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;

/**
 * ibot_setup_status — 各 IM 频道机器人配置状态（L1 只读）
 *
 * 小助手回答「怎么接企业微信机器人」类问题的事实来源：
 * 返回每个频道的配置状态、缺失字段、回调 URL 与下一步动作建议，
 * 供小助手编排 navigate → suggest_form_fill → save_ibot_config 引导链路。
 */
class IbotSetupStatusTool implements ToolHandlerContract
{
    // 各频道必填凭证字段（与 IbotAdminController 白名单一致）
    private const REQUIRED_FIELDS = [
        Ibot::CHANNEL_WECHAT_WORK => ['corp_id', 'corp_secret', 'agent_id', 'token', 'encoding_aes_key'],
        Ibot::CHANNEL_TELEGRAM => ['bot_token'],
    ];

    private const CHANNEL_LABELS = [
        Ibot::CHANNEL_WECHAT_WORK => '企业微信',
        Ibot::CHANNEL_TELEGRAM => 'Telegram',
    ];

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $channels = [];

        foreach (self::REQUIRED_FIELDS as $channelType => $requiredFields) {
            $ibot = Ibot::where('tenant_id', $tenantId)
                ->where('channel_type', $channelType)
                ->orderBy('ibot_id')
                ->first();

            $channels[] = $this->channelStatus($channelType, $requiredFields, $ibot);
        }

        return [
            'channels' => $channels,
            'settings_page' => '/ibot-settings',
            'guide' => '引导顺序：1) navigate 带用户到配置页 /ibot-settings；2) 按 missing_fields 用 suggest_form_fill 预填表单；'
                . '3) 用户确认后 save_ibot_config 保存；4) 企业微信需把 webhook_url 填入企微后台「接收消息」并通过 URL 验证；'
                . '5) generate_ibot_bind_code 生成绑定码，用户在 IM 中发送绑定码完成绑定。',
        ];
    }

    /**
     * @param  array<string>  $requiredFields
     * @return array<string, mixed>
     */
    private function channelStatus(string $channelType, array $requiredFields, ?Ibot $ibot): array
    {
        $label = self::CHANNEL_LABELS[$channelType];

        if (! $ibot) {
            return [
                'channel_type' => $channelType,
                'label' => $label,
                'configured' => false,
                'status' => 'not_configured',
                'missing_fields' => $requiredFields,
                'next_step' => "尚未配置{$label}机器人，请到配置页填写凭证：" . implode('、', $requiredFields),
            ];
        }

        $credentials = $ibot->credentials ?? [];
        $missing = array_values(array_filter(
            $requiredFields,
            fn (string $field) => trim((string) ($credentials[$field] ?? '')) === ''
        ));

        $activeBindings = $ibot->bindings()->where('status', OperatorIbotBinding::STATUS_ACTIVE)->count();

        $nextStep = match (true) {
            $missing !== [] => '凭证不完整，缺少：' . implode('、', $missing) . '，请到配置页补齐。',
            $ibot->status !== Ibot::STATUS_ACTIVE => "{$label}机器人已配置但处于停用状态，请到配置页启用。",
            $activeBindings === 0 => "{$label}机器人已激活但还没有人绑定，可用 generate_ibot_bind_code 生成绑定码。",
            default => "{$label}机器人运行正常（{$activeBindings} 个生效绑定）。",
        };

        return [
            'channel_type' => $channelType,
            'label' => $label,
            'configured' => true,
            'ibot_id' => (string) $ibot->ibot_id,
            'name' => $ibot->name,
            'status' => $ibot->status,
            'missing_fields' => $missing,
            'active_bindings' => $activeBindings,
            'webhook_url' => $channelType === Ibot::CHANNEL_WECHAT_WORK
                ? url("/api/v1/ibot/webhook/wechat-work/{$ibot->ibot_id}")
                : null,
            'next_step' => $nextStep,
        ];
    }
}
