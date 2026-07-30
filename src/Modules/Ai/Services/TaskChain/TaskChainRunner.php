<?php

namespace MultiTenantSaas\Modules\Ai\Services\TaskChain;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Models\TaskChainRun;
use MultiTenantSaas\Modules\Ai\Services\Agent\Dto\Tool;
use MultiTenantSaas\Modules\Ai\Services\Agent\HeadlessAgentService;

/**
 * 任务链执行器（docs/task-chain.md 第五节，Phase 1：tool / input 步）
 *
 * 执行语义：
 * - input 步：等待用户提供 step_input（waiting_input），提交后并入链上下文推进；
 * - tool 步（L1）：占位符解析 args 后经 ToolRegistry::execute 直接执行；
 * - tool 步（L2）：**不直接执行**（绕过确认门是阻断性缺陷），置 waiting_confirm
 *   并通过 next_action 指示 LLM 直接调用该 L2 工具（走 AgentRuntime 既有拦截
 *   确认门），完成后 advance 提交 step_output 记录推进；
 * - delegate / upload 步：Phase 2 实现，当前置 failed 并提示。
 *
 * failed 步保留 current_step 现场，可重复 advance 重试；optional 步可 skip。
 */
class TaskChainRunner
{
    /** 单值超过此字节数时不入 context，仅存摘要引用 */
    private const MAX_CONTEXT_VALUE_BYTES = 16384;

    public function __construct(
        private readonly TaskChainRegistry $registry,
        private readonly ToolRegistryContract $toolRegistry,
        private readonly HeadlessAgentService $headless,
    ) {}

