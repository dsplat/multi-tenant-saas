<?php

/**
 * AiStreaming 模块配置（config key: ai-streaming）
 *
 * Node SSE 引擎 + PHP 契约 API 的运行参数。
 * 所有值均可通过 .env 覆盖，无需发布配置文件。
 */

return [

    /*
    |--------------------------------------------------------------------------
    | 总开关
    |--------------------------------------------------------------------------
    |
    | 关闭时契约 API 返回 503，Node 引擎 resolve 失败即拒绝服务。
    |
    */

    'enabled' => (bool) env('AI_STREAMING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Node 引擎监听地址
    |--------------------------------------------------------------------------
    |
    | Node 单文件引擎（dist/server.mjs）监听的地址。仅供 nginx 反代访问，
    | 不应暴露公网。ai-streaming:nginx / :install 命令据此生成配置。
    |
    */

    'node' => [
        'host' => env('AI_STREAMING_NODE_HOST', '127.0.0.1'),
        'port' => (int) env('AI_STREAMING_NODE_PORT', 9200),
    ],

    /*
    |--------------------------------------------------------------------------
    | nginx 公开路径
    |--------------------------------------------------------------------------
    |
    | 浏览器访问的 SSE 入口路径，nginx location 将其反代到 Node 引擎。
    | 前端请求：POST {public_path}/chat （SSE 响应）
    |
    */

    'public_path' => env('AI_STREAMING_PUBLIC_PATH', '/ai-stream'),

    /*
    |--------------------------------------------------------------------------
    | PHP 契约 API 回调基址
    |--------------------------------------------------------------------------
    |
    | Node 引擎回调 PHP（resolve / tools/execute / usage/report）的基址。
    | 同机部署走本地回环即可，避免出公网绕一圈。
    |
    */

    'php_api_base' => env('AI_STREAMING_PHP_API_BASE', 'http://127.0.0.1/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | API Key 下发策略
    |--------------------------------------------------------------------------
    |
    | direct: resolve 响应中直接下发 provider api_key（Node 与 PHP 同机/内网，
    |         key 只在 127.0.0.1 回环中传输，不出网）。默认。
    | none:   不下发 key，Node 侧须自备环境变量（AI_STREAMING_API_KEY），
    |         适用于 Node 与 PHP 分离部署且不信任中间链路的场景。
    |
    */

    'key_delivery' => env('AI_STREAMING_KEY_DELIVERY', 'direct'),

    /*
    |--------------------------------------------------------------------------
    | 流式参数
    |--------------------------------------------------------------------------
    */

    // 单次对话最大工具调用轮数上限（Agent model_config 未指定时的默认值）
    'max_tool_calls' => (int) env('AI_STREAMING_MAX_TOOL_CALLS', 5),

    // SSE 空闲超时（秒），nginx proxy_read_timeout 据此生成
    'idle_timeout' => (int) env('AI_STREAMING_IDLE_TIMEOUT', 120),

];
