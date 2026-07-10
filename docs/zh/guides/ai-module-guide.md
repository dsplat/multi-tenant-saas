# AI 模块使用指南

**最后更新**: 2026-06-29

---

## 1. 准备

### 1.1 配置提供商

编辑 `.env`，至少配置一个提供商：

```env
AI_DEFAULT_PROVIDER=openai
AI_DEFAULT_MODEL=gpt-4o-mini
AI_STREAMING_ENABLED=true

# OpenAI（文本 / DALL-E）
AI_OPENAI_API_KEY=sk-xxx

# 智谱（文本）
AI_ZHIPU_API_KEY=xxx

# Anthropic / DeepSeek（文本）
AI_ANTHROPIC_API_KEY=xxx
AI_DEEPSEEK_API_KEY=xxx

# Stability（图像）
AI_STABILITY_API_KEY=xxx

# Runway / 可灵（视频）
AI_RUNWAY_API_KEY=xxx
AI_KLING_API_KEY=xxx
```

### 1.2 租户级配置

租户级配置由 `AiConfigService` 管理，默认值取自 `config/ai.php` 的 `tenant.*` 节：

```php
use MultiTenantSaas\Services\AiConfigService;

$config = app(AiConfigService::class)->getOrCreateConfig();

// 开关能力
$config = app(AiConfigService::class)->enableCategory('text');
$config = app(AiConfigService::class)->disableCategory('video');

// 自定义 API Key（加密存储）
$config = app(AiConfigService::class)->setCustomApiKey('openai', 'sk-tenant-xxx');

// 模型白名单
$config = app(AiConfigService::class)->setAllowedModels(['gpt-4o-mini', 'dall-e-3']);

// 月度预算与超额策略（block / warn / allow）
$config = app(AiConfigService::class)->setMonthlyBudgetLimit(100.00);
$config = app(AiConfigService::class)->setOverageAction('block');
```

> 租户自定义 Key 优先于全局 Key；未设置时回退到 `config('ai.providers.{provider}.api_key')`。

---

## 2. 文本 AI

### 2.1 基础对话

```php
use MultiTenantSaas\Services\AiTextService;

$text = app(AiTextService::class);

// 使用指定模型
$result = $text->chat('gpt-4o-mini', [
    ['role' => 'system', 'content' => '你是多租户 SaaS 助手'],
    ['role' => 'user', 'content' => '什么是租户隔离？'],
], ['temperature' => 0.7, 'max_tokens' => 512]);

echo $result['content']; // "租户隔离是指..."
echo $result['usage']['output_tokens'];
```

### 2.2 默认模型

```php
// 使用 config('ai.text.default_chat_model')
$result = $text->chatDefault($messages);

// 强制 JSON 输出（结构化解析）
$result = $text->chatJsonDefault([
    ['role' => 'user', 'content' => '返回 {summary, tags} JSON'],
]);
$data = json_decode($result['content'], true);
```

### 2.3 流式输出

```php
foreach ($text->streamChatDefault($messages) as $chunk) {
    echo $chunk['delta'];
    @ob_flush();
}
```

### 2.4 向量嵌入

```php
$vec = $text->embedDefault('要嵌入的文本');
// $vec['embedding'] = [0.0123, -0.0456, ...];
```

### 2.5 Prompt 模板

```php
// 创建模板
$prompt = $text->createPrompt([
    'name' => '客服摘要',
    'category' => 'service',
    'content' => '请将以下对话总结为不超过 50 字：{{conversation}}',
    'variables' => ['conversation'],
]);

// 渲染并发起对话
$result = $text->chatWithPrompt($prompt->ai_prompt_id, [
    'conversation' => '用户：退款怎么操作？ 客服：...',
]);
```

---

## 3. 图像 AI

```php
use MultiTenantSaas\Services\AiImageService;

$image = app(AiImageService::class);

// 文生图
$result = $image->textToImage('赛博朋克风格的城市夜景', [
    'provider' => 'dalle',
    'model' => 'dall-e-3',
    'size' => '1024x1024',
    'quality' => 'hd',
    'n' => 1,
]);
// $result['urls'][0]  $result['file_upload_id']

// 图生图（基于已上传文件）
$result = $image->imageToImage($fileUploadId, '改为水彩画风格');

// 蒙版编辑
$result = $image->editImage($fileUploadId, $maskFileId, '把背景换成海边');

// 风格迁移
$result = $image->styleTransfer($fileUploadId, '梵高星空');
```

