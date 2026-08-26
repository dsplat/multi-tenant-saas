<?php

namespace MultiTenantSaas\Modules\Ibot\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Services\Agent\ActionConfirmService;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentProvisioningService;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentRuntime;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolConversationContext;
use MultiTenantSaas\Modules\Ai\Services\Agent\ToolRegistry;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Services\IbotChannelResolver;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * 入向消息异步处理（docs/ibot.md 第五/六节）
 *
 * 恢复租户上下文 → 解析目标 agent（ibot 指定或系统小秘书）→
 * find-or-create 承载会话（回写 binding.conversation_id）→
 * L2 文本确认协议（pending 检测 → 确认/取消/过期）→
 * AgentRuntime 非流式执行（intercept_l2）→ 回复经频道发回 IM。
 *
 * 对话不重试（tries=1）：重试会造成重复回复，比丢一条消息更糟。
 */
class ProcessIbotInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * IM 渠道排除的 UI 指令工具：返回值仅 console 前端能渲染（跳页/填表），
     * IM 里无对应前端，暴露给模型只会劫走业务意图并空转到工具调用上限。
     */
    private const EXCLUDED_UI_TOOLS = ['navigate', 'suggest_form_fill'];

    public function __construct(
        private readonly int $tenantId,
        private readonly int $ibotId,
        private readonly int $bindingId,
        private readonly string $text,
    ) {}

    public function handle(AgentRuntime $runtime, IbotChannelResolver $resolver): void
    {
        // 开关可能在派发后被关闭，Job 侧再守卫一次
        if (! config('ai.ibot.enabled', false)) {
            return;
        }

        TenantContext::setTenantId((string) $this->tenantId);

        $ibot = Ibot::where('ibot_id', $this->ibotId)->first();
        $binding = OperatorIbotBinding::where('binding_id', $this->bindingId)->first();

        if (! $ibot || ! $binding || ! $ibot->isActive() || ! $binding->isActive()) {
            return;
        }

        $channel = $resolver->resolve($ibot);
        $agent = $this->resolveAgent($ibot);

        if (! $agent) {
            $channel->sendMessage($ibot, $binding->external_id, __(
                'AI 小助手初始化失败，请联系平台管理员执行 secretary:install。'
            ));

            return;
        }

        $conversation = $this->resolveConversation($binding, (int) $agent->agent_id);

        // ── L2 文本确认协议（docs/ibot.md 第六节）──
        // 入站先查 pending：若存在待确认操作，本条消息优先走确认/取消分支
        $pending = $conversation->metadata['ibot_pending_confirm'] ?? null;

        if ($pending && is_array($pending)) {
            $continueAsNew = $this->handlePendingConfirm(
                $runtime, $ibot, $binding, $conversation, $channel, $pending,
            );

            // 确认执行成功 → 已回复，结束；取消/过期 → 消息作为新输入继续
            if (! $continueAsNew) {
                return;
            }
        }

        // ── 正常 run()（开启 L2 拦截，裁剪 console 专属 UI 工具）──
        $response = $runtime->run(
            (int) $agent->agent_id,
            (int) $conversation->conversation_id,
            $this->text,
            [
                'intercept_l2' => true,
                'confirm_ttl' => config('ai.ibot.confirm_ttl', 600),
                'exclude_tools' => self::EXCLUDED_UI_TOOLS,
            ],
        );

        // L2 待确认：写 metadata + 回发确认话术
        if ($response->finishReason === 'pending_confirmation' && $response->pendingConfirmations !== []) {
            $this->handlePendingResponse($conversation, $ibot, $binding, $channel, $response->pendingConfirmations);

            return;
        }

        $reply = trim((string) $response->message);

        if ($reply === '') {
            Log::warning('[Ibot] AgentRuntime 空回复', [
                'ibot_id' => $this->ibotId,
                'binding_id' => $this->bindingId,
                'finish_reason' => $response->finishReason,
            ]);
            $reply = __('抱歉，我暂时无法回复，请稍后再试。');
        }

        $channel->sendMessage($ibot, $binding->external_id, $reply);
    }

    // ──────────────────────────────────────────────
    //  L2 文本确认协议
    // ──────────────────────────────────────────────

    /**
     * 处理待确认操作（确认 / 取消 / 过期三分支）
     *
     * @return bool true = 该消息应作为新输入继续 run()；false = 已处理完毕
     */
    private function handlePendingConfirm(
        AgentRuntime $runtime,
        Ibot $ibot,
        OperatorIbotBinding $binding,
        AgentConversation $conversation,
        mixed $channel,
        array $pending,
    ): bool {
        $trimmed = trim($this->text);
        $isConfirmWord = $trimmed === '确认' || strtolower($trimmed) === 'yes';

        // ── 确认执行 ──
        if ($isConfirmWord) {
            // 权限校验（对齐 AssistantController::ensureOperatorCanExecute）
            if (! $this->operatorCanExecute((int) $binding->operator_id)) {
                $this->clearPendingMeta($conversation);
                $channel->sendMessage($ibot, $binding->external_id, __('您没有执行该操作的权限（仅团队管理员可确认）。'));

                return false;
            }

            // 消费令牌（先删后校验，并发安全）
            try {
                $payload = app(ActionConfirmService::class)->consume(
                    $pending['token'],
                    $this->tenantId,
                    (int) $conversation->conversation_id,
                    $pending['args_hash'],
                );
            } catch (\RuntimeException $e) {
                // 令牌过期/不符 → 清 metadata，回发提示，消息续跑
                $this->clearPendingMeta($conversation);
                $channel->sendMessage($ibot, $binding->external_id, __('该确认已过期或无效，请重新发起操作。'));

                return true;
            }

            $toolSlug = (string) ($payload['tool_slug'] ?? $pending['tool_slug'] ?? '');
            $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
            $toolCallId = $payload['tool_call_id'] ?? null;

            // 以服务端存储的参数执行（不信任客户端）
            $error = null;
            $result = null;

            try {
                // 会话感知工具（如任务链三工具）需要当前会话 ID，执行前注入
                app(ToolConversationContext::class)->set((int) $conversation->conversation_id);
                $result = app(ToolRegistry::class)->execute($toolSlug, $arguments, $this->tenantId);
                if (is_array($result) && ($result['error'] ?? false)) {
                    $error = $result['message'] ?? '工具执行失败';
                    $result = null;
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            app(AuditService::class)->log('ai_action_execute', 'agent_tool', null, [
                'tool_slug' => $toolSlug,
                'arguments' => $arguments,
                'conversation_id' => (int) $conversation->conversation_id,
                'channel' => 'ibot',
            ], ['success' => $error === null, 'error' => $error]);

            // 工具结果入会话，让 LLM 收尾
            $toolResultContent = $error !== null
                ? json_encode(['error' => $error], JSON_UNESCAPED_UNICODE)
                : (is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE));

            $response = $runtime->continueWithToolResults((int) $conversation->conversation_id, [[
                'tool_name' => $toolSlug,
                'tool_call_id' => $toolCallId,
                'content' => $toolResultContent,
            ]], ['exclude_tools' => self::EXCLUDED_UI_TOOLS]);

            $this->clearPendingMeta($conversation);

            $reply = trim((string) $response->message);
            $channel->sendMessage($ibot, $binding->external_id, $reply !== '' ? $reply : __('操作已执行完成。'));

            return false;
        }

        // ── 取消（非确认词一律取消，宁误取消不误执行）──
        try {
            app(ActionConfirmService::class)->consume(
                $pending['token'],
                $this->tenantId,
                (int) $conversation->conversation_id,
                $pending['args_hash'],
            );
        } catch (\RuntimeException) {
            // 令牌已过期/不存在，静默继续
        }

        $toolName = $pending['tool_name'] ?? $pending['tool_slug'] ?? '未知操作';

        app(AuditService::class)->log('ai_action_cancel', 'agent_tool', null, [
            'tool_slug' => $pending['tool_slug'] ?? '',
            'conversation_id' => (int) $conversation->conversation_id,
            'channel' => 'ibot',
        ], ['cancelled' => true]);

        // 取消结果入会话保持 tool_calls 上下文配对
        $runtime->continueWithToolResults((int) $conversation->conversation_id, [[
            'tool_name' => $pending['tool_slug'] ?? '',
            'tool_call_id' => null,
            'content' => json_encode(['cancelled' => true, 'message' => '用户已取消该操作'], JSON_UNESCAPED_UNICODE),
        ]]);

        $this->clearPendingMeta($conversation);
        $channel->sendMessage($ibot, $binding->external_id, __("已取消【{$toolName}】。"));

        // 取消不吞掉用户消息 → 作为新输入继续
        return true;
    }

    /**
     * run() 返回 pending_confirmation：写 metadata + 回发确认话术
     */
    private function handlePendingResponse(
        AgentConversation $conversation,
        Ibot $ibot,
        OperatorIbotBinding $binding,
        mixed $channel,
        array $pendingConfirmations,
    ): void {
        $first = $pendingConfirmations[0];

        $metadata = $conversation->metadata ?? [];
        $metadata['ibot_pending_confirm'] = $first;
        $conversation->update(['metadata' => $metadata]);

        $confirmPrompt = $this->buildConfirmPrompt($first);

        // 同轮多个 L2：IM 侧只取第一个，提示用户分步
        if (count($pendingConfirmations) > 1) {
            $confirmPrompt .= "\n\n" . __('（本次有多个待确认操作，一次只能确认一个，其余请分步发起。）');
        }

        $channel->sendMessage($ibot, $binding->external_id, $confirmPrompt);
    }

    /**
     * 权限校验：operator_tenants 活跃关联 + 角色为 tenant_admin
     *
     * 对齐 AssistantController::ensureOperatorCanExecute，Job 内无 Request 故直接查库。
     */
    private function operatorCanExecute(int $operatorId): bool
    {
        $operatorTenant = DB::table('operator_tenants')
            ->where('operator_id', $operatorId)
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->first();

        if (! $operatorTenant) {
            return false;
        }

        // 集合判定：租户可能存在专属 tenant_admin 角色行，operator 可能绑定全局或租户专属角色，
        // 单行取值（value）会误拒绑定另一角色的运营者。
        $tenantAdminRoleIds = DB::table('roles')
            ->where('name', 'tenant_admin')
            ->where(function ($q) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenantId);
            })
            ->pluck('role_id')
            ->all();

        return in_array($operatorTenant->role_id, $tenantAdminRoleIds, true);
    }

    /**
     * 构建确认话术（工具名 + 参数摘要 + 操作提示）
     */
    private function buildConfirmPrompt(array $pending): string
    {
        $toolName = $pending['tool_name'] ?? $pending['tool_slug'] ?? '未知操作';
        $arguments = $pending['arguments'] ?? [];

        $lines = ["即将执行【{$toolName}】"];

        if (is_array($arguments) && $arguments !== []) {
            foreach ($arguments as $key => $value) {
                $display = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
                if (mb_strlen($display) > 80) {
                    $display = mb_substr($display, 0, 77) . '...';
                }
                $lines[] = "{$key}: {$display}";
            }
        }

        $ttlMinutes = (int) ceil(config('ai.ibot.confirm_ttl', 600) / 60);
        $lines[] = '';
        $lines[] = "回复\"确认\"执行，回复其他内容取消（{$ttlMinutes} 分钟内有效）。";

        return implode("\n", $lines);
    }

    /**
     * 清除会话 metadata 中的 pending 确认
     */
    private function clearPendingMeta(AgentConversation $conversation): void
    {
        $metadata = $conversation->metadata ?? [];
        unset($metadata['ibot_pending_confirm']);
        $conversation->update(['metadata' => $metadata]);
    }

    // ──────────────────────────────────────────────
    //  基础解析
    // ──────────────────────────────────────────────

    /**
     * 解析目标 agent：ibot 显式指定 > 系统小秘书
     */
    private function resolveAgent(Ibot $ibot): ?Agent
    {
        if ($ibot->agent_id) {
            $agent = Agent::where('agent_id', $ibot->agent_id)
                ->where('enabled', true)
                ->first();

            if ($agent) {
                return $agent;
            }
        }

        $secretary = Agent::where('tenant_id', $this->tenantId)
            ->where('role', 'system_secretary')
            ->where('enabled', true)
            ->first();

        // 懒开通：ibot 未绑定专属员工且秘书缺席时自动开通（与 console 首次打开同策略）
        if ($secretary === null && ! $ibot->agent_id) {
            $secretary = app(AgentProvisioningService::class)->ensureSecretary((int) $this->tenantId);
        }

        return $secretary;
    }

    /**
     * 复用绑定的承载会话；无效则新建并回写 binding.conversation_id
     */
    private function resolveConversation(OperatorIbotBinding $binding, int $agentId): AgentConversation
    {
        if ($binding->conversation_id) {
            $conversation = AgentConversation::where('conversation_id', $binding->conversation_id)
                ->where('agent_id', $agentId)
                ->first();

            if ($conversation) {
                return $conversation;
            }
        }

        $conversation = AgentConversation::create([
            'tenant_id' => $this->tenantId,
            'agent_id' => $agentId,
            'channel' => 'ibot',
            'subject' => '随身助理会话',
            'status' => 'active',
        ]);

        $binding->update(['conversation_id' => $conversation->conversation_id]);

        return $conversation;
    }
}
