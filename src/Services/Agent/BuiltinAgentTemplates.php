<?php

namespace MultiTenantSaas\Services\Agent;

use Illuminate\Support\Collection;

/**
 * 预置 Agent 模板定义数据
 *
 * 框架层提供 8 个角色骨架空模板（客服/销售/营销/数据分析等），
 * feature_keys 留空由业务层填充。本类为纯数据类，不含任何业务逻辑。
 *
 * @see \MultiTenantSaas\Services\Agent\AgentService::getBuiltinTemplates()
 * @see \MultiTenantSaas\Services\Agent\AgentService::cloneFromTemplate()
 */
final class BuiltinAgentTemplates
{
    /**
     * 允许被克隆时覆盖的字段白名单
     */
    public const CLONE_OVERRIDABLE_KEYS = [
        'name',
        'avatar',
        'description',
        'tools',
        'kb_ids',
        'feature_keys',
        'model_config',
        'enabled',
    ];

    /**
     * 静态缓存，避免每次调用都重建完整数组
     *
     * @var list<array<string, mixed>>|null
     */
    private static ?array $cache = null;

    /**
     * 获取全部预置模板定义
     *
     * 返回 8 个角色骨架空模板，feature_keys 为空数组由业务层填充。
     *
     * @return list<array{
     *     template_id: int,
     *     template_key: string,
     *     role: string,
     *     name: string,
     *     avatar: string,
     *     description: string,
     *     system_prompt: string,
     *     tools: list<string>,
     *     kb_ids: list<int>,
     *     feature_keys: list<string>,
     *     model_config: array<string, mixed>,
     * }>
     */
    public static function definitions(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $modelConfig = self::defaultModelConfig();

        self::$cache = [
            [
                'template_id' => 1,
                'template_key' => 'customer_service',
                'role' => 'customer_service',
                'name' => '客服专员',
                'avatar' => '',
                'description' => '处理客户咨询、投诉、售后问题，提供专业、耐心的服务。',
                'system_prompt' => '你是一名专业的客服专员。你的职责是接待客户咨询、解答疑问、处理投诉，并始终保持耐心、专业、友善的服务态度。',
                'tools' => [],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => $modelConfig,
            ],
            [
                'template_id' => 2,
                'template_key' => 'sales',
                'role' => 'sales',
                'name' => '销售顾问',
                'avatar' => '',
                'description' => '挖掘客户需求、推荐产品、跟进商机、促成成交。',
                'system_prompt' => '你是一名专业的销售顾问。你的职责是了解客户需求、推荐合适的产品或方案、跟进商机并促成成交，同时维护良好的客户关系。',
                'tools' => [],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => $modelConfig,
            ],
            [
                'template_id' => 3,
                'template_key' => 'marketing',
                'role' => 'marketing',
                'name' => '营销专员',
                'avatar' => '',
                'description' => '策划营销活动、撰写文案、分析投放效果、优化转化。',
                'system_prompt' => '你是一名专业的营销专员。你的职责是策划营销活动、撰写推广文案、分析投放数据并优化转化效果，助力品牌增长。',
                'tools' => [],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => $modelConfig,
            ],
            [
                'template_id' => 4,
                'template_key' => 'data_analyst',
                'role' => 'data_analyst',
                'name' => '数据分析师',
                'avatar' => '',
                'description' => '采集、清洗、分析业务数据，输出报表与决策建议。',
                'system_prompt' => '你是一名专业的数据分析师。你的职责是采集和清洗业务数据、进行统计分析、输出可视化报表，并基于数据给出可执行的决策建议。',
                'tools' => [],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => $modelConfig,
            ],
            [
                'template_id' => 5,
                'template_key' => 'operations',
                'role' => 'operations',
                'name' => '运营专员',
                'avatar' => '',
                'description' => '负责日常运营、流程优化、活动执行与效果跟踪。',
                'system_prompt' => '你是一名专业的运营专员。你的职责是执行日常运营任务、优化业务流程、跟踪活动效果并推动持续改进。',
                'tools' => [],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => $modelConfig,
            ],
            [
                'template_id' => 6,
                'template_key' => 'hr',
                'role' => 'hr',
                'name' => '人力资源',
                'avatar' => '',
                'description' => '处理招聘、培训、绩效、员工关系等 HR 事务。',
                'system_prompt' => '你是一名专业的人力资源专员。你的职责是处理招聘、培训、绩效评估、员工关系等 HR 事务，并遵守相关劳动法规。',
                'tools' => [],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => $modelConfig,
            ],
            [
                'template_id' => 7,
                'template_key' => 'finance',
                'role' => 'finance',
                'name' => '财务助手',
                'avatar' => '',
                'description' => '处理账务、报销、发票、预算等财务相关事务。',
                'system_prompt' => '你是一名专业的财务助手。你的职责是处理日常账务、报销审核、发票管理、预算执行等财务事务，确保合规与准确。',
                'tools' => [],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => $modelConfig,
            ],
            [
                'template_id' => 8,
                'template_key' => 'tech_support',
                'role' => 'tech_support',
                'name' => '技术支持',
                'avatar' => '',
                'description' => '解答技术问题、排查故障、提供 IT 层面的支持。',
                'system_prompt' => '你是一名专业的技术支持工程师。你的职责是解答技术问题、排查系统故障、提供 IT 层面的支持与指导，并记录工单进展。',
                'tools' => [],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => $modelConfig,
            ],
        ];

        return self::$cache;
    }

    /**
     * 获取全部模板（Collection 形式）
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function all(): Collection
    {
        return new Collection(self::definitions());
    }

    /**
     * 按 template_id 查找模板
     *
     * 对 $templateId 做整型强制转换，避免调用方传入字符串 "1" 时严格比较失败。
     *
     * @param  int  $templateId
     * @return array<string, mixed>|null
     */
    public static function find(int $templateId): ?array
    {
        $targetId = (int) $templateId;

        foreach (self::definitions() as $template) {
            if ((int) $template['template_id'] === $targetId) {
                return $template;
            }
        }

        return null;
    }

    /**
     * 按 template_key 查找模板
     *
     * @param  string  $templateKey
     * @return array<string, mixed>|null
     */
    public static function findByKey(string $templateKey): ?array
    {
        foreach (self::definitions() as $template) {
            if ($template['template_key'] === $templateKey) {
                return $template;
            }
        }

        return null;
    }

    /**
     * 默认 model_config（合并 config/ai.php 默认值的骨架）
     *
     * @return array{
     *     preferred_provider: string,
     *     preferred_model: string,
     *     fallback_provider: string,
     *     fallback_model: string,
     *     temperature: float,
     *     max_tokens: int,
     *     max_tool_calls: int,
     *     stream: bool,
     * }
     */
    public static function defaultModelConfig(): array
    {
        return [
            'preferred_provider' => (string) config('ai.default_provider', 'openai'),
            'preferred_model' => (string) config('ai.default_model', 'gpt-4o-mini'),
            'fallback_provider' => '',
            'fallback_model' => '',
            'temperature' => 0.7,
            'max_tokens' => 2000,
            'max_tool_calls' => 5,
            'stream' => true,
        ];
    }

    /**
     * 清除静态缓存（主要供测试使用）
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