> 结果按 `config('ai.image.storage_disk')` / `storage_category` 自动落盘，并生成 `FileUpload` 记录。

---

## 4. 视频 AI（异步）

```php
use MultiTenantSaas\Services\AiVideoService;

$video = app(AiVideoService::class);

// 提交文生视频任务
$task = $video->textToVideo('日落延时摄影', [
    'provider' => 'runway',
    'model' => 'gen-3',
    'duration' => 5,
    'resolution' => '1280x768',
    'fps' => 24,
]);
// $task['request_id']  $task['status'] = 'pending'

// 查询状态
$status = $video->getTask($task['request_id']);
// status: pending | processing | completed | failed
```

视频生成完成后：

1. 队列按 `poll_interval_seconds` 延迟轮询（`pollTask`）
2. 完成分发 `ai.video.task.updated` 事件
3. 结果存入对象存储，生成 `FileUpload` 记录
4. 订阅该事件的 Webhook 自动推送

监听事件示例（派生项目 `EventServiceProvider`）：

```php
Event::listen('ai.video.task.updated', function (array $payload) {
    // $payload['request_id']  $payload['status']  $payload['result_url']
});
```

---

## 5. 用量与配额

```php
use MultiTenantSaas\Services\AiUsageService;

$usage = app(AiUsageService::class);

// 当前周期配额
$quota = $usage->getOrCreateCurrentQuota();

// 调用前检查（配额/预算不足抛异常）
$usage->checkQuota('text');
$usage->checkBudget();

// 用量汇总
$summary = $usage->getUsageSummary();
// ['spend' => 12.34, 'period' => '2026-06', ...]

$byModel = $usage->getUsageByModel();
// ['gpt-4o-mini' => [...], 'dall-e-3' => [...]]
```

超额策略（`config('ai.tenant.default_overage_action')`）：

| 策略 | 行为 |
|------|------|
| `block` | 拒绝请求（默认） |
| `warn` | 告警但放行，继续计费 |
| `allow` | 放行并计费 |

达到 `quota.warn_threshold`（默认 0.8）时触发告警（`AlertService`）。

---

## 6. 通过 SDK 调用

```php
use MultiTenantSaas\SDK\Client;

$client = new Client('https://api.example.com', 'sk-tenant-xxx');

// 文本
$text = $client->ai()->textCompletion([
    'model' => 'gpt-4o-mini',
    'messages' => [['role' => 'user', 'content' => '你好']],
]);

// 图像
$img = $client->ai()->imageGeneration(['prompt' => '一只猫']);

// 视频（异步）
$task = $client->ai()->videoGeneration(['prompt' => '日落']);

// 用量
$usage = $client->ai()->usage(['period' => '2026-06']);
```

详见 [SDK 示例](../examples/php-sdk-quickstart.md)。

---

## 7. 配置参考

| 环境变量 | 默认值 | 说明 |
|----------|--------|------|
| `AI_DEFAULT_PROVIDER` | `openai` | 默认提供商 |
| `AI_DEFAULT_MODEL` | `gpt-4o-mini` | 默认模型 |
| `AI_STREAMING_ENABLED` | `true` | 流式总开关 |
| `AI_TIMEOUT` | `30` | 请求超时（秒） |
| `AI_RETRY_ATTEMPTS` | `2` | 重试次数 |
| `AI_RATE_LIMIT_ENABLED` | `false` | 速率限制开关 |
| `AI_RATE_LIMIT_RPM` | `60` | 每分钟最大请求数 |
| `AI_TEXT_MAX_INPUT_LENGTH` | `16000` | 文本最大输入长度 |
| `AI_IMAGE_MAX_PROMPT_LENGTH` | `4000` | 图像 prompt 最大长度 |
| `AI_VIDEO_POLL_INTERVAL` | `10` | 视频轮询间隔（秒） |
| `AI_VIDEO_MAX_POLL_ATTEMPTS` | `120` | 视频最大轮询次数 |
| `AI_TENANT_MONTHLY_BUDGET` | `0` | 租户默认月度预算（0=不限） |
| `AI_TENANT_OVERAGE_ACTION` | `block` | 默认超额策略 |

---

**文档版本**: v1.0.0
