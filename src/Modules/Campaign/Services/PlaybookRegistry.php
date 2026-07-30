<?php

namespace MultiTenantSaas\Modules\Campaign\Services;

use Illuminate\Support\Facades\Log;

/**
 * Playbook 注册表（docs/event-plan.md Phase 1）
 *
 * Playbook 为活动方法论模板，提供 phases/tasks 骨架供 campaign_plan_draft 工具参考。
 * 模式完全照搬 TaskChainRegistry：
 * - 框架内置 demo playbook + 下游 extra_playbook_classes 扩展
 * - key 冲突时下游覆盖框架内置
 * - 定义结构校验在首次访问时完成，坏定义记日志跳过
 *
 * 扩展点：config('ai.campaign.extra_playbook_classes') → 静态 playbooks(): array
 */
class PlaybookRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    /**
     * 全部可用 playbook（key => definition）
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $playbooks = [];

        foreach ($this->rawDefinitions() as $playbook) {
            $error = $this->validate($playbook);

            if ($error !== null) {
                Log::warning('[Playbook] 定义非法已跳过', [
                    'key' => $playbook['key'] ?? '(missing)',
                    'error' => $error,
                ]);

                continue;
            }

            // key 冲突时后注册（下游）覆盖先注册（框架内置）
            $playbooks[(string) $playbook['key']] = $this->normalize($playbook);
        }

        return $this->cache = $playbooks;
    }

    /**
     * 按 key 取 playbook 定义
     *
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Playbook 目录（供工具展示的轻量视图）
     *
     * @return list<array{key: string, title: string, description: string}>
     */
    public function catalog(): array
    {
        return array_values(array_map(fn (array $pb) => [
            'key' => (string) $pb['key'],
            'title' => (string) $pb['title'],
            'description' => (string) ($pb['description'] ?? ''),
        ], $this->all()));
    }

    /**
     * 清除缓存
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * 框架内置 + 下游扩展的原始定义
     *
     * @return list<array<string, mixed>>
     */
    private function rawDefinitions(): array
    {
        $definitions = $this->builtinPlaybooks();

        foreach ((array) config('ai.campaign.extra_playbook_classes', []) as $class) {
            if (! is_string($class) || ! class_exists($class) || ! method_exists($class, 'playbooks')) {
                Log::warning('[Playbook] 扩展类不可用已跳过', ['class' => (string) $class]);

                continue;
            }

            foreach ((array) $class::playbooks() as $playbook) {
                if (is_array($playbook)) {
                    $definitions[] = $playbook;
                }
            }
        }

        return $definitions;
    }

    /**
     * 框架内置 playbook（仅 demo，真实业务 playbook 在下游通过 extra_playbook_classes 注册）
     *
     * @return list<array<string, mixed>>
     */
    private function builtinPlaybooks(): array
    {
        return [
            [
                'key' => 'demo_sms_sequence',
                'title' => '三天短信序列（演示）',
                'description' => '演示 playbook：预约后 D+0/D+1/D+2 各发一条短信',
                'methodology' => '简单的定时短信序列，用于验证 recurring 展开和 tool 执行',
                'skeleton' => [
                    'schema' => 'campaign.plan/v1',
                    'phases' => [
                        [
                            'key' => 'sms_sequence',
                            'title' => '短信序列',
                            'tasks' => [
                                [
                                    'key' => 'daily_sms',
                                    'title' => '每日短信',
                                    'trigger' => [
                                        'type' => 'recurring',
                                        'from' => '+0d',
                                        'until' => '+2d',
                                        'interval' => '1d',
                                        'at' => '09:30',
                                    ],
                                    'action' => [
                                        'type' => 'tool',
                                        'tool' => 'send_sms',
                                        'args' => ['template' => '活动提醒'],
                                    ],
                                    'execution_mode' => 'auto',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 定义结构校验：错误返回原因文本，合法返回 null
     */
    private function validate(array $playbook): ?string
    {
        $key = $playbook['key'] ?? '';

        if (! is_string($key) || trim($key) === '') {
            return 'key 缺失或为空';
        }

        if (! is_string($playbook['title'] ?? null) || trim((string) $playbook['title']) === '') {
            return 'title 缺失或为空';
        }

        $skeleton = $playbook['skeleton'] ?? null;

        if (! is_array($skeleton)) {
            return 'skeleton 缺失或非数组';
        }

        $phases = $skeleton['phases'] ?? null;

        if (! is_array($phases) || $phases === []) {
            return 'skeleton.phases 缺失或为空';
        }

        // 校验每个 phase 至少有 tasks
        foreach ($phases as $pi => $phase) {
            if (! is_array($phase)) {
                return "phases[{$pi}] 非数组";
            }

            $tasks = $phase['tasks'] ?? null;
            if (! is_array($tasks) || $tasks === []) {
                return "phases[{$pi}] tasks 缺失或为空";
            }

            foreach ($tasks as $ti => $task) {
                if (! is_array($task)) {
                    return "phases[{$pi}].tasks[{$ti}] 非数组";
                }
                if (empty($task['key'])) {
                    return "phases[{$pi}].tasks[{$ti}] 缺少 key";
                }
                if (empty($task['title'])) {
                    return "phases[{$pi}].tasks[{$ti}] 缺少 title";
                }
                if (! is_array($task['trigger'] ?? null) || empty($task['trigger']['type'])) {
                    return "phases[{$pi}].tasks[{$ti}] 缺少 trigger 或 trigger.type";
                }
                if (! is_array($task['action'] ?? null) || empty($task['action']['type'])) {
                    return "phases[{$pi}].tasks[{$ti}] 缺少 action 或 action.type";
                }
            }
        }

        return null;
    }

    /**
     * 补充默认字段
     *
     * @return array<string, mixed>
     */
    private function normalize(array $playbook): array
    {
        $playbook['description'] = $playbook['description'] ?? '';
        $playbook['methodology'] = $playbook['methodology'] ?? '';

        return $playbook;
    }
}
