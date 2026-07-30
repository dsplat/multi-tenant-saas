<?php

namespace MultiTenantSaas\Modules\Ai\Services\TaskChain;

use Illuminate\Support\Facades\Log;

/**
 * 预设任务链注册表（docs/task-chain.md 第三节）
 *
 * 链定义为纯数据（PHP 数组），不含执行逻辑。
 * 下游扩展模式与数字员工模板 extra_template_classes 完全一致：
 * config('ai.task_chains.extra_chain_classes') 中的类提供静态 chains(): array，
 * key 冲突时下游覆盖框架内置链。
 *
 * 定义结构校验（key 唯一、step type 合法）在首次访问时完成，坏定义记日志跳过；
 * 工具 slug 存在性校验延后到 TaskChainRunner 执行时（避免 Provider 启动顺序依赖）。
 */
class TaskChainRegistry
{
    public const STEP_TYPES = ['tool', 'delegate', 'input', 'upload'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    /**
     * 全部可用链定义（key => definition，框架内置 + 下游扩展）
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $chains = [];

        foreach ($this->rawDefinitions() as $chain) {
            $error = $this->validate($chain);

            if ($error !== null) {
                Log::warning('[TaskChain] 链定义非法已跳过', [
                    'key' => $chain['key'] ?? '(missing)',
                    'error' => $error,
                ]);

                continue;
            }

            // key 冲突时后注册（下游）覆盖先注册（框架内置）
            $chains[(string) $chain['key']] = $this->normalize($chain);
        }

        return $this->cache = $chains;
    }

    /**
     * 按 key 取链定义
     *
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * 按 trigger_hints 关键词粗筛（供秘书提示，不做最终决策）
     *
     * @return list<array<string, mixed>>
     */
    public function matchByIntent(string $intent): array
    {
        if (trim($intent) === '') {
            return [];
        }

        $matched = [];

        foreach ($this->all() as $chain) {
            foreach ((array) ($chain['trigger_hints'] ?? []) as $hint) {
                if ($hint !== '' && str_contains($intent, (string) $hint)) {
                    $matched[] = $chain;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * 链目录（供 list_task_chains / suggestions 消费的轻量视图）
     *
     * @return list<array{key: string, title: string, description: string}>
     */
    public function catalog(): array
    {
        return array_values(array_map(fn (array $chain) => [
            'key' => (string) $chain['key'],
            'title' => (string) $chain['title'],
            'description' => (string) ($chain['description'] ?? ''),
        ], $this->all()));
    }

    /**
     * 清除缓存（供测试与配置变更后使用）
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * 框架内置链 + 下游扩展链原始定义（未校验）
     *
     * @return list<array<string, mixed>>
     */
    private function rawDefinitions(): array
    {
        $definitions = $this->builtinChains();

        foreach ((array) config('ai.task_chains.extra_chain_classes', []) as $class) {
            if (! is_string($class) || ! class_exists($class) || ! method_exists($class, 'chains')) {
                Log::warning('[TaskChain] 扩展链类不可用已跳过', ['class' => (string) $class]);

                continue;
            }

            foreach ((array) $class::chains() as $chain) {
                if (is_array($chain)) {
                    $definitions[] = $chain;
                }
            }
        }

        return $definitions;
    }

    /**
     * 框架内置链：两步演示链（Phase 1 验收载体）
     *
     * @return list<array<string, mixed>>
     */
    private function builtinChains(): array
    {
        return [
            [
                'key' => 'demo_poster_flow',
                'title' => '一句话出海报（演示）',
                'description' => '两步演示链：先收集海报主题与文案要点，再自动生成海报图。',
                'trigger_hints' => ['出海报', '做海报', '演示任务链'],
                'steps' => [
                    [
                        'name' => '收集海报主题',
                        'type' => 'input',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'brief' => ['type' => 'string', 'description' => '海报主题、文案要点与风格'],
                            ],
                            'required' => ['brief'],
                        ],
                        'output_key' => 'brief',
                    ],
                    [
                        'name' => '生成海报',
                        'type' => 'tool',
                        'tool' => 'generate_poster',
                        'args' => ['prompt' => '{{brief}}'],
                        'output_key' => 'poster',
                    ],
                ],
            ],
        ];
    }

    /**
     * 定义结构校验：错误返回原因文本，合法返回 null
     */
    private function validate(array $chain): ?string
    {
        $key = $chain['key'] ?? '';

        if (! is_string($key) || trim($key) === '') {
            return 'key 缺失或为空';
        }

        if (! is_string($chain['title'] ?? null) || trim((string) $chain['title']) === '') {
            return 'title 缺失或为空';
        }

        $steps = $chain['steps'] ?? null;

        if (! is_array($steps) || $steps === []) {
            return 'steps 缺失或为空';
        }

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                return "步骤 #{$index} 非数组";
            }

            $type = $step['type'] ?? '';

            if (! in_array($type, self::STEP_TYPES, true)) {
                return "步骤 #{$index} type [{$type}] 非法";
            }

            if ($type === 'tool' && trim((string) ($step['tool'] ?? '')) === '') {
                return "步骤 #{$index} 缺少 tool slug";
            }

            if ($type === 'delegate' && trim((string) ($step['agent_role'] ?? '')) === '') {
                return "步骤 #{$index} 缺少 agent_role";
            }
        }

        return null;
    }

    /**
     * 步骤字段补默认值（optional/args/output_key）
     *
     * @return array<string, mixed>
     */
    private function normalize(array $chain): array
    {
        $chain['steps'] = array_values(array_map(fn (array $step) => array_merge([
            'name' => '',
            'type' => 'tool',
            'tool' => null,
            'agent_role' => null,
            'input_schema' => null,
            'args' => [],
            'output_key' => null,
            'optional' => false,
        ], $step), $chain['steps']));

        return $chain;
    }
}
