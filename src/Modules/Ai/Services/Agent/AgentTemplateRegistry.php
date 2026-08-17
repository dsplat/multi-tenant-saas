<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use LogicException;

/**
 * 数字员工模板注册表（框架模板 + 下游扩展模板的唯一入口）
 *
 * 框架 BuiltinAgentTemplates 使用 template_id / template_key / role 三元组；
 * 下游扩展模板类（经 config('ai.secretary.extra_template_classes') 注册，
 * 如 ScrmAgentTemplates）历史上使用 id / key 两字段。本注册表负责归一化，
 * 让克隆、启用、名录生成三条链路看到同一份形状一致的模板集合。
 *
 * 归一化规则：
 * - template_id ← template_id ?? id
 * - template_key ← template_key ?? key
 * - role ← role ?? template_key（下游模板 key 即角色标识）
 * - 缺失的 kb_ids / feature_keys / tools / optional_tools / model_config 补空
 * - seq 按定义顺序派生（小秘书首位 seq=0），手写 seq 一律忽略
 *
 * 数值确定性关卡：归一化后全量不变量校验，违规抛 LogicException
 * （模板是编译期数据，不带病运行，fail-fast 优于静默漂移）。
 *
 * @see BuiltinAgentTemplates 框架内置模板数据
 * @see AgentService::cloneFromTemplate()
 * @see \MultiTenantSaas\Modules\Ai\Services\Tool\EnableAgentTool
 */
final class AgentTemplateRegistry
{
    /** snake_case 标识符校验（template_key / role / 工具 slug 同口径） */
    private const SNAKE_CASE = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /** model_config 必填键（缺失即模板不可用，克隆后 Agent 无法解析模型端点） */
    private const REQUIRED_MODEL_CONFIG_KEYS = [
        'preferred_provider',
        'preferred_model',
        'temperature',
        'max_tokens',
    ];

    /**
     * 归一化后的全量模板缓存（同请求内复用）
     *
     * @var list<array<string, mixed>>|null
     */
    private static ?array $cache = null;

