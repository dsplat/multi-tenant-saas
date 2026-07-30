<?php

namespace MultiTenantSaas\Modules\Campaign\Services;

use Carbon\Carbon;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Campaign\Models\CampaignPlan;
use MultiTenantSaas\Modules\Campaign\Models\CampaignTask;

/**
 * Plan 编译器（docs/event-plan.md 第五节）
 *
 * plan_doc（声明式 DSL）→ campaign_tasks（可调度任务）的编译过程：
 * 解析锚点为绝对时间、校验工具存在与依赖无环、幂等 diff 增量更新。
 * 文档是源码，任务表是编译产物；重排期 = 重编译。
 */
class PlanCompiler
{
    public function __construct(
        private readonly ToolRegistryContract $toolRegistry,
    ) {}

    /**
     * 校验 plan_doc 合法性，返回错误清单（空数组 = 通过）
     *
     * @param  array  $planDoc  campaign.plan/v1 schema 文档
     * @return string[] 错误消息列表
     */
    public function validate(array $planDoc): array
    {
        $errors = [];

        // schema 版本
        $schema = $planDoc['schema'] ?? '';
        if ($schema !== 'campaign.plan/v1') {
            $errors[] = "schema 版本无效：期望 campaign.plan/v1，实际 {$schema}";
        }

        // 收集所有 tasks（跨 phases）
        $tasks = $this->flattenTasks($planDoc);

        if ($tasks === []) {
            $errors[] = '计划文档中没有任何任务';

            return $errors;
        }

        // task key 唯一性
        $keys = array_column($tasks, 'key');
        $duplicates = array_diff_assoc($keys, array_unique($keys));
        foreach (array_unique($duplicates) as $dup) {
            $errors[] = "任务 key 重复：{$dup}";
        }

        $keySet = array_unique($keys);

        foreach ($tasks as $task) {
            $key = $task['key'] ?? '?';
            $trigger = $task['trigger'] ?? [];
            $triggerType = $trigger['type'] ?? '';

            // trigger 类型合法性
            if (! in_array($triggerType, ['relative', 'at_time', 'on_event', 'recurring'], true)) {
                $errors[] = "任务 [{$key}] trigger.type 无效：{$triggerType}";
            } elseif ($triggerType === 'recurring') {
                $errors[] = "任务 [{$key}] trigger.type=recurring 暂不支持（Phase 2）";
            }

            // relative 必须有 anchor + offset
            if ($triggerType === 'relative') {
                if (empty($trigger['anchor'])) {
                    $errors[] = "任务 [{$key}] relative 触发缺少 anchor";
                }
                if (empty($trigger['offset'])) {
                    $errors[] = "任务 [{$key}] relative 触发缺少 offset";
                }
            }

            // on_event 必须有 event
            if ($triggerType === 'on_event' && empty($trigger['event'])) {
                $errors[] = "任务 [{$key}] on_event 触发缺少 event";
            }

            // depends_on 引用存在
            $dependsOn = $task['depends_on'] ?? [];
            foreach ($dependsOn as $dep) {
                if (! in_array($dep, $keySet, true)) {
                    $errors[] = "任务 [{$key}] depends_on 引用不存在的任务：{$dep}";
                }
            }

            // action.type=tool 时 slug 已注册
            $action = $task['action'] ?? [];
            if (($action['type'] ?? '') === 'tool') {
                $slug = $action['tool'] ?? '';
                if ($slug === '') {
                    $errors[] = "任务 [{$key}] action.type=tool 但缺少 tool slug";
                } elseif ($this->toolRegistry->get($slug) === null) {
                    $errors[] = "任务 [{$key}] 工具未注册：{$slug}";
                }
            }
        }

        // 依赖环检测（拓扑排序）
        $cycleError = $this->detectCycles($tasks);
        if ($cycleError !== null) {
            $errors[] = $cycleError;
        }

        return $errors;
    }

