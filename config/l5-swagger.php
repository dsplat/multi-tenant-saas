<?php

return [
    'default' => [
        'title' => 'Multi-Tenant SaaS Framework API',
        'description' => '多租户 SaaS 框架 API 文档',
        'version' => '1.0.0',

        // 文档生成后存放路径
        'annotations' => [
            'src/',
            'app/Http/Controllers/',
            'app/Http/Resources/',
        ],

        // Swagger UI 路由前缀
        'routes' => [
            'api' => 'api/documentation',
            'asset' => 'api/asset',
            'docs' => 'api/docs',
            'swagger_ui_assets' => 'api/swagger-ui-assets',
            'oauth2_callback' => 'api/oauth2-callback',
        ],

        // 中间件
        'middleware' => [
            'api' => [],
            'asset' => [],
            'docs' => [],
            'swagger_ui_assets' => [],
            'oauth2_callback' => [],
        ],

        // 生成路径
        'generate_always' => env('L5_SWAGGER_GENERATE_ALWAYS', false),
        'proxy' => false,
        'format' => 'json',
        'validate' => env('L5_SWAGGER_VALIDATE_DOCS', false),
        'ui' => [
            'display' => [
                'docExpansion' => 'none',
                'filter' => true,
                'defaultModelRendering' => 'model',
            ],
            'authorization' => [
                'persistAuthorization' => true,
            ],
        ],

        // 安全定义
        'security' => [
            'sanctum' => [
                'type' => 'apiKey',
                'name' => 'Authorization',
                'in' => 'header',
                'description' => 'Bearer Token 认证',
            ],
        ],

        // 外部文档（指向手动维护的 openapi.yaml）
        'external' => [
            'description' => 'Manual OpenAPI YAML',
            'url' => '/docs/api/openapi.yaml',
        ],
    ],
];
