Now I have a complete picture. Here is my review:

---

## Architecture

**TASK-010a 的三个交付物（AiProviderContract、AiModelEnum、config/ai.php）设计合理：**

- `AiProviderContract` 作为接口契约，分离了对话补全、文本补全、Embedding、流式输出四种能力，粒度优于 task 原始 spec 中的单一 `completion()` 方法，更贴合实际 AI API 差异。
- `AiModelEnum` 用 PHP 8.1 backed enum 承载模型路由元数据（type/provider/maxTokens），与 `forProvider()`/`forType()` 筛选方法配合，是典型的枚举驱动路由模式。
- `config/ai.php` 结构清晰，遵循 `config/pay.php` 的项目惯例。

**严重问题：交付物未进入 git diff。** 三个文件目前是 `??`（untracked）状态，不包含在本次提交中。而 diff 中出现的 `TenantController`、`TenantResource`、`routes/api.php`、`TestCase.php`（AI 网关 schema）等文件全部超出 TASK-010a 的 **"只允许修改 3 个文件，禁止修改其他文件"** 约束。这些属于 TASK-010（完整 AI 网关）或 Onboarding 相关任务的代码，不应混入此子任务。

## Code Quality

**AiProviderContract：**
- PHPDoc 非常详尽，返回值使用了 PHPStan 级别的 shape 类型标注，覆盖 `usage`、`raw` 等字段。
- 命名一致：`chatCompletion`、`textCompletion`、`embeddings`、`streamChatCompletion` 符合 OpenAI API 惯例。

**AiModelEnum：**
- `isDeprecated()` 硬编码 `default => false`——当前所有模型都不废弃，但缺少 `deprecated` 属性或状态字段。未来模型废弃时需要改代码而非数据，可维护性差。
- `forProvider()` / `forType()` 返回 `self[]` 但实际返回 `array`（`array_filter` 保持索引），PHPDoc 声明 `self[]` 可接受但调用方可能预期 0-indexed 数组。

**config/ai.php：**
- `'streaming_enabled' => env('AI_STREAMING_ENABLED', true)` 未做 `(bool)` 强转，而 `timeout` 做了 `(int)` 强转——不一致。`env()` 在 `.env` 中未设置时返回 `true`（布尔），但 `.env` 中写了 `AI_STREAMING_ENABLED=false` 时返回字符串 `"false"`（truthy），这是一个经典 Laravel 配置陷阱。

**超出范围的 Onboarding 代码（TenantController）：**
- 异常处理层级清晰（`RuntimeException` → `InvalidArgumentException` → `Throwable`），审计日志覆盖完整。
- 但 4 个 onboarding 方法全部使用 `app(TenantOnboardingService::class)` 而非构造函数注入，与 Controller 其他方法的风格一致（项目惯例），可接受。

## Type Safety

**AiProviderContract：**
- 所有方法参数和返回值都有类型声明 ✓
- `embeddings` 的 `$input` 使用 `string|array<int, string>` union type，符合 PHP 8.1+ ✓

**AiModelEnum：**
- 所有方法都有返回值类型声明 ✓
- `forProvider` 和 `forType` 参数类型为 `string`，未约束为枚举值——调用方可传入任意字符串，返回空数组而非报错。这是设计选择（宽容），但可考虑用 `self::tryFrom()` 做提前校验。

**TenantController（超出范围）：**
- `onboardingStep(Request $request, int $step): JsonResponse` 参数类型完整 ✓
- `$stepFields` 未声明为 `array<int, array<string, string>>`，PHPDoc 缺失。

**TenantResource：**
- `$tenant->trial_ends_at !== null && $tenant->trial_ends_at->isFuture()` — 如果 `trial_ends_at` 列不存在（旧数据迁移问题），会抛 `AttributeError`。但 TestCase 已定义该列，风险低。

## Security

**AiProviderContract / AiModelEnum / config/ai.php — 无安全问题。**

**config/ai.php — API Key 暴露风险：**
- 所有 `api_key` 通过 `env()` 读取，这是 Laravel 标准做法。但如果 `config:cache` 被执行，key 会写入 `bootstrap/cache/config.php`，应确保该文件不被提交到 VCS。（这是 Laravel 通用风险，非此代码独有。）