    /**
     * 全部模板（框架 + 下游扩展），已归一化
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $templates = array_map(
            static fn (array $t): array => self::normalize($t),
            BuiltinAgentTemplates::definitions(),
        );

        foreach ((array) config('ai.secretary.extra_template_classes', []) as $class) {
            if (! is_string($class) || ! class_exists($class) || ! method_exists($class, 'definitions')) {
                continue;
            }

            $extra = $class::definitions();

            if (! is_array($extra)) {
                continue;
            }

            foreach ($extra as $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $normalized = self::normalize($definition);

                // template_id / template_key 冲突时以框架模板为准（下游不得覆盖框架）
                if (self::findIn($templates, $normalized) !== null) {
                    continue;
                }

                $templates[] = $normalized;
            }
        }

        $templates = array_values($templates);

        // seq 从定义顺序派生（唯一事实源）：手写 seq 一律忽略，
        // 小秘书在定义首位即 seq=0，后续模板依次递增
        foreach ($templates as $index => $template) {
            $templates[$index]['seq'] = $index;
        }

        self::assertInvariants($templates);

        return self::$cache = $templates;
    }

    /**
     * 按 template_id 查找
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $templateId): ?array
    {
        foreach (self::definitions() as $template) {
            if ((int) ($template['template_id'] ?? 0) === $templateId) {
                return $template;
            }
        }

        return null;
    }

    /**
     * 按 template_key 或 role 查找（两者对下游模板等价）
     *
     * @return array<string, mixed>|null
     */
    public static function findByKey(string $key): ?array
    {
        foreach (self::definitions() as $template) {
            if (($template['template_key'] ?? null) === $key || ($template['role'] ?? null) === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * 除小秘书外的全部可启用模板（小秘书由 secretary:install 单独安装）
     *
     * @return list<array<string, mixed>>
     */
    public static function enableable(): array
    {
        return array_values(array_filter(
            self::definitions(),
            static fn (array $t): bool => ($t['template_key'] ?? '') !== 'system_secretary',
        ));
    }

    /**
     * 清空缓存（测试或运行时改动 config 后调用）
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * 模板字段归一化
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private static function normalize(array $template): array
    {
        $templateKey = (string) ($template['template_key'] ?? $template['key'] ?? '');
        $role = (string) ($template['role'] ?? $templateKey);

        return array_merge($template, [
            'template_id' => (int) ($template['template_id'] ?? $template['id'] ?? 0),
            'template_key' => $templateKey,
            'role' => $role,
            'name' => (string) ($template['name'] ?? $templateKey),
            'avatar' => $template['avatar'] ?? '',
            'description' => (string) ($template['description'] ?? ''),
            'system_prompt' => (string) ($template['system_prompt'] ?? ''),
            'tools' => (array) ($template['tools'] ?? []),
            // 下游扩展工具（未注册静默跳过属设计意图，与 tools 的缺失即失败区分）
            'optional_tools' => (array) ($template['optional_tools'] ?? []),
            'kb_ids' => (array) ($template['kb_ids'] ?? []),
            'feature_keys' => (array) ($template['feature_keys'] ?? []),
            'model_config' => (array) ($template['model_config'] ?? []),
        ]);
    }

    /**
     * 全量模板不变量校验（数值确定性关卡）
     *
     * 模板是编译期数据：违规即抛 LogicException，不允许手写两份靠人工对齐。
     *
     * @param  list<array<string, mixed>>  $templates
     */
    private static function assertInvariants(array $templates): void
    {
        $seenIds = [];
        $seenKeys = [];
        $seenRoles = [];

        foreach ($templates as $template) {
            $key = (string) $template['template_key'];
            $id = (int) $template['template_id'];

            if ($id < 1) {
                throw new LogicException("Agent 模板 [{$key}] 的 template_id 必须 >= 1（0 为 falsy 值，禁用作 ID）");
            }
            if (isset($seenIds[$id])) {
                throw new LogicException("Agent 模板 template_id [{$id}] 重复：{$seenIds[$id]} 与 {$key}");
            }
            $seenIds[$id] = $key;

            if ($key === '' || ! preg_match(self::SNAKE_CASE, $key)) {
                throw new LogicException("Agent 模板 template_key [{$key}] 必须为非空 snake_case");
            }
            if (isset($seenKeys[$key])) {
                throw new LogicException("Agent 模板 template_key [{$key}] 重复");
            }
            $seenKeys[$key] = true;

            $role = (string) $template['role'];
            if ($role === '' || ! preg_match(self::SNAKE_CASE, $role)) {
                throw new LogicException("Agent 模板 [{$key}] 的 role [{$role}] 必须为非空 snake_case");
            }
            if (isset($seenRoles[$role])) {
                throw new LogicException("Agent 模板 role [{$role}] 重复：{$key}");
            }
            $seenRoles[$role] = true;

            foreach (['name', 'description', 'system_prompt'] as $field) {
                if (trim((string) $template[$field]) === '') {
                    throw new LogicException("Agent 模板 [{$key}] 的 {$field} 不得为空");
                }
            }

            foreach (['tools', 'optional_tools'] as $field) {
                $slugs = (array) $template[$field];
                $seen = [];
                foreach ($slugs as $slug) {
                    if (! is_string($slug) || ! preg_match(self::SNAKE_CASE, $slug)) {
                        throw new LogicException("Agent 模板 [{$key}] 的 {$field} 含非法 slug：" . var_export($slug, true));
                    }
                    if (isset($seen[$slug])) {
                        throw new LogicException("Agent 模板 [{$key}] 的 {$field} 存在重复 slug：{$slug}");
                    }
                    $seen[$slug] = true;
                }
            }

            foreach (self::REQUIRED_MODEL_CONFIG_KEYS as $configKey) {
                if (! array_key_exists($configKey, (array) $template['model_config'])) {
                    throw new LogicException("Agent 模板 [{$key}] 的 model_config 缺少必填键：{$configKey}");
                }
            }
        }
    }

    /**
     * 在已收集模板中查找 id 或 key 冲突项
     *
     * @param  list<array<string, mixed>>  $templates
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private static function findIn(array $templates, array $candidate): ?array
    {
        foreach ($templates as $template) {
            $sameId = (int) ($template['template_id'] ?? 0) !== 0
                && (int) ($template['template_id'] ?? 0) === (int) ($candidate['template_id'] ?? 0);
            $sameKey = ($template['template_key'] ?? '') !== ''
                && ($template['template_key'] ?? '') === ($candidate['template_key'] ?? '');

            if ($sameId || $sameKey) {
                return $template;
            }
        }

        return null;
    }
}
