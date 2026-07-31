<?php

namespace MultiTenantSaas\Modules\Campaign\Services\Tools;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Services\PlanCompiler;
use MultiTenantSaas\Modules\Campaign\Services\PlaybookRegistry;

/**
 * campaign_plan_draft — AI 共创计划方案（L1）
 *
 * 有 plan_id：取现有 CampaignPlan (status=planning) 的 plan_doc 作为修订基础
 * 无 plan_id + 有 playbook_key：取 playbook skeleton 作为初始骨架
 * 构建 prompt（methodology + user_input + 当前骨架）→ 独立 LLM 单次调用（JSON mode）
 * → 生成 plan_doc JSON → 存 DB
 *
 * 设计理由：存 DB 避免 commit 工具需要 LLM 传入巨量 JSON（贵且易错），
 * 多次 draft 修订自然版本化。
 *
 * fail-open：LLM 调用失败时返回错误提示但不抛异常。
 */
class CampaignPlanDraftTool implements ToolHandlerContract
{
    public function __construct(
        private readonly AiTextServiceContract $aiTextService,
        private readonly PlaybookRegistry $playbookRegistry,
        private readonly PlanCompiler $planCompiler,
    ) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $planId = (int) ($arguments['plan_id'] ?? 0);
        $playbookKey = (string) ($arguments['playbook_key'] ?? '');
        $userInput = (string) ($arguments['user_input'] ?? '');
        $anchorType = $arguments['anchor_type'] ?? null;
        $anchorId = $arguments['anchor_id'] ?? null;

        if ($userInput === '') {
            return ['error' => true, 'message' => '请提供 user_input 描述活动需求'];
        }

        // 1. 确定基础骨架
        $existingPlan = null;
        $currentDoc = null;
        $methodology = '';

        if ($planId > 0) {
            $existingPlan = CampaignPlan::where('plan_id', $planId)
                ->where('tenant_id', $tenantId)
                ->where('status', CampaignPlan::STATUS_PLANNING)
                ->first();

            if ($existingPlan === null) {
                return ['error' => true, 'message' => "计划 [{$planId}] 不存在或不在 planning 状态"];
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

        // 2. 构建 LLM prompt
        $prompt = $this->buildPrompt($userInput, $currentDoc, $methodology);

        // 3. 调用 LLM 生成 plan_doc（fail-open）
        $planDoc = $this->callLlm($prompt);

        if ($planDoc === null) {
            return [
                'error' => true,
                'message' => 'AI 生成计划方案失败，请重试或手动编写 plan_doc',
            ];
        }

        // 4. 存 DB
        if ($existingPlan !== null) {
            $existingPlan->update(['plan_doc' => $planDoc]);
            $savedPlanId = $existingPlan->plan_id;
        } else {
            $plan = CampaignPlan::create([
                'tenant_id' => $tenantId,
                'plan_doc' => $planDoc,
                'status' => CampaignPlan::STATUS_PLANNING,
                'playbook_key' => $playbookKey ?: null,
                'anchor_type' => $anchorType,
                'anchor_id' => $anchorId ? (int) $anchorId : null,
                'created_by' => 0, // 由 AI 创建
            ]);
            $savedPlanId = $plan->plan_id;
        }

        // 5. 即时校验：把问题暴露在 draft 阶段让 LLM 自愈修订，而非留到 commit 才拦截
        $validationErrors = $this->planCompiler->validate($planDoc);

        // 6. 返回预览
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
        ];

        if ($validationErrors !== []) {
            $result['hint'] = '计划存在校验问题，直接 commit 会失败。请再次调用 campaign_plan_draft'
                . '（带 plan_id 与针对上述问题的修订说明 user_input）修复后再定稿';
        }

        return $result;
    }

    private function buildPrompt(string $userInput, ?array $currentDoc, string $methodology): string
    {
        $parts = [];

        $parts[] = '你是一位活动策划专家。请根据用户需求生成或修订一份活动执行计划（JSON 格式，符合 campaign.plan/v1 schema）。';
        $parts[] = '';
        $parts[] = '## 输出格式要求';
        $parts[] = '严格输出 JSON 对象，schema 如下：';
        $parts[] = '```json';
        $parts[] = '{';
        $parts[] = '  "schema": "campaign.plan/v1",';
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
     * 独立 LLM 单次调用（JSON mode，fail-open）
     */
    private function callLlm(string $prompt): ?array
    {
        try {
            $response = $this->aiTextService->chat([
                ['role' => 'system', 'content' => 'You are a campaign planning assistant. Always output valid JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.4,
                'max_tokens' => 4000,
            ]);

            $content = trim($response->content);

            // 清理可能的 markdown 代码块包裹
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content);
                $content = preg_replace('/\s*```$/', '', $content);
            }

            $decoded = json_decode($content, true);

            if (! is_array($decoded) || ! isset($decoded['phases'])) {
                Log::warning('[CampaignPlanDraft] LLM 输出非法 JSON', ['content' => mb_substr($content, 0, 500)]);

                return null;
            }

            // 确保 schema 版本
            $decoded['schema'] = 'campaign.plan/v1';

            return $decoded;
        } catch (\Throwable $e) {
            Log::warning('[CampaignPlanDraft] LLM 调用失败（fail-open）', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