**TenantController（超出范围但存在安全问题）：**
- **`register()` 端点无认证、仅 `throttle:10,1`**——每分钟 10 次注册请求，可用于邮箱枚举（`duplicate_email` 错误消息泄露已注册邮箱）。建议统一返回通用错误消息。
- **密码正则使用 3 个独立 `regex` 规则**——如果某个 regex 格式错误，Laravel 会静默跳过。建议改用 `Password::min(8)->letters()->numbers()->uncompromised()`（Laravel 内置）。
- **`onboardingStep` 的 `$request->validate($stepFields[$step])` 在 try-catch 之外**——验证失败会抛 `ValidationException`，返回 422 但无审计日志。这是小问题。
- **路由 `throttle:10,1` 适用于整个 onboarding 组**——`onboardingStatus` 查询进度也被限流 10 次/分钟，可能太严格。

## Performance

**AiModelEnum：**
- `forProvider()` 和 `forType()` 每次调用遍历 `cases()` 全量枚举。当前仅 11 个值，无性能问题。但如果模型数量增长到 50+，可考虑静态缓存。

**TestCase（超出范围）：**
- AI 网关表的索引设计合理：`ai_requests` 有 5 个索引覆盖常见查询路径（tenant+time、tenant+provider+time、tenant+model+time、user+time、status+time）。
- `ai_providers` 表 `api_key` 为 `TEXT` 类型——合理，API key 可能很长。

**无 N+1 问题。** 本次变更是接口/枚举/配置层，不涉及数据库查询。

## Potential Bugs

1. **`config/ai.php` 的 `streaming_enabled` 类型陷阱：** `.env` 中 `AI_STREAMING_ENABLED=false` 会变成字符串 `"false"`（truthy）。应改为 `(bool) env('AI_STREAMING_ENABLED', true)`。

2. **`AiModelEnum::isDeprecated()` 永远返回 `false`：** 不是 bug 但是 stub 实现，如果上层逻辑依赖此方法做路由排除，所有模型都会通过。应至少在 docblock 中标注 "TODO: implement deprecation tracking"。

3. **`config/ai.php` 缺少 4 个 AiModelEnum 中声明的提供商：** `stability`、`runway`、`kuaishou` 在枚举中出现但配置中无对应条目。调用 `AiModelEnum::Sdxl->provider()` → `'stability'` → `config('ai.providers.stability')` → `null`。**运行时 NPE。**

4. **`AiProviderContract` 缺少 `getModels()` 和 `getPricing()` 方法：** TASK-010 原始 spec 要求接口包含这两个方法。当前接口没有，下游 AiGatewayService 无法通过统一接口获取提供商支持的模型列表和定价。

5. **三个交付文件未 git add，不在 diff 中：** 意味着本次提交不会包含 TASK-010a 的实际工作成果。

---

## Verdict

**FAIL**

【必须修复】：

1. **范围溢出**：diff 包含 `TenantController`、`TenantResource`、`lang/*.php`、`routes/api.php`、`TestCase.php` 的修改，全部超出 TASK-010a "只允许修改 3 个文件"的约束。应拆分为独立提交/任务。

2. **交付物未提交**：`src/Contracts/AiProviderContract.php`、`src/Enums/AiModelEnum.php`、`config/ai.php` 三个文件为 untracked 状态，不在 git diff 中——TASK-010a 的核心交付物未进入版本控制。

3. **config/ai.php 缺少 4 个提供商配置**（`stability`、`runway`、`kuaishou`、`anthropic` 部分存在但 `AiModelEnum` 中 `DeepSeekV3` 对应的 `deepseek` 已有）：实际缺少 `stability`、`runway`、`kuaishou`。运行时 `config('ai.providers.{provider}')` 返回 `null`，导致 NPE。要么补齐配置，要么在枚举中标记这些模型为"未接入"。

4. **`config/ai.php` 的 `streaming_enabled` 未做 `(bool)` 强转**：`.env` 中 `AI_STREAMING_ENABLED=false` 将被解析为 truthy 字符串 `"false"`。
