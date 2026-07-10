# AI 模块架构

**最后更新**: 2026-06-29

---

## 概述

AI 模块为框架提供统一的多模型 AI 网关与租户级用量/预算控制，覆盖文本、图像、视频三类能力。模块完全配置驱动，派生项目可按需启用提供商与能力开关，无需修改业务代码。

---

## 架构分层

```
┌──────────────────────────────────────────────────────────┐
│                  业务层 / SDK (AiResource)                │
│   AiTextService  AiImageService  AiVideoService           │
└────────────────────────┬─────────────────────────────────┘
                         │
            ┌────────────▼────────────┐
            │    AiGatewayService     │   统一入口：chat/complete/embed/streamChat
            └────────────┬────────────┘
                         │  选择 provider
            ┌────────────▼────────────┐
            │   AiProviderContract    │   契约层
            └────────────┬────────────┘
                         │
   ┌──────────┬──────────┼──────────┬──────────┬──────────┐
   ▼          ▼          ▼          ▼          ▼          ▼
OpenAi    Zhipu        Dalle     StableDiff   Runway     Kling
Provider  Provider     Provider  Provider     Provider   Provider
   │          │          │          │          │          │
   └──────────┴──────────┴──────────┴──────────┴──────────┘
                         │
            ┌────────────▼────────────┐
            │  AiConfigService        │  租户配置：开关/Key/白名单/预算
            │  AiUsageService         │  用量配额：记录/检查/超额
            └─────────────────────────┘
```

---

## 提供商契约（AiProviderContract）

文本类提供商实现统一接口：

| 方法 | 说明 |
|------|------|
| `chatCompletion($model, $messages, $options)` | 对话补全 |
| `textCompletion($model, $prompt, $options)` | 文本补全 |
| `embeddings($model, $input, $options)` | 向量嵌入 |
| `streamChatCompletion($model, $messages, $options)` | 流式对话（返回 `\Generator`） |

图像/视频提供商按能力域定义各自接口（`textToImage` / `imageToImage` / `editImage` / `styleTransfer` / `submitTextToVideo` / `getTaskStatus`）。

---

## 服务层职责

### AiGatewayService

统一网关入口，向下路由到具体提供商：

```php
$gateway = app(AiGatewayService::class);

// 对话
$result = $gateway->chat('gpt-4o-mini', [
    ['role' => 'user', 'content' => '你好'],
], ['temperature' => 0.7]);

// 流式
foreach ($gateway->streamChat('gpt-4o-mini', $messages) as $chunk) { /* ... */ }

// 嵌入
$vec = $gateway->embed('text-embedding-3-small', '要嵌入的文本');
```

### AiTextService

文本能力的高层封装 + Prompt 模板管理：

- `chat / complete / embed / streamChat` — 显式指定模型
- `chatDefault / completeDefault / embedDefault / streamChatDefault / chatJsonDefault` — 使用 `config('ai.text.*')` 默认模型
- `chatJson` — 强制 JSON 模式输出
- Prompt 模板：`createPrompt / updatePrompt / deletePrompt / getPrompt / findByName / listPrompts / render / chatWithPrompt`

### AiImageService

- `textToImage` — 文生图
- `imageToImage` — 图生图（基于 `FileUpload`）
- `editImage` — 带蒙版编辑
- `styleTransfer` — 风格迁移

结果按 `config('ai.image.storage_*')` 自动落盘到指定 disk/category。

### AiVideoService

异步视频生成：

- `textToVideo / imageToVideo / editVideo / extractFrames`
- `getTask` — 查询任务状态
- `pollTask` — 队列延迟轮询（由 `poll_interval_seconds` / `max_poll_attempts` 控制）
- 完成后分发 `ai.video.task.updated` 事件

### AiConfigService

租户级配置（持久化到 `ai_tenant_configs`）：

- 能力开关：`isCategoryEnabled / enableCategory / disableCategory`
- 自定义 API Key（加密）：`setCustomApiKey / removeCustomApiKey / resolveApiKey`
- 模型白名单：`setAllowedModels / addAllowedModel / removeAllowedModel / isModelAllowed`
- 预算与超额：`setMonthlyBudgetLimit / setOverageAction`
- 导入导出：`export / import`

### AiUsageService

用量配额（持久化到 `ai_usage_quotas`，按 `monthly` 周期）：

- `recordTextUsage / recordImageUsage / recordVideoUsage` — 记录用量
- `checkQuota($category)` — 配额检查（不足抛异常）
- `checkBudget` — 预算检查
- `checkOverage` — 超额策略判定（`block`/`warn`/`allow`）
- `getUsageSummary / getUsageByCategory / getUsageByModel` — 用量报表

---

## 数据模型

| 表 | 说明 |
|----|------|
| `ai_tenant_configs` | 租户 AI 配置（能力开关、自定义 Key、模型白名单、预算、超额策略） |
| `ai_usage_quotas` | 租户月度用量配额（按周期聚合 tokens/张数/秒数与花费） |
| `ai_requests` | AI 请求记录（模型、输入输出、token、耗时、状态） |
| `ai_prompts` | Prompt 模板（名称、分类、内容、变量） |
| `ai_model_aliases` | 模型别名映射（租户自定义别名 → 真实模型标识） |
| `ai_providers` | 提供商配置（租户级覆盖 base_url/timeout） |
| `event_subscriptions` | 事件订阅（用于 Webhook/广播，AI 视频完成回调等） |

所有表均使用 `HasGlobalId`（16 位主键），租户级表使用 `BelongsToTenant` 实现隔离。

---

## 配置

全部配置集中于 `config/ai.php`，关键节：

| 节 | 作用 |
|----|------|
| `default_provider` / `default_model` | 全局默认提供商与模型 |
| `streaming_enabled` | 流式输出总开关 |
| `timeout` / `retry` | 超时与重试策略 |
| `rate_limit` | 速率限制（RPM） |
| `providers.*` | 各提供商 base_url / api_key / timeout |
| `text.*` | 文本默认模型与参数（temperature/max_tokens/max_input_length） |
| `image.*` | 图像默认 provider/model/size/quality/style/storage_* |
| `video.*` | 视频默认 provider/model/resolution/duration/fps/轮询参数/回调事件 |
| `tenant.*` | 租户默认能力开关、月度预算、超额策略 |
| `quota.*` | 计费周期（monthly）、告警阈值（0.8） |

---

## 安全与隔离

- **租户隔离**: AI 配置、用量、请求记录均通过 `BelongsToTenant` 隔离，查询自动按 `tenant_id` 过滤。
- **API Key 加密**: 租户自定义 Key 使用 `Crypt` 加密存储，`resolveApiKey` 解密后注入提供商。
- **模型白名单**: 未列入白名单的模型被 `isModelAllowed` 拒绝，防止租户调用未授权模型。
- **预算控制**: 超额策略 `block` 直接拒绝请求，`warn` 告警放行，`allow` 放行并计费。
- **用量审计**: `ai_requests` 记录每次调用的模型、token、耗时，可追溯。

---

**文档版本**: v1.0.0
