<?php

namespace MultiTenantSaas\Modules\Ibot\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Channels\TelegramChannel;
use MultiTenantSaas\Modules\Ibot\Services\IbotBindingService;

/**
 * generate_ibot_bind_code — 为当前 operator 生成一次性绑定码（L2）
 *
 * 依赖 HTTP 认证上下文取 operator（Console 小助手对话场景）；
 * 队列/ibot 入向场景无认证上下文时返回引导话术而非报错。
 */
class GenerateIbotBindCodeTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        if (! config('ai.ibot.enabled', false)) {
            return ['error' => true, 'message' => '随身助理功能未启用，请联系平台管理员。'];
        }

        $operatorId = (int) (auth('sanctum')->user()?->operator_id ?? 0);

        if ($operatorId <= 0) {
            return [
                'error' => true,
                'message' => '当前会话无法识别操作人身份，请到配置页 /ibot-settings 的绑定区生成绑定码。',
            ];
        }

        $channelType = trim((string) ($arguments['channel_type'] ?? ''));

        $query = Ibot::where('tenant_id', $tenantId)->where('status', Ibot::STATUS_ACTIVE);

        if ($channelType !== '') {
            $query->where('channel_type', $channelType);
        }

        $ibots = $query->orderBy('ibot_id')->get();

        if ($ibots->isEmpty()) {
            return [
                'error' => true,
                'message' => $channelType !== ''
                    ? "没有已激活的 {$channelType} 机器人，请先用 ibot_setup_status 检查配置。"
                    : '当前租户没有已激活的机器人，请先完成频道配置。',
            ];
        }

        if ($ibots->count() > 1) {
            return [
                'error' => true,
                'message' => '存在多个已激活机器人，请通过 channel_type 指定频道：'
                    . $ibots->pluck('channel_type')->unique()->implode('、'),
            ];
        }

        $ibot = $ibots->first();
        $code = app(IbotBindingService::class)->generateBindCode($operatorId, $ibot);

        // Telegram 可生成 t.me deep link（前端可做成二维码）
        $bindLink = $ibot->channel_type === Ibot::CHANNEL_TELEGRAM
            ? app(TelegramChannel::class)->bindLink($ibot, $code)
            : null;

        return [
            'code' => $code,
            'channel_type' => $ibot->channel_type,
            'ibot_name' => $ibot->name,
            'bind_link' => $bindLink,
            // 二维码内容：Telegram 用 deep link（扫码直达会话），企微用绑定码文本（扫一扫识别后发送）
            'bind_qr' => $bindLink ?? $code,
            'expires_in' => (int) config('ai.ibot.bind_code_ttl', 600),
            'message' => $ibot->channel_type === Ibot::CHANNEL_WECHAT_WORK
                ? "绑定码 {$code}（有效期内一次性使用）。在企业微信中用扫一扫识别下方二维码获取绑定码，打开机器人应用发送即可完成绑定。"
                : "绑定码 {$code}（有效期内一次性使用）。在 IM 中向机器人发送该绑定码完成绑定。",
        ];
    }
}
