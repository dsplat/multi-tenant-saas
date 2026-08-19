<?php

namespace MultiTenantSaas\Modules\ActivityPlan\Services\Tools;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Ai\Models\AiTask;
use MultiTenantSaas\Modules\Ai\Services\AiTask\AiTaskHandlerContract;
use MultiTenantSaas\Modules\ActivityPlan\Models\ActivityPlan;
use MultiTenantSaas\Modules\ActivityPlan\Services\PlanCompiler;
use MultiTenantSaas\Modules\ActivityPlan\Services\PlaybookRegistry;

/**
 * activity_plan_draft 任务处理器（queue worker 内执行）
 *
 * 由 ActivityPlanDraftTool 提交 AiTask 后 dispatch ExecuteAiTaskJob，
 * worker 恢复租户上下文调用本处理器：骨架解析 → LLM 生成 plan_doc（JSON mode）
 * → 存 DB → 即时校验 → 返回预览结果。后台执行不受连接超时约束。
 *
 * 失败抛异常，由 ExecuteAiTaskJob 统一落库 failed + error。
 */
class ActivityPlanDraftTaskHandler implements AiTaskHandlerContract
{
    /**
     * 单次方案生成 HTTP 超时（秒）：后台任务不受连接约束，显式放宽。
     * 平台默认 AI_TIMEOUT（生产 30s）对重模型全量生成不够，超时致任务
     * failed，前端表现为「先失败一次再重试成功」。
     */
    private const LLM_TIMEOUT_SECONDS = 180;

    public function __construct(
        private readonly AiTextServiceContract $aiTextService,
        private readonly PlaybookRegistry $playbookRegistry,
        private readonly PlanCompiler $planCompiler,
    ) {}

    public function handle(AiTask $task): array
    {
        $payload = (array) $task->payload;
        $tenantId = (int) $task->tenant_id;
        $planId = (int) ($payload['plan_id'] ?? 0);
        $playbookKey = (string) ($payload['playbook_key'] ?? '');
        $userInput = (string) ($payload['user_input'] ?? '');
        $anchorType = $payload['anchor_type'] ?? null;
        $anchorId = $payload['anchor_id'] ?? null;

        if ($userInput === '') {
            throw new DomainException('缺少 user_input，无法生成计划方案');
        }

        // 1. 确定基础骨架
        $existingPlan = null;
        $currentDoc = null;
        $methodology = '';

        if ($planId > 0) {
            $existingPlan = ActivityPlan::where('plan_id', $planId)
                ->where('tenant_id', $tenantId)
                ->where('status', ActivityPlan::STATUS_PLANNING)
                ->first();

            if ($existingPlan === null) {
                throw new NotFoundException("计划 [{$planId}] 不存在或不在 planning 状态");
            }

            $currentDoc = $existingPlan->plan_doc;
            $playbookKey = $existingPlan->playbook_key ?? $playbookKey;
        }

        if ($playbookKey !== '') {
            $playbook = $this->playbookRegistry->find($playbookKey);
            if ($playbook !== null) {
                $methodology = (string) ($playbook['methodology'] ?? '');
                if ($currentDoc === null) {
                    $currentDoc = $playbook['skeleton'] ?? null;
                }
            }
        }

        // 2. 构建 prompt → LLM 生成 plan_doc
        $prompt = $this->buildPrompt($userInput, $currentDoc, $methodology);
        $planDoc = $this->callLlm($prompt);

        if ($planDoc === null) {
            throw new ServiceUnavailableException('AI 生成计划方案失败，请重试或手动编写 plan_doc');
        }

        // 3. 存 DB
        if ($existingPlan !== null) {
            $existingPlan->update(['plan_doc' => $planDoc]);
            $savedPlanId = $existingPlan->plan_id;
        } else {
            $plan = ActivityPlan::create([
                'tenant_id' => $tenantId,
                'plan_doc' => $planDoc,
                'status' => ActivityPlan::STATUS_PLANNING,
                'playbook_key' => $playbookKey ?: null,
                'anchor_type' => $anchorType,
                'anchor_id' => $anchorId ? (int) $anchorId : null,
                'created_by' => 0, // 由 AI 创建
            ]);
            $savedPlanId = $plan->plan_id;
        }

        // 4. 即时校验：把问题暴露在 draft 阶段让 LLM 自愈修订，而非留到 commit 才拦截
        $validationErrors = $this->planCompiler->validate($planDoc);

        // 5. 返回预览（与任务化前工具返回结构一致，LLM 续答无感）
        $phases = $planDoc['phases'] ?? [];
        $taskCount = 0;
        foreach ($phases as $phase) {
            $taskCount += count($phase['tasks'] ?? []);
        }

        $result = [
            'plan_id' => $savedPlanId,
            'plan_doc_preview' => [
                'title' => $planDoc['title'] ?? '（未命名）',
                'phases_count' => count($phases),
                'tasks_count' => $taskCount,
                'phases' => array_map(fn ($p) => [
                    'key' => $p['key'] ?? '',
                    'title' => $p['title'] ?? '',
                    'tasks_count' => count($p['tasks'] ?? []),
                ], $phases),
            ],
            'validation_errors' => $validationErrors,
            // commit 时 anchor_times 需覆盖的全部锚点（提前告知，避免定稿时才发现缺失）
            'required_anchors' => $this->planCompiler->collectRequiredAnchors($planDoc),
        ];

        if ($validationErrors !== []) {
            $result['hint'] = '计划存在校验问题，直接 commit 会失败。请再次调用 activity_plan_draft'
                . '（带 plan_id 与针对上述问题的修订说明 user_input）修复后再定稿';
        }

        // 表述锁（确定性事实源）：draft 后的播报要点与锚点追问方式，不依赖模型自由发挥
        $result['next_action'] = '向用户转述方案要点（阶段/任务数/时间周期），并务必在正文写明 plan_id 字段中的真实计划编号（长数字，定稿只能用它）。'
            . ($result['required_anchors'] !== []
                ? 'required_anchors 列出的锚点需要具体时间：用用户听得懂的话追问（如「活动几点开始？」），绝不向用户提及锚点名等内部标识；拿到时间后在用户确认满意时 commit 一次性传入 anchor_times。'
                : '')
            . '等用户明确表示满意后才可 commit；严禁同一轮内 draft+commit 连做；'
            . '用 ask_user_choice 征询是否满意时，该轮严禁同时调用 commit（系统会拦截并报错）。';

        // 断连兜底落库会话用的人性化摘要
        $result['summary'] = sprintf(
            '活动计划《%s》方案已生成（计划编号 %s，%d 个阶段 / %d 个任务），可继续对话查看与定稿。',
            $planDoc['title'] ?? '未命名',
            $savedPlanId,
            count($phases),
            $taskCount
        );

        return $result;
    }

