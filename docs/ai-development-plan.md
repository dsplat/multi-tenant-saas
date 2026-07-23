# 框架层 AI 基建开发计划

> 本计划为「AI 能力一次性到位」的框架侧部分。项目侧见 scrm-platform/docs/ai-development-plan.md。
> 架构依据：scrm-platform/docs/ai-usage-architecture.md（设计哲学 / 可选性铁律 / 分层复用 / AiOptional 降级规范 / 页面助手协议）。

## 目标

补齐 3 项通用基建，为项目层复用提供前提：

1. **AiOptional** 可选性包装器（P0，项目复用前提）
2. **PageContext** 上下文协议（P1）
3. **IntentRouter** 意图路由器（P1）

## 关键约束

- 落点均在 `src/Modules/Ai/`，与 AiTextService 同层，由 AiServiceProvider 注册。
- 复用已有基建：AiConfigService（特性开关）、AiUsageService（配额/预算）、AgentMonitor（监控）、AgentRuntime（ReAct 运行时）。
- 项目层禁止自建上述任何能力，必须复用本层。
- 部署顺序：框架先于项目。

---

## F1 AiOptional 可选性包装器（P0）

### 文件

- `src/Modules/Ai/DTOs/AiResult.php`
- `src/Modules/Ai/Services/AiOptional.php`
- AiServiceProvider 注册

### 契约

```php
class AiOptional
{
    public function invoke(
        string $category,    // AI 能力类别（开关 + 配额维度）
        mixed $fallback,     // 降级返回值（确定性兜底）
        callable $aiCall,    // 真正的 AI 调用
        array $options = []  // timeout_ms / confidence_threshold / metadata
    ): AiResult;

    public function available(string $category): bool; // 开关 + 配额预检
}

class AiResult
{
    public readonly bool $success;
    public readonly mixed $output;     // 成功=AI结果；失败=fallback
    public readonly float $confidence;
    public readonly bool $degraded;
    public readonly ?string $reason;   // disabled / quota / timeout / error / low_confidence
    public readonly int $durationMs;
}
```

### 调用链（fail-open）

```
isCategoryEnabled? ─否→ AiResult::degraded(fallback, 'disabled')
       │是
checkQuota / checkBudget ─超限→ AiResult::degraded(fallback, 'quota')
       │通过
try { aiCall() } catch ─异常→ AiResult::degraded(fallback, 'error')
       │成功
超时软检测 ─超→ AiResult::degraded(fallback, 'timeout')
       │通过
置信度 < threshold ─→ AiResult::degraded(fallback, 'low_confidence')
       │通过
AgentMonitor 日志（best-effort）
       ↓
AiResult::success(output, confidence, durationMs)
```

### 铁律

- 绝不向调用方抛异常。
- 失败必返回带 reason 的降级 AiResult。
- 异步 / 队列场景同此。
- 配额维度映射：业务 category（如 `customer.auto_tag`）→ 配额维度（text / image / video），默认 text，可经 options['quota_category'] 覆盖。

### 依赖（已实现，直接注入）

- `AiConfigService::isCategoryEnabled(string $category): bool`
- `AiUsageService::checkQuota(string $category): void`（超限抛 RuntimeException）
- `AiUsageService::checkBudget(?float $currentSpend = null): void`
- `AgentMonitorContract::logConversationTurn(...)` / `logToolCall(...)`

### 测试

`tests/AiOptionalTest.php`：
- 开关关闭 → degraded, reason='disabled'
- 配额超限 → degraded, reason='quota'
- aiCall 抛异常 → degraded, reason='error'
- 超时 → degraded, reason='timeout'
- 低置信度 → degraded, reason='low_confidence'
- 正常 → success, output=AI结果, degraded=false

---

## F2 PageContext 上下文协议（P1）

### 文件

- `src/Modules/Ai/DTOs/PageContext.php`

### 结构

```php
class PageContext
{
    public readonly string $route;              // 前端路由（如 marketing.campaign.create）
    public readonly string $module;             // 模块名（Marketing）
    public readonly ?string $entityType;        // 实体类型（campaign）
    public readonly ?int $entityId;             // 实体 ID（编辑时有值）
    public readonly array $formState;           // 当前表单状态
    public readonly string $visibleDataSummary; // 页面可见数据摘要
    public readonly ?string $userIntent;        // 用户自然语言意图
}
```

### 职责

- 标准化前端页面上下文 → 意图路由 → agent 的入参。
- 由前端采集、经 AssistantController 传入。

---

## F3 IntentRouter 意图路由器（P1）

### 文件

- `src/Modules/Ai/Services/IntentRouter.php`
- `src/Modules/Ai/Http/Controllers/AssistantController.php`
- 路由注册（`src/Modules/Ai/Routes/api.php`）

### IntentRouter 契约

```php
class IntentRouter
{
    /**
     * 根据页面上下文路由到对应 agent slug。
     * 路由表：module → 默认 agent slug；user_intent 关键词 → agent slug。
     * 无法识别 → 通用助手 slug 或 null（拒绝并提示）。
     */
    public function route(PageContext $ctx): ?string;
}
```

### AssistantController

- `POST ai/assistant`：接收 PageContext + user_intent → IntentRouter 路由 → AgentRuntime::runStream 流式返回。
- 写操作约束：写工具只产出草稿，落库由业务 Service + 人确认完成（路由器/工具层强制）。

### 路由注册

在 `src/Modules/Ai/Routes/api.php` 追加：
```php
Route::post('ai/assistant', [AssistantController::class, 'handle'])
    ->middleware(['auth:sanctum', 'tenant.ensure']);
```

---

## 验证

- `php -l` 全部新增 PHP。
- `composer dump-autoload`。
- `php artisan test --filter AiOptionalTest` 全绿。
- 确认 AiServiceProvider 注册后 `app(AiOptional::class)` 可解析。

## 部署

框架先部署（本计划全部内容），项目再部署（见项目侧计划）。
