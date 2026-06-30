<?php

/**
 * Multi-Tenant SaaS PHP SDK 可运行示例
 *
 * 用法：
 *   1. composer require luoyueliang/multi-tenant-saas
 *   2. 填入 BASE_URL 与 API_KEY
 *   3. php docs/examples/php-sdk-sample.php
 *
 * 本示例使用注入的 http_handler 模拟响应，无需真实后端即可运行演示。
 * 将 http_handler 选项移除即对接真实服务。
 */

require __DIR__.'/../../vendor/autoload.php';

use MultiTenantSaas\SDK\Client;
use MultiTenantSaas\SDK\Exceptions\SdkException;

$baseUrl = 'https://api.example.com';
$apiKey = 'sk-tenant-xxx';

// 演示用：注入自定义 HTTP 处理器模拟后端响应
// 真实使用时移除 'http_handler' 选项
$mockHandler = function (string $method, string $url, array $headers, string $body): array {
    $data = match ($method) {
        'GET' => ['success' => true, 'data' => ['name' => '示例企业', 'tenant_id' => '1234567890123456']],
        default => ['success' => true, 'data' => ['content' => '多租户是一套实例服务多个隔离租户的架构。', 'request_id' => '1802000000000000']],
    };

    return [
        'status' => 200,
        'body' => json_encode($data, JSON_UNESCAPED_UNICODE) ?: '',
        'error' => null,
    ];
};

$client = new Client($baseUrl, $apiKey, ['http_handler' => $mockHandler]);

try {
    // 1. 租户查询
    $tenant = $client->tenant()->find(1234567890123456);
    echo "[租户] {$tenant['data']['name']} ({$tenant['data']['tenant_id']})\n";

    // 2. AI 文本补全
    $text = $client->ai()->textCompletion([
        'model' => 'gpt-4o-mini',
        'messages' => [['role' => 'user', 'content' => '一句话介绍多租户']],
    ]);
    echo "[AI 文本] {$text['data']['content']}\n";

    // 3. AI 用量查询
    $usage = $client->ai()->usage(['period' => '2026-06']);
    echo '[AI 用量] success='.($usage['success'] ? 'true' : 'false')."\n";
} catch (SdkException $e) {
    fprintf(STDERR, "[错误] [%s] %s (HTTP %d)\n", $e->getErrorCode(), $e->getMessage(), $e->getStatusCode());
    exit(1);
}