    /**
     * 启动一条链：建 run（步骤快照全 pending），按 step0 类型给出 next_action（不执行）
     *
     * @return array<string, mixed> 统一状态视图；链不存在时 {error: true, message}
     */
    public function start(string $chainKey, int $tenantId, ?int $conversationId, bool $forceL2 = false): array
    {
        $chain = $this->registry->find($chainKey);

        if ($chain === null) {
            return ['error' => true, 'message' => "任务链 [{$chainKey}] 不存在，可先调用 list_task_chains 查看可用链"];
        }

        $run = TaskChainRun::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'chain_key' => $chainKey,
            'steps_state' => [
                'steps' => array_map(fn (array $step) => [
                    'name' => (string) $step['name'],
                    'type' => (string) $step['type'],
                    'status' => 'pending',
                    'output_key' => $step['output_key'],
                ], $chain['steps']),
                'context' => [],
            ],
            'current_step' => 0,
            'status' => TaskChainRun::STATUS_RUNNING,
        ]);

        return $this->prepareCurrentStep($run, $chain, $forceL2);
    }

    /**
     * 推进链：按当前步类型分派（提交输入 / 执行 L1 工具 / 记录 L2 结果 / 跳过 optional 步）
     *
     * @param  array<string, mixed>  $stepInput  input 步的用户输入
     * @param  array<string, mixed>  $stepOutput  L2 工具步经确认门执行后的结果回填
     * @return array<string, mixed> 统一状态视图
     */
    public function advance(int $runId, int $tenantId, array $stepInput = [], array $stepOutput = [], bool $skip = false, bool $forceL2 = false): array
    {
        /** @var TaskChainRun|null $run */
        $run = TaskChainRun::where('run_id', $runId)->where('tenant_id', $tenantId)->first();

        if ($run === null) {
            return ['error' => true, 'message' => "任务链运行 [{$runId}] 不存在"];
        }

        if ($run->isFinished()) {
            return $this->view($run, '该任务链已结束，无需推进');
        }

        $chain = $this->registry->find($run->chain_key);

        if ($chain === null) {
            return ['error' => true, 'message' => "链定义 [{$run->chain_key}] 已不可用，无法推进"];
        }

        $step = $chain['steps'][$run->current_step] ?? null;

        if ($step === null) {
            // 步下标越界视为已完成（防御）
            return $this->finish($run);
        }

        if ($skip) {
            if (! $step['optional']) {
                return $this->view($run, "第 {$run->current_step} 步 [{$step['name']}] 不是可选步，不能跳过");
            }

            return $this->completeStep($run, $chain, $step, null, 'skipped');
        }

        return match ($step['type']) {
            'input' => $this->advanceInputStep($run, $chain, $step, $stepInput),
            'tool' => $this->advanceToolStep($run, $chain, $step, $stepOutput, $tenantId, $forceL2),
            'delegate' => $this->advanceDelegateStep($run, $chain, $step, $stepOutput, $tenantId),
            'upload' => $this->advanceUploadStep($run, $chain, $step, $stepInput),
            default => $this->failStep($run, $step, "步骤类型 [{$step['type']}] 不支持"),
        };
    }

    /**
     * 当前会话未完成的运行实例（供 list_task_chains 展示可续跑的链）
     *
     * @return list<array<string, mixed>>
     */
    public function unfinishedRuns(int $tenantId, int $conversationId): array
    {
        return TaskChainRun::where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->whereIn('status', TaskChainRun::UNFINISHED_STATUSES)
            ->orderByDesc('run_id')
            ->get()
            ->map(fn (TaskChainRun $run) => $this->view($run))
            ->all();
    }

    /**
     * input 步：无输入 → waiting_input 指引；有输入 → 并入 context 后推进
     */
    private function advanceInputStep(TaskChainRun $run, array $chain, array $step, array $stepInput): array
    {
        if ($stepInput === []) {
            $run->status = TaskChainRun::STATUS_WAITING_INPUT;
            $this->setStepStatus($run, $run->current_step, 'waiting');
            $run->save();

            $schema = $step['input_schema'] !== null ? json_encode($step['input_schema'], JSON_UNESCAPED_UNICODE) : '（自由文本）';

            return $this->view($run, "请向用户收集本步所需信息（schema: {$schema}），然后调用 advance_task_chain 以 step_input 提交");
        }

        return $this->completeStep($run, $chain, $step, $stepInput);
    }

    /**
     * tool 步：L1 直接执行；L2 走确认门（等 step_output 回填）
     */
    private function advanceToolStep(TaskChainRun $run, array $chain, array $step, array $stepOutput, int $tenantId, bool $forceL2 = false): array
    {
        // L2 步已由 LLM 经确认门执行完毕，本次调用只是回填结果推进
        if ($stepOutput !== []) {
            return $this->completeStep($run, $chain, $step, $stepOutput);
        }

        $slug = (string) $step['tool'];
        $tool = $this->toolRegistry->get($slug);

        if ($tool === null) {
            return $this->failStep($run, $step, "工具 [{$slug}] 未注册，本步无法执行");
        }

        $args = $this->resolvePlaceholders($step['args'], $this->context($run));

        if ($tool->risk === Tool::RISK_L2 && ! $forceL2) {
            // 铁律：L2 工具不得由 Runner 直接执行（会绕过确认卡片）
            $run->status = TaskChainRun::STATUS_WAITING_CONFIRM;
            $this->setStepStatus($run, $run->current_step, 'waiting');
            $run->save();

            $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE);

            return $this->view($run, "本步需用户确认：请直接调用工具 [{$slug}]（参数建议：{$argsJson}）执行，用户确认完成后调用 advance_task_chain 以 step_output 提交执行结果");
        }

        // L1 或 forceL2 → 直接执行
        $result = $this->toolRegistry->execute($slug, $args, $tenantId);

        if (is_array($result) && ($result['error'] ?? false) === true) {
            return $this->failStep($run, $step, "工具 [{$slug}] 执行失败：" . ($result['message'] ?? '未知错误'));
        }

        return $this->completeStep($run, $chain, $step, $result);
    }

    /**
     * 完成当前步：结果入 context（超限存摘要）、步置 done、推进或收尾
     *
     * input 步的 step_input 按 key 直接 merge 进 context（schema 各属性即上下文 key）；
     * 其余步型的结果存入 output_key。
     */
    private function completeStep(TaskChainRun $run, array $chain, array $step, mixed $output, string $stepStatus = 'done'): array
    {
        $state = $run->steps_state;

        if ($step['type'] === 'input' && is_array($output)) {
            foreach ($output as $key => $value) {
                $state['context'][$key] = $this->clampContextValue($value);
            }
        } elseif ($step['output_key'] !== null && $output !== null) {
            $state['context'][$step['output_key']] = $this->clampContextValue($output);
        }

        $state['steps'][$run->current_step]['status'] = $stepStatus;
        $run->steps_state = $state;
        $run->current_step = $run->current_step + 1;

        if (! isset($chain['steps'][$run->current_step])) {
            return $this->finish($run);
        }

        $run->status = TaskChainRun::STATUS_RUNNING;
        $run->save();

        return $this->prepareCurrentStep($run, $chain);
    }

    /**
     * delegate 步就位提示：与 tool 步相同的两阶段模式（避免级联）
     */
    private function prepareDelegateStep(TaskChainRun $run, array $step): array
    {
        $run->status = TaskChainRun::STATUS_RUNNING;
        $this->setStepStatus($run, $run->current_step, 'ready');
        $run->save();

        $role = (string) ($step['agent_role'] ?? 'unknown');

        return $this->view($run, "下一步为 delegate 步 [{$role}]，请调用 advance_task_chain 推进执行");
    }

    /**
     * delegate 步执行：调用 HeadlessAgentService 执行无用户交互的短 ReAct 会话
     */
    private function advanceDelegateStep(TaskChainRun $run, array $chain, array $step, array $stepOutput, int $tenantId): array
    {
        // 手动回填（headless 失败后人工覆盖）
        if ($stepOutput !== []) {
            return $this->completeStep($run, $chain, $step, $stepOutput);
        }

        $agentRole = (string) ($step['agent_role'] ?? '');

        // 自引用防护：禁止 delegate 给秘书（秘书跑链时不能 delegate 给自己）
        if ($agentRole === 'system_secretary') {
            return $this->failStep($run, $step, '禁止 delegate 给 system_secretary（自引用循环）');
        }

        // 解析 prompt 中的占位符
        $promptTemplate = (string) ($step['args']['prompt'] ?? '');
        $resolvedPrompt = $this->resolvePlaceholderString($promptTemplate, $this->context($run));

        if ($resolvedPrompt === '') {
            return $this->failStep($run, $step, 'delegate 步缺少 args.prompt 模板');
        }

        // 调用 HeadlessAgentService
        $result = $this->headless->execute($agentRole, $resolvedPrompt, $tenantId);

        if ($result->partial) {
            return $this->failStep($run, $step, 'delegate 执行失败（partial）：' . ($result->error ?: '超过最大轮次'));
        }

        return $this->completeStep($run, $chain, $step, $result->text);
    }

    /**
     * upload 步：语义等同 input 步但前端渲染为上传组件
     */
    private function advanceUploadStep(TaskChainRun $run, array $chain, array $step, array $stepInput): array
    {
        if ($stepInput === []) {
            $run->status = TaskChainRun::STATUS_WAITING_INPUT;
            $this->setStepStatus($run, $run->current_step, 'waiting');
            $run->save();

            return $this->view($run, "请让用户上传文件，然后调用 advance_task_chain 以 step_input={file_id: xxx} 提交");
        }

        return $this->completeStep($run, $chain, $step, $stepInput);
    }

    /**
     * 按当前步类型置状态并生成 next_action（不执行工具）
     */
    private function prepareCurrentStep(TaskChainRun $run, array $chain, bool $forceL2 = false): array
    {
        $step = $chain['steps'][$run->current_step];

        return match ($step['type']) {
            'input' => $this->advanceInputStep($run, $chain, $step, []),
            'tool' => $this->prepareToolStep($run, $step),
            'delegate' => $this->prepareDelegateStep($run, $step),
            'upload' => $this->advanceUploadStep($run, $chain, $step, []),
            default => $this->failStep($run, $step, "步骤类型 [{$step['type']}] 不支持"),
        };
    }

    /**
     * tool 步就位提示：指示 LLM 调用 advance_task_chain 触发执行（L1）或走确认门（L2）
     */
    private function prepareToolStep(TaskChainRun $run, array $step): array
    {
        $run->status = TaskChainRun::STATUS_RUNNING;
        $this->setStepStatus($run, $run->current_step, 'ready');
        $run->save();

        return $this->view($run, "下一步为工具步 [{$step['tool']}]，请调用 advance_task_chain 推进执行");
    }

    /**
     * 当前步置 failed：保留 current_step 现场，可重复 advance 重试
     */
    private function failStep(TaskChainRun $run, array $step, string $reason): array
    {
        $run->status = TaskChainRun::STATUS_FAILED;
        $this->setStepStatus($run, $run->current_step, 'failed');
        $run->save();

        return $this->view($run, "第 {$run->current_step} 步 [{$step['name']}] 失败：{$reason}。修正后可再次调用 advance_task_chain 重试");
    }

    private function finish(TaskChainRun $run): array
    {
        $run->status = TaskChainRun::STATUS_COMPLETED;
        $run->save();

        return $this->view($run, '任务链已全部完成，请向用户总结各步结果');
    }

    /**
     * 统一状态视图：{run_id, chain_key, title, steps, current_step, status, next_action}
     */
    private function view(TaskChainRun $run, ?string $nextAction = null): array
    {
        $chain = $this->registry->find($run->chain_key);

        return [
            'run_id' => $run->run_id,
            'chain_key' => $run->chain_key,
            'title' => (string) ($chain['title'] ?? $run->chain_key),
            'steps' => array_map(fn (array $step) => [
                'name' => $step['name'],
                'status' => $step['status'],
            ], $run->steps_state['steps'] ?? []),
            'current_step' => $run->current_step,
            'status' => $run->status,
            'next_action' => $nextAction,
        ];
    }

    private function setStepStatus(TaskChainRun $run, int $index, string $status): void
    {
        $state = $run->steps_state;

        if (isset($state['steps'][$index])) {
            $state['steps'][$index]['status'] = $status;
            $run->steps_state = $state;
        }
    }

    /**
     * 链上下文 KV 包
     */
    private function context(TaskChainRun $run): array
    {
        return (array) ($run->steps_state['context'] ?? []);
    }

    /**
     * 递归替换 args 中的 {{output_key}} 占位符（数组值 json_encode 后代入）
     */
    private function resolvePlaceholders(array $args, array $context): array
    {
        return array_map(function ($value) use ($context) {
            if (is_array($value)) {
                return $this->resolvePlaceholders($value, $context);
            }

            if (! is_string($value)) {
                return $value;
            }

            return $this->resolvePlaceholderString($value, $context);
        }, $args);
    }

    /**
     * 单个字符串中的占位符替换
     */
    private function resolvePlaceholderString(string $value, array $context): string
    {
        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $matches) use ($context) {
            $replacement = $context[$matches[1]] ?? $matches[0];

            return is_array($replacement) ? json_encode($replacement, JSON_UNESCAPED_UNICODE) : (string) $replacement;
        }, $value);
    }

    /**
     * 超大结果不入 context，存摘要引用（docs/task-chain.md：>16KB 存引用）
     */
    private function clampContextValue(mixed $value): mixed
    {
        $encoded = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($encoded !== false && strlen($encoded) > self::MAX_CONTEXT_VALUE_BYTES) {
            return [
                'truncated' => true,
                'summary' => mb_substr($encoded, 0, 500) . '…（结果过大已截断）',
            ];
        }

        return $value;
    }
}
