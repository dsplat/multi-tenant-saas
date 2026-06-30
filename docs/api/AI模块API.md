# AI 模块 API 参考

**最后更新**: 2026-06-29

---

## 概述

AI 模块以服务层形式提供能力，并通过 PHP SDK（`MultiTenantSaas\SDK\AiResource`）暴露 HTTP 端点。本文档覆盖服务层 API、SDK 资源与 HTTP 端点三部分。

---

## 一、服务层 API

### AiGatewayService

统一网关入口，按 provider 路由。

```php
$gateway = app(\MultiTenantSaas\Services\AiGatewayService::class);
```

| 方法 | 签名 | 返回 | 说明 |
|------|------|------|------|
| `chat` | `(string $model, array $messages, array $options = []): array` | `array` | 对话补全 |
| `complete` | `(string $model, string $prompt, array $options = []): array` | `array` | 文本补全 |
| `embed` | `(string $model, string\|array $input, array $options = []): array` | `array` | 向量嵌入 |
| `streamChat` | `(string $model, array $messages, array $options = []): \Generator` | `Generator` | 流式对话，逐 chunk yield |

`$options` 常用键：`temperature`、`max_tokens`、`json_mode`、`provider`（覆盖默认提供商）。

### AiTextService

```php
$text = app(\MultiTenantSaas\Services\AiTextService::class);
```

| 方法 | 说明 |
|------|------|
| `chat($model, $messages, $options = [])` | 指定模型对话 |
| `complete($model, $prompt, $options = [])` | 指定模型补全 |
| `embed($model, $input, $options = [])` | 指定模型嵌入 |
| `streamChat($model, $messages, $options = [])` | 指定模型流式对话 |
| `chatJson($model, $messages, $options = [])` | 强制 JSON 模式输出 |
| `chatDefault($messages, $options = [])` | 使用默认 chat 模型 |
| `completeDefault($prompt, $options = [])` | 使用默认 completion 模型 |
| `embedDefault($input, $options = [])` | 使用默认 embedding 模型 |
| `streamChatDefault($messages, $options = [])` | 默认模型流式 |
| `chatJsonDefault($messages, $options = [])` | 默认模型 JSON 模式 |

**Prompt 模板管理**：

| 方法 | 说明 |
|------|------|
| `createPrompt(array $data): AiPrompt` | 创建模板 |
| `updatePrompt($id, array $data): AiPrompt` | 更新 |
| `deletePrompt($id): bool` | 删除 |
| `getPrompt($id): ?AiPrompt` | 获取 |
| `findByName(string $name): ?AiPrompt` | 按名查询 |
| `listPrompts(?string $category = null): Collection` | 列表（可按分类） |
| `render(AiPrompt $prompt, array $variables): array` | 渲染变量 |
| `chatWithPrompt($promptId, $variables, $options = [])` | 用模板发起对话 |

### AiImageService

```php
$image = app(\MultiTenantSaas\Services\AiImageService::class);
```

| 方法 | 签名 | 说明 |
|------|------|------|
| `textToImage` | `(string $prompt, array $options = []): array` | 文生图 |
| `imageToImage` | `($fileUploadId, string $prompt, array $options = []): array` | 图生图 |
| `editImage` | `($fileUploadId, $maskFileUploadId, string $prompt, array $options = []): array` | 蒙版编辑 |
| `styleTransfer` | `($fileUploadId, string $style, array $options = []): array` | 风格迁移 |

`$options`：`provider`、`model`、`size`、`quality`、`style`、`n`、`steps`、`cfg_scale`。结果按 `config('ai.image.storage_*')` 落盘。

### AiVideoService

```php
$video = app(\MultiTenantSaas\Services\AiVideoService::class);
```

| 方法 | 说明 |
|------|------|
| `textToVideo(string $prompt, array $options = []): array` | 文生视频（异步提交） |
| `imageToVideo($fileUploadId, string $prompt, array $options = []): array` | 图生视频 |
| `editVideo($fileUploadId, string $prompt, array $options = []): array` | 视频编辑 |
| `extractFrames($fileUploadId, array $options = []): array` | 抽帧 |
| `getTask($requestId): array` | 查询任务状态 |
| `pollTask($requestId): void` | 队列轮询（由定时任务驱动） |

完成后分发 `ai.video.task.updated` 事件。

### AiConfigService

```php
$config = app(\MultiTenantSaas\Services\AiConfigService::class);
```

| 方法 | 说明 |
|------|------|
| `getOrCreateConfig(): AiTenantConfig` | 获取或初始化租户配置 |
| `isCategoryEnabled(string $category): bool` | 能力开关（text/image/video） |
| `enableCategory / disableCategory / setCategoryEnabled` | 设置能力开关 |
| `setCustomApiKey(string $provider, string $key)` | 设置租户自定义 Key（加密） |
| `removeCustomApiKey(string $provider)` | 移除自定义 Key |
| `resolveApiKey(string $provider): ?string` | 解析最终使用的 Key |
| `setAllowedModels(?array $models) / addAllowedModel / removeAllowedModel` | 模型白名单 |
| `isModelAllowed(string $model): bool` | 模型是否允许 |
| `setMonthlyBudgetLimit(float $amount)` | 月度预算 |
| `setOverageAction(string $action)` | 超额策略（block/warn/allow） |
| `export(): array / import(array $data)` | 导入导出 |