    /**
     * 编译计划：plan_doc → campaign_tasks（幂等，按 task_key diff）
     *
     * @param  CampaignPlan  $plan  待编译计划（status 须为 planning/scheduled）
     * @param  array  $anchorTimes  锚点时间映射 ['event.starts_at' => '2026-08-10 09:00', ...]
     *
     * @throws \RuntimeException 校验不通过或锚点缺失时
     */
    public function compile(CampaignPlan $plan, array $anchorTimes): void
    {
        $planDoc = $plan->plan_doc;

        // 编译前校验
        $errors = $this->validate($planDoc);
        if ($errors !== []) {
            throw new \RuntimeException('计划校验不通过：' . implode('；', $errors));
        }

        $tasks = $this->flattenTasks($planDoc);
        $now = Carbon::now();

        // 已有任务（按 task_key 索引）
        $existingTasks = CampaignTask::where('plan_id', $plan->plan_id)
            ->get()
            ->keyBy('task_key');

        $compiledKeys = [];

        foreach ($tasks as $taskDef) {
            $key = $taskDef['key'];
            $compiledKeys[] = $key;

            $trigger = $taskDef['trigger'] ?? [];
            $triggerType = $trigger['type'] ?? '';

            // 解析触发时间
            $scheduledAt = null;
            $listenEvent = null;
            $dbTriggerType = 'at_time';

            if ($triggerType === 'relative') {
                $anchorKey = $trigger['anchor'] ?? '';
                if (! isset($anchorTimes[$anchorKey])) {
                    throw new \RuntimeException("锚点时间缺失：{$anchorKey}（任务 {$key}）");
                }
                $scheduledAt = $this->resolveRelative($anchorTimes[$anchorKey], $trigger);
                $dbTriggerType = 'at_time';
            } elseif ($triggerType === 'at_time') {
                // at_time 在文档中罕见（编译产物），但支持直传
                $scheduledAt = isset($trigger['time']) ? Carbon::parse($trigger['time']) : null;
                $dbTriggerType = 'at_time';
            } elseif ($triggerType === 'on_event') {
                $listenEvent = $trigger['event'] ?? null;
                $dbTriggerType = 'on_event';
            }

            // 构建任务属性
            $phaseKey = $taskDef['_phase_key'] ?? null;
            $assignee = $taskDef['assignee'] ?? [];
            $action = $taskDef['action'] ?? [];

            $attributes = [
                'title' => $taskDef['title'] ?? $key,
                'phase_key' => $phaseKey,
                'trigger_type' => $dbTriggerType,
                'scheduled_at' => $scheduledAt,
                'listen_event' => $listenEvent,
                'assignee_type' => $assignee['type'] ?? 'system',
                'assignee_ref' => $assignee['role'] ?? $assignee['ref'] ?? null,
                'action' => $action,
                'execution_mode' => $taskDef['execution_mode'] ?? 'auto',
                'depends_on' => $taskDef['depends_on'] ?? [],
            ];

            $existing = $existingTasks->get($key);

            if ($existing) {
                // 已 done/running 的任务不动（重编译语义）
                if (in_array($existing->status, [CampaignTask::STATUS_DONE, CampaignTask::STATUS_RUNNING], true)) {
                    continue;
                }
                $existing->update($attributes);
            } else {
                CampaignTask::create(array_merge($attributes, [
                    'tenant_id' => $plan->tenant_id,
                    'plan_id' => $plan->plan_id,
                    'task_key' => $key,
                    'status' => CampaignTask::STATUS_PENDING,
                ]));
            }
        }

        // 删除文档中已不存在的任务（非终态才删）
        foreach ($existingTasks as $existingKey => $existingTask) {
            if (! in_array($existingKey, $compiledKeys, true) && ! $existingTask->isTerminal()) {
                $existingTask->delete();
            }
        }

        // 状态流转：planning → scheduled
        if ($plan->status === CampaignPlan::STATUS_PLANNING) {
            $plan->update(['status' => CampaignPlan::STATUS_SCHEDULED]);
        }
    }

    /**
     * 展平 phases → tasks 列表（附加 _phase_key）
     */
    private function flattenTasks(array $planDoc): array
    {
        $tasks = [];

        foreach ($planDoc['phases'] ?? [] as $phase) {
            $phaseKey = $phase['key'] ?? null;
            foreach ($phase['tasks'] ?? [] as $task) {
                $task['_phase_key'] = $phaseKey;
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * 解析 relative 触发：锚点 ± offset，可选 at 覆盖时分
     *
     * offset 格式：-7d / +1d / -2h / +30m
     * at 格式：HH:MM（覆盖计算结果的时分）
     */
    private function resolveRelative(string $anchorTime, array $trigger): Carbon
    {
        $base = Carbon::parse($anchorTime);
        $offset = $trigger['offset'] ?? '+0d';

        // 解析 offset：符号 + 数值 + 单位
        if (preg_match('/^([+-])(\d+)([dhm])$/', $offset, $m)) {
            $sign = $m[1] === '-' ? '-' : '+';
            $amount = (int) $m[2];
            $unit = match ($m[3]) {
                'd' => 'day',
                'h' => 'hour',
                'm' => 'minute',
            };
            $base = $sign === '-'
                ? $base->subUnit($unit, $amount)
                : $base->addUnit($unit, $amount);
        }

        // at 覆盖时分
        if (! empty($trigger['at'])) {
            $parts = explode(':', $trigger['at']);
            $base = $base->setTime((int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0), 0);
        }

        return $base;
    }

    /**
     * 依赖环检测（Kahn 拓扑排序）
     *
     * @return string|null 错误消息，null 表示无环
     */
    private function detectCycles(array $tasks): ?string
    {
        $keySet = array_column($tasks, 'key');
        $inDegree = array_fill_keys($keySet, 0);
        $adjacency = array_fill_keys($keySet, []);

        foreach ($tasks as $task) {
            $key = $task['key'];
            foreach ($task['depends_on'] ?? [] as $dep) {
                if (in_array($dep, $keySet, true)) {
                    $adjacency[$dep][] = $key;
                    $inDegree[$key]++;
                }
            }
        }

        $queue = array_keys(array_filter($inDegree, fn ($d) => $d === 0));
        $visited = 0;

        while ($queue !== []) {
            $node = array_shift($queue);
            $visited++;
            foreach ($adjacency[$node] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        if ($visited < count($keySet)) {
            $cyclic = array_keys(array_filter($inDegree, fn ($d) => $d > 0));

            return '依赖存在循环：' . implode(', ', $cyclic);
        }

        return null;
    }
}
