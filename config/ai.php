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
                'qwen3.8-max-preview', 'qwen3.7-max', 'qwen3.7-plus', 'qwen3.6-plus', 'qwen3.6-flash',
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
            'models' => ['qwen3.7-plus', 'qwen3.6-flash', 'qwen-plus', 'qwen-turbo', 'deepseek-v3.2'],
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
        'model' => env('SECRETARY_AI_MODEL', 'qwen3.6-flash'),
        'fallback_provider' => env('SECRETARY_AI_FALLBACK_PROVIDER', 'bailian'),
        'fallback_model' => env('SECRETARY_AI_FALLBACK_MODEL', 'deepseek-v4-flash'),
        'temperature' => (float) env('SECRETARY_AI_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('SECRETARY_AI_MAX_TOKENS', 2000),
        'max_tool_calls' => (int) env('SECRETARY_AI_MAX_TOOL_CALLS', 5),
        'build_timeout' => (int) env('SECRETARY_BUILD_TIMEOUT', 180),
        'build_max_tokens' => (int) env('SECRETARY_BUILD_MAX_TOKENS', 4000),
        // 下游扩展模板类（需提供静态 definitions(): array，如 ScrmAgentTemplates），
        // 用于数字员工名录生成与转派路由
        'extra_template_classes' => [],
        // 下游扩展设置完善度检查类（需提供 checks(int $tenantId): array，
        // 如 SCRM 的渠道配置检查），供开场引导 setup_checklist 消费
        'extra_setup_checkers' => [],
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
