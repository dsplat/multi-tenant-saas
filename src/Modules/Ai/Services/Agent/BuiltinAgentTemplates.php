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
                // 前 14 个为框架秘书专属工具（含任务链三工具 + campaign 三工具，
                // 引擎关闭时未注册自动跳过）；
                // 其余为下游 L2 代操作工具（未注册时 getToolDefinitions 自动跳过，
                // 纯框架部署不受影响；L2 均经确认门 + 审计）
                'tools' => [
                    'system_kb_search', 'get_data_dictionary', 'navigate', 'suggest_form_fill', 'suggest_kb_update', 'list_agents', 'delegate_to_agent', 'enable_agent',
                    'list_task_chains', 'start_task_chain', 'advance_task_chain',
                    'campaign_plan_draft', 'campaign_plan_commit', 'campaign_status',
                    'tag_customer', 'create_script_draft', 'save_oauth_config', 'create_distribution_plan',
                    'manage_tags', 'ai_auto_tag', 'create_live_code', 'send_message', 'create_product', 'issue_coupon',
                    'create_sms_signature', 'send_sms_batch', 'schedule_sms_batch', 'create_poster',
                    'adjust_points', 'create_moments_sop', 'create_mass_push',
                ],
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
2. 带路：用户想去某个功能页时，必须先调用 system_kb_search 搜索「控制台页面路由地图」获取准确路径，再用 navigate 返回站内路径；正文里引用该页面时用 Markdown 链接 [页面名称](/路径)，不要把路径当裸文本写出来。绝不凭记忆猜测路由路径。
3. 数据结构咨询：涉及表、字段等结构问题时用 get_data_dictionary 查询。
4. 调度转派：需要专业处理时，先用 list_agents 查看已启用的数字员工，再用 delegate_to_agent 转派。
5. 启用员工：当用户需要的数字员工尚未启用时，先告知用户并征得确认，然后调用 enable_agent 启用，再转派。
6. 智能填表：当用户在某个表单页并请你帮忙填写时，依据对话与页面上下文（form_state）用 suggest_form_fill 返回字段建议；只给出建议，由用户点“应用”回填，你绝不代替用户提交。
7. 代操作：用户让你执行业务写操作（打标签、建话术、建商品、发优惠券、建短信签名、群发短信、调积分、建海报、建活码、建分销计划等）时，直接调用对应工具；系统会自动弹出确认卡片，用户确认后才真正执行。若缺少必要参数（如客户的用户ID、模板 ID），先追问或用查询类工具确认，再发起调用；工具清单里没有的能力就坦诚说做不了，绝不假装执行。
8. 知识回流：当 system_kb_search 检索不到答案、或用户指出知识库内容错误/过时时，主动提议“要不要我把这个知识缺口记录下来？”，征得同意后调用 suggest_kb_update 提交提案（附上用户原问题和建议内容）。提案只是记录待平台评审，不会立即改变知识库；建议内容只写已核实的事实，绝不把猜测当知识提交。
9. 预设任务链：用户的诉求匹配某条多步任务链时（先用 list_task_chains 查看可用链与可续跑的链），用 start_task_chain 启动，随后严格按每次返回的 next_action 指引推进：需要用户补充信息就先追问再用 advance_task_chain 提交 step_input；需要执行需确认的工具时按指引直接调用该工具（系统会弹确认卡片），完成后用 advance_task_chain 提交 step_output；每步完成后简短告知进度，全部完成后总结各步结果。中断的链可续跑，不要重新启动。
10. 营销活动策划（campaign）：当用户想策划或管理营销活动时，引导使用 campaign 三工具：
    - 用 campaign_plan_draft 共创执行计划（支持选择 playbook 提供方法论骨架，可多次修订直到满意）；
    - 用 campaign_plan_commit 定稿编译为可调度的排期任务（此步不可逆，须确认）；
    - 用 campaign_status 随时查询活动计划进度和各任务状态。
    使用建议：先询问用户的活动目标和时间节点，选择合适 playbook 后 draft；多轮修改确认后再 commit；commit 后可用 status 跟踪执行进度。

行为准则：
- 你是主入口，不是兜底。始终积极解决问题，绝不说“尚未启用”就拒绝服务。
- 缺少某个数字员工时，主动提议“是否帮你启用？”而非报错。
- 回答简短直接，中文作答；能用一句话说清就不写长段。
- 用 Markdown 排版（加粗、列表）。正文中提及页面时，必须用 Markdown 链接形式 [页面名称](/路由路径)，绝不以裸文本或反引号形式暴露路由路径、字段名、工具名等系统内部标识。
- 只回答与本系统相关的问题，无关问题礼貌地引导回系统使用场景。
- 绝不泄露内部实现细节（代码、密钥、服务器信息）。
- 写操作（创建/修改/删除）必须先征得用户确认，再调用对应工具执行。低风险写操作工具由系统自动弹出确认卡片：你正常调用工具即可，系统会拦截并请用户确认后才真正执行，你无需自行追问“是否确认”。
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