    private function buildPrompt(string $userInput, ?array $currentDoc, string $methodology): string
    {
        $parts = [];

        $parts[] = '你是一位活动策划专家。请根据用户需求生成或修订一份活动执行计划（JSON 格式，符合 activity.plan/v1 schema）。';
        $parts[] = '';
        $parts[] = '## 输出格式要求';
        $parts[] = '严格输出 JSON 对象，schema 如下：';
        $parts[] = '```json';
        $parts[] = '{';
        $parts[] = '  "schema": "activity.plan/v1",';
        $parts[] = '  "title": "计划标题",';
        $parts[] = '  "phases": [';
        $parts[] = '    {';
        $parts[] = '      "key": "phase_key",';
        $parts[] = '      "title": "阶段标题",';
        $parts[] = '      "tasks": [';
        $parts[] = '        {';
        $parts[] = '          "key": "task_key",';
        $parts[] = '          "title": "任务标题",';
        $parts[] = '          "trigger": {"type": "relative|at_time|on_event|recurring", ...},';
        $parts[] = '          "action": {"type": "tool|task_chain|agent_react|human", ...},';
        $parts[] = '          "execution_mode": "auto|require_confirm",';
        $parts[] = '          "depends_on": []';
        $parts[] = '        }';
        $parts[] = '      ]';
        $parts[] = '    }';
        $parts[] = '  ]';
        $parts[] = '}';
        $parts[] = '```';
        $parts[] = '';
        $parts[] = '## action.type 硬性规则';
        $parts[] = '- "tool"：必须同时提供 "tool" 字段且值为系统已注册的工具 slug；不确定有哪些工具时禁用此类型';
        $parts[] = '- "human"：人工待办（到点通知操作人执行），没有合适工具的任务一律用 human，这是默认选择';
        $parts[] = '- trigger.type=relative 必须带 anchor 与 offset；recurring 必须带 from/until/interval';
        $parts[] = '- 锚点纪律：全计划统一只用一个锚点名 "event.starts_at"（活动开始时间），'
            . '其他时点用 offset 相对表达（如开营前3天 = anchor "event.starts_at" + offset "-3d"）；'
            . '禁止自造多个锚点名，除非用户明确提供了多个独立日期';

        if ($methodology !== '') {
            $parts[] = '';
            $parts[] = '## 方法论参考';
            $parts[] = $methodology;
        }

        if ($currentDoc !== null) {
            $parts[] = '';
            $parts[] = '## 当前计划骨架（请在此基础上修改或扩展）';
            $parts[] = '```json';
            $parts[] = json_encode($currentDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $parts[] = '```';
        }

        $parts[] = '';
        $parts[] = '## 用户需求';
        $parts[] = $userInput;
        $parts[] = '';
        $parts[] = '请直接输出 JSON，不要包含 markdown 代码块标记或其他解释文字。';

        return implode("\n", $parts);
    }

    /**
     * 独立 LLM 单次调用（JSON mode）；失败返回 null 由上层抛异常。
     * 瞬时失败（超时/网络/5xx）自动重试一次：ExecuteAiTaskJob tries=1
     * 不在队列层重试，瞬时抖动不应直接暴露成用户可见的任务失败。
     */
    private function callLlm(string $prompt): ?array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $this->aiTextService->chat([
                    ['role' => 'system', 'content' => 'You are an activity planning assistant. Always output valid JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ], [
                    'temperature' => 0.4,
                    'max_tokens' => 4000,
                    'timeout' => self::LLM_TIMEOUT_SECONDS,
                ]);

                $content = trim($response->content);

                // 清理可能的 markdown 代码块包裹
                if (str_starts_with($content, '```')) {
                    $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                    $content = preg_replace('/\s*```$/', '', $content);
                }

                $decoded = json_decode($content, true);

                if (! is_array($decoded) || ! isset($decoded['phases'])) {
                    Log::warning('[ActivityPlanDraft] LLM 输出非法 JSON', ['content' => mb_substr($content, 0, 500)]);

                    return null;
                }

                // 确保 schema 版本
                $decoded['schema'] = 'activity.plan/v1';

                return $decoded;
            } catch (\Throwable $e) {
                Log::warning('[ActivityPlanDraft] LLM 调用失败', ['error' => $e->getMessage(), 'attempt' => $attempt]);

                if ($attempt >= 2) {
                    return null;
                }
                sleep(2);
            }
        }

        return null;
    }
}
