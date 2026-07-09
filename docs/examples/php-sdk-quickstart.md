# PHP SDK 使用示例

**最后更新**: 2026-06-29

---

## 1. 安装

PHP SDK 随框架包发布，独立于 Laravel，仅依赖 PHP 8.2+ 与 ext-curl。

```bash
composer require dsplat/multi-tenant-saas
```

## 2. 初始化客户端

```php
use MultiTenantSaas\SDK\Client;

$client = new Client(
    baseUrl: 'https://api.example.com',
    apiKey: 'sk-tenant-xxx',
    options: [
        'timeout' => 30,
        'retries' => 3,
        'retry_base_delay_ms' => 500,
        'api_prefix' => '/v1',
    ]
);
```

> 鉴权：每个请求自动携带 `Authorization: Bearer <apiKey>`。SDK 对 5xx 与连接错误自动重试，4xx 不重试。

## 3. 租户管理

```php
$tenant = $client->tenant()->find(1234567890123456);
$members = $client->tenant()->members(1234567890123456, ['page' => 1]);
```

## 4. 支付

```php
$order = $client->payment()->createOrder([
    'tenant_id' => 1234567890123456,
    'driver' => 'alipay',
    'amount' => 99.00,
    'subject' => 'Pro 订阅',
    'out_trade_no' => 'ORD' . time(),
]);

$refund = $client->payment()->refund([
    'out_trade_no' => 'ORD...',
    'refund_amount' => 99.00,
]);
```

## 5. AI 调用

```php
$ai = $client->ai();

// 文本补全
$text = $ai->textCompletion([
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system', 'content' => '你是助手'],
        ['role' => 'user', 'content' => '一句话介绍多租户'],
    ],
    'options' => ['temperature' => 0.7, 'max_tokens' => 256],
]);
echo $text['data']['content'];

// 图像生成
$image = $ai->imageGeneration([
    'prompt' => '赛博朋克猫',
    'options' => ['model' => 'dall-e-3', 'size' => '1024x1024'],
]);
echo $image['data']['urls'][0];

// 视频生成（异步提交，返回 request_id）
$task = $ai->videoGeneration([
    'prompt' => '日落延时',
    'options' => ['provider' => 'runway', 'duration' => 5],
]);
echo $task['data']['request_id'];

// 查询用量
$usage = $ai->usage(['period' => '2026-06']);
print_r($usage['data']['summary']);
```

## 6. 错误处理

```php
use MultiTenantSaas\SDK\Exceptions\SdkException;

try {
    $result = $client->ai()->textCompletion($payload);
} catch (SdkException $e) {
    // $e->getStatusCode()  $e->getErrorCode()  $e->getContext()
    echo '[' . $e->getErrorCode() . '] ' . $e->getMessage();
}
```

常见错误码：`ai_quota_exceeded`（429）、`ai_model_not_allowed`（403）、`ai_provider_error`（502）、`server_error`（5xx 重试耗尽）。

## 7. 自定义 HTTP 处理器（测试/拦截）

```php
$client = new Client('https://api.example.com', 'sk-xxx', [
    'http_handler' => function (string $method, string $url, array $headers, string $body): array {
        // 返回 ['status' => 200, 'body' => '{"success":true,"data":{...}}', 'error' => null]
        return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => []]), 'error' => null];
    },
]);
```

> 完整可运行示例见 [php-sdk-sample.php](php-sdk-sample.php)。
