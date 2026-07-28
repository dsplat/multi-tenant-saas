<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Collection;

/**
 * 预置 Agent 模板定义数据
 *
 * 框架层提供系统小秘书（展示序号 seq=0，即“第 0 号数字员工”）+ 8 个角色骨架空模板
 * （客服/销售/营销/数据分析等），feature_keys 留空由业务层填充。
 * 本类为纯数据类，不含任何业务逻辑。
 *
 * 注意：template_id 是标识符，一律从 1 起编（0 为 falsy 值，禁用作 ID）；
 * “第 0 号”是 seq 展示序号，与 ID 无关。
 *
 * @see AgentService::getBuiltinTemplates()
 * @see AgentService::cloneFromTemplate()
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
     * 返回系统小秘书 + 8 个角色骨架空模板，feature_keys 为空数组由业务层填充。
     *
     * @return list<array{
     *     template_id: int,
     *     seq: int,
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
                'template_id' => 9,
                'seq' => 0,
                'template_key' => 'system_secretary',
                'role' => 'system_secretary',
                'name' => '系统小秘书',
                'avatar' => '',
                'description' => '系统总入口与总调度：回答系统怎么用、功能在哪里，带你跳转页面，并把专业事务转派给合适的数字员工。',
                'system_prompt' => self::secretarySystemPrompt(),
                'tools' => ['system_kb_search', 'get_data_dictionary', 'navigate', 'list_agents', 'delegate_to_agent', 'enable_agent'],
                'kb_ids' => [],
                'feature_keys' => [],
                'model_config' => self::secretaryModelConfig(),
            ],
            [
                'template_id' => 1,
                'seq' => 1,
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
                'seq' => 2,
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
                'seq' => 3,
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
                'seq' => 4,
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
                'seq' => 5,
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
                'seq' => 6,
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
                'seq' => 7,
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
                'seq' => 8,
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
     * 系统小秘书 system_prompt
     */
    private static function secretarySystemPrompt(): string
    {
        return <<<'PROMPT'
你是「AI小助手」——租户运营人员的唯一交互主入口，第 0 号数字员工。
你的使命：让用户从“点点点”升级为“说说说”——一句话就能完成以前需要多步点击的操作。

你的职责：
1. 系统向导：回答“系统怎么用、功能在哪里、业务流程怎么走”。必须先调用 system_kb_search 检索系统知识库，严格依据检索片段作答；检索不到就坦诚说不知道，绝不编造。
2. 带路：用户想去某个功能页时，用 navigate 返回站内路径；正文里引用该页面时用 Markdown 链接 [页面名称](/路径)，不要把路径当裸文本写出来。
3. 数据结构咨询：涉及表、字段等结构问题时用 get_data_dictionary 查询。
4. 调度转派：需要专业处理时，先用 list_agents 查看已启用的数字员工，再用 delegate_to_agent 转派。
5. 启用员工：当用户需要的数字员工尚未启用时，先告知用户并征得确认，然后调用 enable_agent 启用，再转派。

行为准则：
- 你是主入口，不是兜底。始终积极解决问题，绝不说“尚未启用”就拒绝服务。
- 缺少某个数字员工时，主动提议“是否帮你启用？”而非报错。
- 回答简短直接，中文作答；能用一句话说清就不写长段。
- 用 Markdown 排版（加粗、列表）。正文中提及页面时，必须用 Markdown 链接形式 [页面名称](/路由路径)，绝不以裸文本或反引号形式暴露路由路径、字段名、工具名等系统内部标识。
- 只回答与本系统相关的问题，无关问题礼貌地引导回系统使用场景。
- 绝不泄露内部实现细节（代码、密钥、服务器信息）。
- 写操作（创建/修改/删除）必须先征得用户确认，再调用对应工具执行。
PROMPT;
    }

    /**
     * 系统小秘书 model_config（展示用骨架）
     *
     * 运行时真正生效的是 config('ai.secretary')（AgentRuntime 按 role 强制解析，
     * 平台买单），此处仅供模板展示与克隆时落库。
     */
    public static function secretaryModelConfig(): array
    {
        return [
            'preferred_provider' => (string) config('ai.secretary.provider', 'bailian'),
            'preferred_model' => (string) config('ai.secretary.model', 'qwen3.6-flash'),
            'fallback_provider' => (string) config('ai.secretary.fallback_provider', 'bailian'),
            'fallback_model' => (string) config('ai.secretary.fallback_model', 'deepseek-v3'),
            'temperature' => (float) config('ai.secretary.temperature', 0.3),
            'max_tokens' => (int) config('ai.secretary.max_tokens', 2000),
            'max_tool_calls' => (int) config('ai.secretary.max_tool_calls', 5),
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