### AiUsageService

```php
$usage = app(\MultiTenantSaas\Services\AiUsageService::class);
```

| 方法 | 说明 |
|------|------|
| `getOrCreateCurrentQuota(): AiUsageQuota` | 获取/创建当前周期配额 |
| `recordTextUsage($model, $inputTokens, $outputTokens, $metadata = [])` | 记录文本用量 |
| `recordImageUsage($model, $count, $size = null, $metadata = [])` | 记录图像用量 |
| `recordVideoUsage($model, $durationSeconds, $resolution = null, $metadata = [])` | 记录视频用量 |
| `checkQuota(string $category): void` | 配额检查（不足抛异常） |
| `checkBudget(?float $currentSpend = null): void` | 预算检查 |
| `checkOverage(): ?string` | 超额策略判定 |
| `getUsageSummary(): array` | 用量汇总 |
| `getUsageByCategory(): array` | 按能力域统计 |
| `getUsageByModel(): array` | 按模型统计 |

---

## 二、PHP SDK（AiResource）

```php
use MultiTenantSaas\SDK\Client;

$client = new Client('https://api.example.com', 'sk_xxx');
```

| SDK 方法 | HTTP | 路径 | 说明 |
|----------|------|------|------|
| `$client->ai()->textCompletion($data)` | `POST` | `/v1/ai/text` | 文本补全 |
| `$client->ai()->imageGeneration($data)` | `POST` | `/v1/ai/image` | 图像生成 |
| `$client->ai()->videoGeneration($data)` | `POST` | `/v1/ai/video` | 视频生成（异步提交） |
| `$client->ai()->usage($query = [])` | `GET` | `/v1/ai/usage` | 查询 AI 用量 |

> 鉴权：`Authorization: Bearer <apiKey>`。SDK 自动重试 5xx 与连接错误，4xx 不重试。

---

## 三、HTTP 端点

AI 端点通过 SDK 暴露，统一前缀 `/v1/ai`，需 Bearer Token 鉴权。

### POST /v1/ai/text

文本补全。

**请求体**：

```json
{
  "model": "gpt-4o-mini",
  "messages": [{"role": "user", "content": "用一句话介绍多租户"}],
  "options": {"temperature": 0.7, "max_tokens": 256}
}
```

**响应**：

```json
{
  "success": true,
  "data": {
    "model": "gpt-4o-mini",
    "content": "多租户是一套实例服务多个隔离租户的架构模式。",
    "usage": {"input_tokens": 12, "output_tokens": 20}
  }
}
```

### POST /v1/ai/image

图像生成。

**请求体**：

```json
{
  "prompt": "一只赛博朋克猫",
  "options": {"provider": "dalle", "model": "dall-e-3", "size": "1024x1024", "n": 1}
}
```

**响应**：

```json
{
  "success": true,
  "data": {
    "urls": ["https://.../ai_generated/xxx.png"],
    "file_upload_id": "1701445653535567"
  }
}
```

### POST /v1/ai/video

视频生成（异步）。

**请求体**：

```json
{
  "prompt": "日落延时摄影",
  "options": {"provider": "runway", "model": "gen-3", "duration": 5, "resolution": "1280x768"}
}
```

**响应**：

```json
{
  "success": true,
  "data": {
    "request_id": "1802000000000000",
    "status": "pending",
    "poll_interval_seconds": 10
  }
}
```

完成后通过 `ai.video.task.updated` 事件与 Webhook 通知，结果存入对象存储。

### GET /v1/ai/usage

查询当前租户 AI 用量。

**查询参数**：`period`（月份，如 `2026-06`）、`category`（text/image/video）、`model`。

**响应**：

```json
{
  "success": true,
  "data": {
    "period": "2026-06",
    "summary": {"spend": 12.34, "currency": "CNY"},
    "by_category": {"text": {...}, "image": {...}, "video": {...}},
    "by_model": {"gpt-4o-mini": {...}, "dall-e-3": {...}}
  }
}
```

---

## 四、错误码

| error_code | HTTP | 说明 |
|------------|------|------|
| `ai_category_disabled` | 403 | 租户未启用该 AI 能力 |
| `ai_model_not_allowed` | 403 | 模型不在白名单 |
| `ai_quota_exceeded` | 429 | 配额耗尽 |
| `ai_budget_exceeded` | 429 | 预算超限且策略为 block |
| `ai_provider_error` | 502 | 上游提供商错误 |
| `ai_timeout` | 504 | 上游超时 |

---

**文档版本**: v1.0.0
