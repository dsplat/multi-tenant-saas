<?php

namespace MultiTenantSaas\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\AgentRuntimeContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\DTOs\PageContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentConversationMessage;
use MultiTenantSaas\Modules\Ai\Services\Agent\ActionConfirmService;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;
use MultiTenantSaas\Modules\Ai\Services\Assistant\TenantSetupChecker;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI 小助手入口（主入口，非兜底）。
 *
 * 系统小助手（system_secretary）是租户运营人员的唯一交互主入口，
 * 全程可用、平台买单、不消耗租户额度。
 * 小助手通过 list_agents / delegate_to_agent / enable_agent 工具
 * 智能调度数字员工完成专业操作。
 *
 * 路由逻辑：
 * - 前端显式传 agent_id（转派后续接）→ 直达目标员工
 * - 否则 → 一律由系统小助手接手（主入口）
 *
 * @OA\Tag(
 *     name="AI 助手",
 *     description="AI 小助手（主入口 + 流式对话 + 数字员工调度）"
 * )
 */
class AssistantController extends Controller
{
    public function __construct(
        private AgentRuntimeContract $agentRuntime,
        private TenantContextContract $tenantContext,
        private ToolRegistryContract $toolRegistry,
        private ActionConfirmService $actionConfirm,
    ) {}

