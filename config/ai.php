<?php

use MultiTenantSaas\Modules\Ai\Services\Ai\Drivers\LaravelAiDriverAdapter;
use MultiTenantSaas\Modules\Ai\Services\Ai\Drivers\MockAiDriver;

return [
    /*
    |--------------------------------------------------------------------------
    | AI 推理服务配置
    |--------------------------------------------------------------------------
    |
    | 多租户 SaaS 的 AI 推理服务（AiTextService）统一配置。
    | 通过 drivers 抽象支持多种推理后端，AgentRuntime 通过
    | AiTextServiceContract 调用，与具体后端解耦。
    |
    | - default: 默认 driver 名称，对应 drivers 列表中的 key
    | - drivers: 已注册的 driver 实现（name => class）
    | - providers: OpenAI 兼容后端配置（base_url / api_key / models）
    | - default_model: 默认模型名称
    | - timeout: HTTP 请求超时秒数
    | - retry: 失败重试次数（含首次）
    |
    */

    // 默认 driver（生产环境使用 laravel-ai，测试使用 mock）
    'default' => env('AI_DRIVER', 'laravel-ai'),

    // 已注册 driver 实现
    'drivers' => [
        'mock' => MockAiDriver::class,
        'laravel-ai' => LaravelAiDriverAdapter::class,
    ],

    // 默认模型（driver 未显式指定时使用）
    'default_model' => env('AI_MODEL', 'gpt-4o-mini'),

    /*
    | AiTextService 默认模型（chatDefault/completeDefault/embedDefault）
    | 缺省回退 AI_MODEL，避免新环境硬编码 gpt-4o-mini 导致 Unsupported model。
    */
    'text' => [
        'default_chat_model' => env('AI_TEXT_CHAT_MODEL', env('AI_MODEL', 'gpt-4o-mini')),
        'default_completion_model' => env('AI_TEXT_COMPLETION_MODEL', env('AI_MODEL', 'gpt-4o-mini')),
        'default_embedding_model' => env('AI_TEXT_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],

    // 默认请求超时（秒）
    'timeout' => (int) env('AI_TIMEOUT', 60),

    // 失败重试次数（含首次请求，>=1）
    'retry' => [
        'times' => (int) env('AI_RETRY_TIMES', 1),
        'sleep_ms' => (int) env('AI_RETRY_SLEEP_MS', 200),
    ],

    // Provider 配置（同时兼容 SaaS 网关与 laravel/ai）
    // - base_url / api_key / models：SaaS 网关层（AiGatewayService → Provider）使用
    // - driver / key / url：laravel/ai SDK 使用
    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY', env('AI_OPENAI_API_KEY', '')),
            'url' => env('OPENAI_URL', env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1')),
            // SaaS 网关层兼容字段
            'base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('AI_OPENAI_API_KEY', ''),
            'models' => ['gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo'],
        ],
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY', ''),
        ],
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY', ''),
        ],
        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY', ''),
        ],
        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY', ''),
        ],
        // 阿里云百炼主通道（OpenAI 兼容端点），平台级小秘书默认后端。
        // 生产环境指向 Token Plan 包量套餐端点（token-plan.cn-beijing.maas.aliyuncs.com），
        // models 为离线兜底清单，真实可用清单以 ai:models:sync 拉取的动态缓存为准。
        'bailian' => [
            'driver' => 'openai',
            'key' => env('AI_BAILIAN_API_KEY', ''),
            'url' => env('AI_BAILIAN_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
            // SaaS 网关层兼容字段
            'base_url' => env('AI_BAILIAN_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
            'api_key' => env('AI_BAILIAN_API_KEY', ''),
            'models' => [
                'qwen3.8-max-preview', 'qwen3.7-max', 'qwen3.7-plus', 'qwen3.7-flash', 'qwen3.6-plus', 'qwen3.6-flash',
                'deepseek-v4-pro', 'deepseek-v4-flash', 'deepseek-v3.2',
                'glm-5', 'glm-5.1', 'glm-5.2',
                'kimi-k2.5', 'kimi-k2.6', 'kimi-k2.7-code', 'MiniMax-M2.5',
                'qwen-image-2.0', 'qwen-image-2.0-pro', 'wan2.7-image', 'wan2.7-image-pro',
            ],
        ],
        // 阿里云百炼按量付费备用通道（dashscope 按量 key），仅套餐不可用时应急切换
        'bailian_metered' => [
            'driver' => 'openai',
            'key' => env('AI_BAILIAN_METERED_API_KEY', ''),
            'url' => env('AI_BAILIAN_METERED_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
            // SaaS 网关层兼容字段
            'base_url' => env('AI_BAILIAN_METERED_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
            'api_key' => env('AI_BAILIAN_METERED_API_KEY', ''),
            'models' => ['qwen3.7-plus', 'qwen3.7-flash', 'qwen3.6-flash', 'qwen-plus', 'qwen-turbo', 'deepseek-v3.2'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 动态模型清单（ai:models:sync）
    |--------------------------------------------------------------------------
    |
    | AiModelCatalogService 调 provider 的 /models 端点拉取真实可用清单并
    | 缓存（TTL 默认 1 天），providers.*.models 手写数组仅作网络不可达时的
    | 离线兜底。定时刷新：php artisan ai:models:sync。
    |
    */
    'model_catalog' => [
        'cache_ttl' => (int) env('AI_MODEL_CATALOG_TTL', 86400),
        'timeout' => (int) env('AI_MODEL_CATALOG_TIMEOUT', 15),
    ],

    // 默认 provider 名称（仅 laravel/ai SDK 内部使用）
    'default_provider' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | 数字员工模板默认值（单一事实源）
    |--------------------------------------------------------------------------
    |
    | BuiltinAgentTemplates::defaultModelConfig() 全量读本段，
    | 8 个角色骨架模板的 model_config 不再硬编码数值。
    |
    */
    'agents' => [
        'defaults' => [
            'temperature' => (float) env('AI_AGENT_TEMPERATURE', 0.7),
            'max_tokens' => (int) env('AI_AGENT_MAX_TOKENS', 2000),
            'max_tool_calls' => (int) env('AI_AGENT_MAX_TOOL_CALLS', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 系统小秘书（第 0 号数字员工）平台级配置
    |--------------------------------------------------------------------------
    |
    | 小秘书是框架内置的总入口数字员工，模型费用由平台买单（不消耗租户
    | 任何积分/token）。模板 0 的 model_config 不写死，AgentRuntime 运行时
    | 从本段解析——换模型只改 .env，零代码零数据变更。
    |
    | - enabled: 平台级总开关（关闭时前端零入口、后端拒绝会话）
    | - provider/model: 主模型（默认阿里云百炼 qwen-flash）
    | - fallback_provider/fallback_model: 降级模型
    | - build_timeout/build_max_tokens: kb:build 起草器超时与 token 上限
    |
    */
    'secretary' => [
        'enabled' => (bool) env('SECRETARY_ENABLED', true),
        'provider' => env('SECRETARY_AI_PROVIDER', 'bailian'),
        'model' => env('SECRETARY_AI_MODEL', 'qwen3.7-flash'),
        'fallback_provider' => env('SECRETARY_AI_FALLBACK_PROVIDER', 'bailian'),
        'fallback_model' => env('SECRETARY_AI_FALLBACK_MODEL', 'deepseek-v4-flash'),
        'temperature' => (float) env('SECRETARY_AI_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('SECRETARY_AI_MAX_TOKENS', 2000),
        // 默认 10：thread_review→kb_search→draft 多步推理链会触顶旧默认 5
        'max_tool_calls' => (int) env('SECRETARY_AI_MAX_TOOL_CALLS', 10),
        'build_timeout' => (int) env('SECRETARY_BUILD_TIMEOUT', 180),
        'build_max_tokens' => (int) env('SECRETARY_BUILD_MAX_TOKENS', 4000),
        // 下游扩展模板类（需提供静态 definitions(): array，如 ScrmAgentTemplates），
        // 用于数字员工名录生成与转派路由
        'extra_template_classes' => [],
        // 下游扩展设置完善度检查类（需提供 checks(int $tenantId): array，
        // 如 SCRM 的渠道配置检查），供开场引导 setup_checklist 消费
        'extra_setup_checkers' => [],
    ],

    // 小助手附件能力配置
    'assistant' => [
        // 图片内容识别（视觉模型）：provider 置空则图片附件被拒绝并提示改传文档
        'image_extract' => [
            'provider' => env('AI_ASSISTANT_VISION_PROVIDER', 'bailian'),
            'model' => env('AI_ASSISTANT_VISION_MODEL', 'qwen-vl-plus'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 内容安全守护（安全狗）
    |--------------------------------------------------------------------------
    |
    | 用户输入进 LLM 前的第一道闸（ContentGuardService）：本地轻量扫描，
    | 命中即礼貌拒绝。内置规则见服务类 BUILTIN_PATTERNS；此处仅控制
    | 开关与追加词表。守护自身故障时降级放行（AI 可选性铁律）。
    |
    */
    'content_guard' => [
        'enabled' => (bool) env('AI_CONTENT_GUARD_ENABLED', true),
        // 追加拦截关键词（归一化后精确包含匹配），如环境特有的违规词
        'keywords' => array_filter(array_map('trim', explode(',', (string) env('AI_CONTENT_GUARD_KEYWORDS', '')))),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP 协议适配器（Bridge）配置
    |--------------------------------------------------------------------------
    |
    | ToolRegistryMcpBridge 将 ToolRegistry 工具暴露为 MCP tools/list、
    | tools/call 端点。以下配置控制 MCP 通道的风险策略。
    |
    | - l2_policy: L2（写操作）工具的 MCP 调用策略
    |   - 'deny'（默认）：直接拒绝，fail-closed
    |   - 'confirm_token'：校验预授权令牌（预留，尚未实现）
    |
    */
    'mcp' => [
        // L2（写操作）工具的 MCP 调用策略
        // - 'rbac'：检查 RBAC 权限节点 mcp.execute_l2 + 频率限制（默认）
        // - 'deny'：直接拒绝（紧急熔断用）
        'l2_policy' => env('AI_MCP_L2_POLICY', 'rbac'),

        // L2 工具限流（每 operator 每 10 分钟上限）
        'l2_rate_limit' => (int) env('AI_MCP_L2_RATE_LIMIT', 10),

        // 工具白名单（null = 暴露全部; array = 仅暴露指定 slug）
        'tool_whitelist' => null,

        // 工具黑名单（优先于白名单，命中即不可见）
        'tool_blacklist' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | laravel/ai SDK 默认模型
    |--------------------------------------------------------------------------
    |
    | laravel/ai 的 Image / Audio / Transcription / Embeddings / Reranking
    | 各能力的默认模型与 provider。
    |
    */
    'image' => [
        'provider' => env('AI_IMAGE_PROVIDER', 'openai'),
        'model' => env('AI_IMAGE_MODEL', 'dall-e-3'),
    ],
    'audio' => [
        'provider' => env('AI_AUDIO_PROVIDER', 'openai'),
        'model' => env('AI_AUDIO_MODEL', 'tts-1'),
        'voice' => env('AI_AUDIO_VOICE', 'alloy'),
    ],
    'transcription' => [
        'provider' => env('AI_TRANSCRIPTION_PROVIDER', 'openai'),
        'model' => env('AI_TRANSCRIPTION_MODEL', 'whisper-1'),
    ],
    'embeddings' => [
        'provider' => env('AI_EMBEDDINGS_PROVIDER', 'openai'),
        'model' => env('AI_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
    ],
    'reranking' => [
        'provider' => env('AI_RERANKING_PROVIDER', 'cohere'),
        'model' => env('AI_RERANKING_MODEL', 'rerank-multilingual-v3.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ibot 随身 AI 小助理（IM 机器人）
    |--------------------------------------------------------------------------
    |
    | operator 扫码绑定 IM 机器人（P0: Telegram long polling），入向消息
    | 经 IbotGateway → Job → AgentRuntime。设计稿 docs/ibot.md。
    |
    | - enabled: 平台级总开关（默认关闭，AI 可选性铁律）
    | - bind_code_ttl: 绑定码有效期（秒）
    | - telegram.api_base: Bot API 地址（测试可指向 fake）
    | - telegram.poll_timeout: long polling 长连接等待秒数
    | - extra_channels: 下游扩展频道映射 channel_type => 实现类
    |
    */
    'ibot' => [
        'enabled' => (bool) env('AI_IBOT_ENABLED', false),
        'bind_code_ttl' => (int) env('AI_IBOT_BIND_CODE_TTL', 600),
        // IM 内 L2 文本确认令牌有效期（秒）：比 Web 确认卡片（300s）放宽，IM 回复节奏更慢
        'confirm_ttl' => (int) env('AI_IBOT_CONFIRM_TTL', 600),
        'telegram' => [
            'api_base' => env('AI_IBOT_TELEGRAM_API_BASE', 'https://api.telegram.org'),
            'poll_timeout' => (int) env('AI_IBOT_TELEGRAM_POLL_TIMEOUT', 30),
            // 出站代理（仅 Telegram API）：国内服务器直连不通时配置，如 socks5h://127.0.0.1:1080
            'proxy' => env('AI_IBOT_TELEGRAM_PROXY'),
        ],
        'extra_channels' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaign 排期引擎（docs/event-plan.md Phase 0）
    |--------------------------------------------------------------------------
    |
    | 平台级开关：AI_CAMPAIGN_ENABLED=true 启用 campaign:process-due 调度。
    | 默认关闭（AI 可选性铁律）。
    |
    */
    'campaign' => [
        'enabled' => (bool) env('AI_CAMPAIGN_ENABLED', false),
        'extra_playbook_classes' => [],
        // on_event 任务监听的事件类列表（下游通过 ServiceProvider 追加业务事件）
        // 仅列入此处的事件类才会被 CampaignEventSubscriber 监听
        'listen_events' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | 预设任务链引擎（docs/task-chain.md Phase 1）
    |--------------------------------------------------------------------------
    |
    | 平台级开关：AI_TASK_CHAINS_ENABLED=true 启用三个链工具注册。
    | 默认关闭（AI 可选性铁律，引擎关闭不影响主链路）。
    | extra_chain_classes：下游扩展链定义类（静态 chains(): array，
    | 与数字员工模板 extra_template_classes 扩展模式一致）。
    |
    */
    'task_chains' => [
        'enabled' => (bool) env('AI_TASK_CHAINS_ENABLED', false),
        'extra_chain_classes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | 项目大脑（工作脉络跟踪与主动推理）
    |--------------------------------------------------------------------------
    |
    | enabled：活跃脉络摘要注入（resolve）总开关，默认关闭（AI 可选性铁律）。
    | background_reasoning：thread:health-check 巡检异常脉络后追加一次 LLM
    | 分析（可选增强），默认关闭；巡检本身纯规则零 LLM 不受此开关影响。
    | billing：后台推理计费主体 platform（平台买单）/ tenant（租户买单），
    | 用量始终记入真实 tenant_id（scenario=brain），与秘书通道分流。
    | 硬限额：每脉络每日最多一次后台推理、单次 token 上限。
    |
    */
    'brain' => [
        'enabled' => (bool) env('AI_BRAIN_ENABLED', false),
        'background_reasoning' => (bool) env('AI_BRAIN_BACKGROUND_REASONING', false),
        'billing' => env('AI_BRAIN_BILLING', 'platform'),
        'max_daily_runs_per_thread' => (int) env('AI_BRAIN_MAX_DAILY_RUNS_PER_THREAD', 1),
        'max_tokens_per_run' => (int) env('AI_BRAIN_MAX_TOKENS_PER_RUN', 4096),
        // 下游资产探测器类列表（实现 ThreadAssetProbeContract，供 thread_review
        // 聚合锚点对象的关联资产与完整度事实，与 extra_chain_classes 扩展模式一致）
        'asset_probes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | laravel/ai 会话存储配置
    |--------------------------------------------------------------------------
    |
    | 配置 laravel/ai SDK 的 RemembersConversations 功能使用的表名。
    | 使用项目 IdGenerator（16位数字ID）替代 laravel/ai 默认的 UUID7。
    | 与业务层 agent_conversations 表分离，专供 SDK 内部使用。
    |
    */
    'conversations' => [
        'connection' => env('AI_CONVERSATIONS_CONNECTION'),
        'tables' => [
            'conversations' => 'laravel_ai_conversations',
            'messages' => 'laravel_ai_messages',
        ],
    ],
];
