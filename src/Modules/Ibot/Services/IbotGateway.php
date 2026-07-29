<?php

namespace MultiTenantSaas\Modules\Ibot\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\DTOs\IbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Jobs\ProcessIbotInboundMessage;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;

/**
 * 入向消息统一网关（docs/ibot.md 第五节）
 *
 * 各频道（轮询命令/webhook 控制器）解析出归一化消息后统一入此：
 *   开关关闭 → 静默返回（AI 可选性铁律）
 *   已绑定   → 派发 Job 交 AgentRuntime 处理
 *   未绑定   → 尝试消费绑定码（/start CODE 或裸 8 位码），否则回引导语
 */
class IbotGateway
{
    public function __construct(
        private readonly IbotBindingService $bindingService,
        private readonly IbotChannelResolver $resolver,
    ) {}

    public function handleInbound(Ibot $ibot, IbotInboundMessage $msg): void
    {
        // AI 可选性：平台级开关关闭时全身而退
        if (! config('ai.ibot.enabled', false)) {
            return;
        }

        if (! $ibot->isActive()) {
            return;
        }

        // 恢复租户上下文（轮询命令/webhook 均无 tenant.identify 中间件）
        TenantContext::setTenantId((string) $ibot->tenant_id);

        $binding = OperatorIbotBinding::where('ibot_id', $ibot->ibot_id)
            ->where('external_id', $msg->externalId)
            ->where('status', OperatorIbotBinding::STATUS_ACTIVE)
            ->first();

        if ($binding) {
            ProcessIbotInboundMessage::dispatch(
                (int) $ibot->tenant_id,
                (int) $ibot->ibot_id,
                (int) $binding->binding_id,
                $msg->text,
            );

            return;
        }

        // 未绑定 → 尝试绑定码流程
        $this->handleUnbound($ibot, $msg);
    }

    private function handleUnbound(Ibot $ibot, IbotInboundMessage $msg): void
    {
        $channel = $this->resolver->resolve($ibot);
        $code = $this->extractBindCode($msg->text);

        if ($code === null) {
            $channel->sendMessage($ibot, $msg->externalId, __(
                '您还未绑定账号。请登录控制台，在「AI 助理 → 随身助理」生成绑定码后发送给我完成绑定。'
            ));

            return;
        }

        $binding = $this->bindingService->consume($code, $ibot, $msg->externalId);

        if ($binding) {
            $channel->sendMessage($ibot, $msg->externalId, __(
                '绑定成功！我是您的随身 AI 小助理，直接发消息即可开始对话。'
            ));
        } else {
            $channel->sendMessage($ibot, $msg->externalId, __(
                '绑定码无效或已过期，请回控制台重新生成。'
            ));
        }
    }

    /**
     * 提取绑定码：支持 "/start CODE"（TG deep link）与裸 8 位码两种形式
     */
    private function extractBindCode(string $text): ?string
    {
        $text = trim($text);

        if (preg_match('/^\/start\s+([A-Za-z0-9]{8})$/', $text, $m)) {
            return strtoupper($m[1]);
        }

        if (preg_match('/^[A-Za-z0-9]{8}$/', $text)) {
            return strtoupper($text);
        }

        return null;
    }
}
