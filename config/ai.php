<?php

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

    // 默认 driver（mock 供本地/测试，openai-compatible 供生产）
    'default' => env('AI_DRIVER', 'mock'),

    // 已注册 driver 实现
    'drivers' => [
        'mock' => \MultiTenantSaas\Services\Ai\Drivers\MockAiDriver::class,
        'openai-compatible' => \MultiTenantSaas\Services\Ai\Drivers\OpenAiCompatibleDriver::class,
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

    // OpenAI 兼容后端 provider 配置
    'providers' => [
        'openai' => [
            'base_url' => env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('AI_OPENAI_API_KEY', ''),
            'models' => [
                'gpt-4o-mini',
                'gpt-4o',
                'gpt-4-turbo',
                'gpt-3.5-turbo',
            ],
        ],
        // 兼容 OpenAI 协议的其它后端，按需复制扩展
        // 'bailian' => [
        //     'base_url' => env('AI_BAILIAN_BASE_URL', 'https://dashscope.aliyuncs.com/compatible-mode/v1'),
        //     'api_key' => env('AI_BAILIAN_API_KEY', ''),
        //     'models' => ['qwen-plus', 'qwen-turbo'],
        // ],
    ],

    // 默认 provider 名称（仅 OpenAiCompatibleDriver 使用）
    'default_provider' => env('AI_PROVIDER', 'openai'),
];
