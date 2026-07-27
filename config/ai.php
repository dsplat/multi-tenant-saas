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
        // 阿里云百炼（OpenAI 兼容 dashscope 端点），平台级小秘书默认后端
        'bailian' => [
            'driver' => 'openai',
            'key' => env('AI_BAILIAN_API_KEY', ''),
            'url' => env('AI_BAILIAN_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
            // SaaS 网关层兼容字段
            'base_url' => env('AI_BAILIAN_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
            'api_key' => env('AI_BAILIAN_API_KEY', ''),
            'models' => ['qwen-flash', 'qwen-turbo', 'qwen-plus', 'deepseek-v3', 'mimo-2.5'],
        ],
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
    | - embedding_model: 系统知识库向量化模型（为空或 key 缺失时索引
    |   fail-open 降级为纯关键词检索）
    |
    */
    'secretary' => [
        'enabled' => (bool) env('SECRETARY_ENABLED', true),
        'provider' => env('SECRETARY_AI_PROVIDER', 'bailian'),
        'model' => env('SECRETARY_AI_MODEL', 'qwen-flash'),
        'fallback_provider' => env('SECRETARY_AI_FALLBACK_PROVIDER', 'bailian'),
        'fallback_model' => env('SECRETARY_AI_FALLBACK_MODEL', 'deepseek-v3'),
        'temperature' => (float) env('SECRETARY_AI_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('SECRETARY_AI_MAX_TOKENS', 2000),
        'max_tool_calls' => (int) env('SECRETARY_AI_MAX_TOOL_CALLS', 5),
        'embedding_provider' => env('SECRETARY_EMBEDDING_PROVIDER', 'bailian'),
        'embedding_model' => env('SECRETARY_EMBEDDING_MODEL', 'text-embedding-v3'),
        // 下游扩展模板类（需提供静态 definitions(): array，如 ScrmAgentTemplates），
        // 用于数字员工名录生成与转派路由
        'extra_template_classes' => [],
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