    /**
     * @OA\Post(
     *     path="/v1/ai/assistant",
     *     summary="AI 小助手（SSE 流式）",
     *     description="系统小助手为唯一主入口，智能调度数字员工完成操作。传 agent_id 可直达转派目标。",
     *     tags={"AI 助手"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"user_intent"},
     *
     *         @OA\Property(property="user_intent", type="string", example="帮我创建一个抽奖活动", description="用户自然语言意图"),
     *         @OA\Property(property="route", type="string", nullable=true, example="marketing.campaign.create", description="当前前端路由"),
     *         @OA\Property(property="module", type="string", nullable=true, example="Marketing", description="当前模块名"),
     *         @OA\Property(property="entity_type", type="string", nullable=true, example="campaign"),
     *         @OA\Property(property="entity_id", type="integer", nullable=true),
     *         @OA\Property(property="form_state", type="object", nullable=true, description="当前表单状态"),
     *         @OA\Property(property="visible_data_summary", type="string", nullable=true, description="页面可见数据摘要"),
     *         @OA\Property(property="conversation_id", type="integer", nullable=true, description="续接已有会话"),
     *         @OA\Property(property="agent_id", type="integer", nullable=true, description="转派后续接目标员工")
     *     )),
     *
     *     @OA\Response(response=200, description="SSE 流式响应"),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=503, description="小助手未初始化")
     * )
     */
    public function handle(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'route' => 'nullable|string|max:255',
            'module' => 'nullable|string|max:100',
            'user_intent' => 'required|string|max:32000',
            'entity_type' => 'nullable|string|max:100',
            'entity_id' => 'nullable|integer',
            'form_state' => 'nullable|array',
            'visible_data_summary' => 'nullable|string|max:10000',
            'conversation_id' => 'nullable|integer',
            'agent_id' => 'nullable|integer',
        ]);

        $tenantId = (int) $this->tenantContext->resolveId();

        // 构建页面上下文
        $pageContext = PageContext::fromArray($validated);

        // 路由策略：
        // 1. 前端显式传 agent_id（转派后续接）→ 直达目标员工
        // 2. 否则 → 系统小助手（主入口，全程可用）
        $agent = null;

        if (! empty($validated['agent_id'])) {
            $agent = Agent::where('tenant_id', $tenantId)
                ->where('agent_id', $validated['agent_id'])
                ->where('enabled', true)
                ->first();
        }

        // 主入口：系统小助手（不是兜底，是唯一入口）
        if (! $agent) {
            $agent = Agent::where('tenant_id', $tenantId)
                ->where('role', 'system_secretary')
                ->where('enabled', true)
                ->first();
        }

        if (! $agent) {
            return response()->json([
                'success' => false,
                'message' => 'AI 小助手尚未初始化，请联系平台管理员执行 secretary:install。',
            ], 503);
        }

        // 获取或创建会话
        $conversation = $this->resolveConversation($tenantId, $agent->agent_id, $validated['conversation_id'] ?? null);

        // 组装消息（注入页面上下文）
        $message = $this->buildMessage($pageContext);

        // 流式响应
        return $this->streamResponse($agent->agent_id, $conversation->conversation_id, $message);
    }

    /**
     * 获取或创建会话。
     */
    private function resolveConversation(int $tenantId, int $agentId, ?int $conversationId): AgentConversation
    {
        if ($conversationId) {
            $conversation = AgentConversation::where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->where('agent_id', $agentId)
                ->first();

            if ($conversation) {
                return $conversation;
            }
        }

        return AgentConversation::create([
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'channel' => 'assistant',
            'subject' => '页面助手会话',
            'status' => 'active',
        ]);
    }

    /**
     * 组装带页面上下文的消息。
     */
    private function buildMessage(PageContext $ctx): string
    {
        $contextBlock = $ctx->toPromptContext();

        return "[页面上下文]\n{$contextBlock}\n\n[用户请求]\n{$ctx->userIntent}";
    }

    /**
     * 从落库的完整 prompt 中提取用户原话（buildMessage 的逆操作）。
     */
    private function extractUserIntent(string $content): string
    {
        if (preg_match('/\[用户请求\]\n(.*)$/su', $content, $m)) {
            return trim($m[1]);
        }

        return $content;
    }

    /**
     * 检查 AI 小助手可用性（平台级开关）。
     *
     * 小助手是平台级预配置、全程可用的主入口，
     * 仅当平台管理员显式关闭 secretary 时才不可用。
     *
     * GET /v1/ai/assistant/availability
     */
    public function availability(Request $request): JsonResponse
    {
        // 平台级开关：config('ai.secretary.enabled')
        // 小助手全程可用，不依赖租户配置或数字员工是否启用
        $available = config('ai.secretary.enabled', true);

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $available,
            ],
        ]);
    }

    /**
     * 会话历史（刷新恢复用）。
     *
     * GET /v1/ai/assistant/history?conversation_id=X&limit=50
     * 前端刷新后凭 localStorage 中的 conversation_id 拉取历史消息恢复面板。
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|integer',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $tenantId = (int) $this->tenantContext->resolveId();

        $conversation = AgentConversation::where('tenant_id', $tenantId)
            ->where('conversation_id', $validated['conversation_id'])
            ->first();

        if (! $conversation) {
            return response()->json([
                'success' => false,
                'message' => '会话不存在或已过期。',
            ], 404);
        }

        $limit = (int) ($validated['limit'] ?? 50);

        // 只取用户可见轮次（过滤 tool 结果与空的工具调用轮次），倒序取最近 N 条后正序返回
        // 注：message_id 为全局 ID（非单调递增），排序以 created_at 为准
        $messages = AgentConversationMessage::where('conversation_id', $conversation->conversation_id)
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('message_id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'message_id' => $m->message_id,
                'role' => $m->role,
                // user 轮次落库为完整 prompt（含页面上下文包装），恢复时只回显用户原话
                'content' => $m->role === 'user' ? $this->extractUserIntent((string) $m->content) : (string) $m->content,
                'created_at' => $m->created_at?->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'conversation_id' => $conversation->conversation_id,
                'agent_id' => $conversation->agent_id,
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * 小助手历史会话列表（多会话管理）。
     *
     * GET /v1/ai/assistant/conversations?page=1&per_page=20
     * 仅返回本租户 channel=assistant 的会话，按最近活跃倒序分页。
     */
    public function conversations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $tenantId = (int) $this->tenantContext->resolveId();

        $paginator = AgentConversation::where('tenant_id', $tenantId)
            ->where('channel', 'assistant')
            ->orderByDesc('updated_at')
            ->paginate(
                (int) ($validated['per_page'] ?? 20),
                ['*'],
                'page',
                (int) ($validated['page'] ?? 1),
            );

        return response()->json([
            'success' => true,
            'data' => [
                'conversations' => collect($paginator->items())->map(fn ($c) => [
                    'conversation_id' => (int) $c->conversation_id,
                    'agent_id' => (int) $c->agent_id,
                    'subject' => $c->subject,
                    'status' => $c->status,
                    'updated_at' => $c->updated_at?->toISOString(),
                ])->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    /**
     * 删除小助手会话（连同消息）。
     *
     * DELETE /v1/ai/assistant/conversations/{conversationId}
     * 仅限本租户 channel=assistant 的会话，防止越权删除数字员工业务会话。
     */
    public function deleteConversation(Request $request, int $conversationId): JsonResponse
    {
        $tenantId = (int) $this->tenantContext->resolveId();

        $conversation = AgentConversation::where('tenant_id', $tenantId)
            ->where('channel', 'assistant')
            ->where('conversation_id', $conversationId)
            ->first();

        if (! $conversation) {
            return response()->json([
                'success' => false,
                'message' => '会话不存在或不属于当前团队。',
            ], 404);
        }

        AgentConversationMessage::where('conversation_id', $conversationId)->delete();
        $conversation->delete();

        return response()->json([
            'success' => true,
            'message' => '会话已删除。',
        ]);
    }

    /**
     * 新会话开场引导（建议推荐 + 设置完善度）。
     *
     * GET /v1/ai/assistant/suggestions?route=/customers&module=Customer
     * 返回四块：
     *  - page_suggestions   按当前页面路由规则匹配的建议话术
     *  - history_suggestions 最近会话主题（继续聊入口）
     *  - task_chains         预设任务链（引擎就位前返回空数组，见 docs/task-chain.md）
     *  - setup_checklist     租户设置完善度（仅 tenant_admin 返回）
     */
    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'route' => 'nullable|string|max:255',
            'module' => 'nullable|string|max:100',
        ]);

        $tenantId = (int) $this->tenantContext->resolveId();

        $data = [
            'page_suggestions' => $this->pageSuggestions((string) ($validated['route'] ?? '')),
            'history_suggestions' => $this->historySuggestions($tenantId),
            // 预设任务链契约先行：引擎实现前固定空数组（docs/task-chain.md）
            'task_chains' => [],
            'setup_checklist' => null,
        ];

        // 设置完善度仅对团队管理员可见（复用代操作权限切面，非管理员不报错只省略）
        if ($this->ensureOperatorCanExecute($request, $tenantId) === null) {
            $data['setup_checklist'] = app(TenantSetupChecker::class)->checklist($tenantId);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * 页面感知建议：按路由前缀最长匹配规则表，未命中返回通用建议。
     *
     * 路由取值以 KB 路由地图（ConsoleRouteMapGenerator 产出）为准。
     *
     * @return list<string>
     */
    private function pageSuggestions(string $route): array
    {
        $rules = [
            '/customers' => ['帮我给最近 7 天新增的客户打标签', '分析当前客户列表的画像分布'],
            '/marketing' => ['帮我策划一个拉新活动', '看看正在进行的活动效果如何'],
            '/analytics' => ['本周经营数据帮我分析一下', '生成一份本月运营周报'],
            '/agents' => ['带我看看有哪些数字员工', '帮我启用合适的数字员工'],
            '/members' => ['邀请一位新成员加入团队', '给新成员分配合适的角色权限'],
            '/external-kb' => ['帮我检查知识库连接状态', '知识库里有哪些内容可以用？'],
            '/dashboard' => ['带我熟悉一下系统功能', '今天有哪些重点数据需要关注？'],
        ];

        $matched = null;
        $matchedLength = 0;

        foreach ($rules as $prefix => $suggestions) {
            if (str_starts_with($route, $prefix) && strlen($prefix) > $matchedLength) {
                $matched = $suggestions;
                $matchedLength = strlen($prefix);
            }
        }

        return $matched ?? ['带我熟悉一下系统功能', '有哪些数字员工可以帮我干活？', '帮我看看还有哪些设置没完成'];
    }

    /**
     * 历史会话主题（最近 5 条，供继续聊入口）。
     *
     * @return list<array{conversation_id: int, subject: ?string, updated_at: ?string}>
     */
    private function historySuggestions(int $tenantId): array
    {
        return AgentConversation::where('tenant_id', $tenantId)
            ->where('channel', 'assistant')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'conversation_id' => (int) $c->conversation_id,
                'subject' => $c->subject,
                'updated_at' => $c->updated_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * 确认并执行 L2 待确认操作（AI 代操作的人类确认点）。
     *
     * POST /v1/ai/assistant/confirm-action
     * 校验 confirm_token + 参数哈希 + 会话归属 + Operator RBAC，
     * 通过后以当前登录 Operator 身份经 ToolRegistry 执行工具、写审计、
     * 结果以 role=tool 入会话并让 LLM 续答。取消路径同样消费令牌使其作废。
     */
    public function confirmAction(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:128',
            'conversation_id' => 'required|integer',
            'args_hash' => 'required|string|max:128',
            'confirmed' => 'required|boolean',
        ]);

        $tenantId = (int) $this->tenantContext->resolveId();
        $conversationId = (int) $validated['conversation_id'];

        // 会话归属校验
        $conversation = AgentConversation::where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->first();

        if (! $conversation) {
            return response()->json(['success' => false, 'message' => '会话不存在或已过期。'], 404);
        }

        // 权限切面：以当前 Operator 身份行事，绝不提权
        if ($deny = $this->ensureOperatorCanExecute($request, $tenantId)) {
            return $deny;
        }

        // 一次性消费令牌（确认与取消共用；不存在/过期/归属或哈希不符抛异常）
        try {
            $payload = $this->actionConfirm->consume(
                $validated['token'], $tenantId, $conversationId, $validated['args_hash'],
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $toolSlug = (string) ($payload['tool_slug'] ?? '');
        $arguments = is_array($payload['arguments'] ?? null) ? $payload['arguments'] : [];
        $toolCallId = $payload['tool_call_id'] ?? null;

        // 取消路径：令牌已作废，回传取消结果让 LLM 收尾
        if (! $validated['confirmed']) {
            app(AuditService::class)->log('ai_action_cancel', 'agent_tool', null, [
                'tool_slug' => $toolSlug,
                'arguments' => $arguments,
                'conversation_id' => $conversationId,
            ], ['cancelled' => true]);

            $response = $this->agentRuntime->continueWithToolResults($conversationId, [[
                'tool_name' => $toolSlug,
                'tool_call_id' => $toolCallId,
                'content' => json_encode(['cancelled' => true, 'message' => '用户已取消该操作'], JSON_UNESCAPED_UNICODE),
            ]]);

            return response()->json([
                'success' => true,
                'data' => [
                    'executed' => false,
                    'cancelled' => true,
                    'assistant_message' => $response->message ?? '',
                    'conversation_id' => $conversationId,
                ],
            ]);
        }

        // 确认路径：以服务端存储的参数执行（不信任前端回传）
        $startTime = microtime(true);
        $error = null;
        $result = null;

        try {
            $result = $this->toolRegistry->execute($toolSlug, $arguments, $tenantId);
            if (is_array($result) && ($result['error'] ?? false)) {
                $error = $result['message'] ?? '工具执行失败';
                $result = null;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        app(AuditService::class)->log('ai_action_execute', 'agent_tool', null, [
            'tool_slug' => $toolSlug,
            'arguments' => $arguments,
            'conversation_id' => $conversationId,
        ], [
            'success' => $error === null,
            'result' => $result,
            'error' => $error,
            'duration_ms' => $durationMs,
        ]);

        $toolResultContent = $error !== null
            ? json_encode(['error' => $error], JSON_UNESCAPED_UNICODE)
            : (is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE));

        $response = $this->agentRuntime->continueWithToolResults($conversationId, [[
            'tool_name' => $toolSlug,
            'tool_call_id' => $toolCallId,
            'content' => $toolResultContent,
        ]]);

        return response()->json([
            'success' => $error === null,
            'data' => [
                'executed' => $error === null,
                'error' => $error,
                'assistant_message' => $response->message ?? '',
                'conversation_id' => $conversationId,
            ],
        ]);
    }

    /**
     * 权限切面：确认执行前校验当前 Operator 对该租户的 console 写权限。
     *
     * 参照 CheckPermission::checkConsoleAccess：仅 operator_tenants 活跃关联
     * 且角色为 tenant_admin 的 Operator 可执行代操作。绝不提权、绝不放行 User。
     *
     * @return JsonResponse|null null 表示放行，否则为 403 拒绝响应
     */
    private function ensureOperatorCanExecute(Request $request, int $tenantId): ?JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof Operator)) {
            return response()->json(['success' => false, 'message' => '无权执行该操作。'], 403);
        }

        $operatorTenant = DB::table('operator_tenants')
            ->where('operator_id', $user->operator_id)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if (! $operatorTenant) {
            return response()->json(['success' => false, 'message' => '当前账号不属于该团队。'], 403);
        }

        $tenantAdminRoleId = DB::table('roles')
            ->where('name', 'tenant_admin')
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->value('role_id');

        if ($operatorTenant->role_id !== $tenantAdminRoleId) {
            return response()->json(['success' => false, 'message' => '仅团队管理员可执行代操作。'], 403);
        }

        return null;
    }

    /**
     * SSE 流式响应。
     *
     * 协议（data: JSON\n\n）：
     *  - {"type":"meta","content":{...}}        会话元信息（conversation_id/agent_id，首帧下发）
     *  - {"type":"text","content":"..."}        增量文本
     *  - {"type":"tool_call","content":[...]}   工具调用决策（前端展示“正在调用 XX”）
     *  - {"type":"done","metadata":{...}}       流结束
     *  - ": ping"                               心跳注释帧（工具执行等静默期维持连接，前端自动忽略）
     */
    private function streamResponse(int $agentId, int $conversationId, string $message): StreamedResponse
    {
        return response()->stream(function () use ($agentId, $conversationId, $message) {
            // 首帧下发会话元信息，前端持久化 conversation_id 以支持刷新续接
            $this->emit(['type' => 'meta', 'content' => ['conversation_id' => $conversationId, 'agent_id' => $agentId]]);

            $generator = $this->agentRuntime->runStream($agentId, $conversationId, $message);

            foreach ($generator as $chunk) {
                if (! $chunk instanceof StreamChunk) {
                    continue;
                }

                // 心跳帧：以 SSE 注释行推送字节维持连接（防 nginx/FPM 判死），前端解析器自动忽略
                if ($chunk->isHeartbeat()) {
                    echo ": ping\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();

                    continue;
                }

                // 增量文本
                if ($chunk->text !== '') {
                    $this->emit(['type' => 'text', 'content' => $chunk->text]);
                }

                // L2 工具待确认 → 下发确认卡片
                if ($chunk->hasPendingConfirmation()) {
                    $this->emit(['type' => 'pending_confirmation', 'content' => $chunk->pendingConfirmation]);
                }

                // 工具调用决策
                if ($chunk->hasToolCalls()) {
                    // 检测 suggest_form_fill 工具 → 转为 form_fill 类型
                    $formFill = $this->extractFormFill($chunk->toolCalls);
                    if ($formFill) {
                        $this->emit(['type' => 'form_fill', 'content' => $formFill]);
                    } elseif ($workflow = $this->extractWorkflow($chunk->toolCalls)) {
                        $this->emit(['type' => 'workflow', 'content' => $workflow]);
                    } else {
                        $this->emit(['type' => 'tool_call', 'content' => $chunk->toolCalls]);
                    }
                }

                // 流结束
                if ($chunk->isFinished()) {
                    $this->emit(['type' => 'done', 'content' => '', 'metadata' => ['finish_reason' => $chunk->finishReason]]);
                }
            }

            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * 输出单个 SSE 事件。
     */
    private function emit(array $payload): void
    {
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * 从工具调用中提取 suggest_form_fill 结果。
     * Agent 调用此工具时，参数即为表单填充建议。
     */
    private function extractFormFill(array $toolCalls): ?array
    {
        foreach ($toolCalls as $tc) {
            [$slug, $args] = $this->normalizeToolCall($tc);
            if ($slug === 'suggest_form_fill') {
                return [
                    'fields' => $args['fields'] ?? $args,
                    'explanation' => $args['explanation'] ?? null,
                    'field_notes' => $args['field_notes'] ?? null,
                    'confidence' => $args['confidence'] ?? 0.8,
                ];
            }
        }

        return null;
    }

    /**
     * 归一化单个工具调用（兼容 OpenAI function.name/arguments 与早期 slug/name 格式）。
     *
     * @return array{0: string, 1: array} [slug, arguments]
     */
    private function normalizeToolCall(mixed $tc): array
    {
        if (is_object($tc)) {
            $tc = (array) $tc;
        }
        if (! is_array($tc)) {
            return ['', []];
        }

        $fn = $tc['function'] ?? null;
        if (is_object($fn)) {
            $fn = (array) $fn;
        }

        $slug = (is_array($fn) ? ($fn['name'] ?? null) : null) ?? $tc['slug'] ?? $tc['name'] ?? '';

        $args = (is_array($fn) ? ($fn['arguments'] ?? null) : null) ?? $tc['arguments'] ?? [];
        if (is_string($args)) {
            $args = json_decode($args, true) ?: [];
        }
        if (! is_array($args)) {
            $args = [];
        }

        return [(string) $slug, $args];
    }

    /**
     * 从工具调用中提取 suggest_workflow 结果。
     * Agent 调用此工具时，参数即为工作流编排建议。
     */
    private function extractWorkflow(array $toolCalls): ?array
    {
        foreach ($toolCalls as $tc) {
            [$slug, $args] = $this->normalizeToolCall($tc);
            if ($slug === 'suggest_workflow') {
                return [
                    'name' => $args['name'] ?? '未命名流程',
                    'steps' => $args['steps'] ?? [],
                    'submit_endpoint' => $args['submit_endpoint'] ?? null,
                    'submit_payload' => $args['submit_payload'] ?? null,
                    'explanation' => $args['explanation'] ?? null,
                ];
            }
        }

        return null;
    }
}
