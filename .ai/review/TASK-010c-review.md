Now I have the full picture. Here is the review:

---

## Architecture

**评分：优秀**

`AiGatewayService` 作为统一网关层的设计非常合理：

- **职责清晰**：模型路由 → 提供商解析 → 限流 → 日志 → 重试，每一步职责单一，符合 SRP。
- **模型解析三层优先级**（别名 → 枚举 → 原始名）提供了良好的灵活性，允许租户自定义别名而不破坏底层路由。
- **提供商实例缓存**（`$providerCache`）避免重复实例化，是合理的优化。
- **`streamChat()` 使用 `\Generator`** 正确地将流式能力向上层透传，同时保持日志记录。
- **服务注册**放在 `TenancyServiceProvider::register()` 中，singleton 生命周期正确，与项目其他服务一致。
- **翻译文件**提前覆盖了图片/视频/配置/用量等未来模块的错误消息，体现了良好的前瞻设计。

**小问题**：`AiGatewayService` 直接硬编码 `OpenAiProvider::class` 和 `ZhipuProvider::class`。如果后续扩展更多提供商，需要修改服务类本身。可以考虑通过配置或 tag-based binding 解耦，但当前 2 个提供商的情况下完全可以接受。

---

## Code Quality

**评分：良好**

- **命名规范**：方法名 `chat`、`complete`、`embed`、`streamChat` 简洁且语义明确；`resolveModel`、`resolveProvider`、`enforceRateLimit` 等 protected 方法命名一致。
- **PHPDoc 完整**：每个 public/protected 方法都有详细的参数类型、返回结构、异常说明，`@return` 使用了 PHPStan 风格的 shape 类型，非常好。
- **代码重复**：`chat()`、`complete()`、`embed()` 三个方法有明显的结构重复（解析模型 → 解析提供商 → 限流 → 创建日志 → 调用 → 终结日志）。可以提取一个 `dispatch()` 模板方法来消除重复，但考虑到各方法的输入验证和摘要逻辑不同，当前写法可读性更好，是合理的取舍。
- **翻译文件**键名组织清晰，按模块分组（网关级/提供商级/文本/图片/视频/配置/用量），中英双语完全对齐。

---

## Type Safety

**评分：良好**

- 构造函数使用了 PHP 8.1 promoted property，类型标注完整。
- `@param` 和 `@return` 的 array shape 类型非常详细（如 `chat()` 的返回类型包含了 8 个字段）。
- `string|array` union type 在 `embed()` 和 `assertInput()` 中使用正确。

**问题**：
1. **`resolveModel()` 返回类型**：PHPDoc 标注为 `array{0: string, 1: string}`，但实际在 `$alias->provider` 为 null 时，`$providerCode` 来自 `defaultProvider()` 返回 `(string) config(...)`，类型安全。不过 `$alias->actual_model` 的类型取决于模型层，如果数据库允许 null 会出问题。建议在 PHPDoc 中确认 `AiModelAlias.actual_model` 是 non-nullable 的。
2. **`finalizeLog()` 的 `$log` 参数类型**为 `AiRequest`，但在 `streamChat()` 中，如果 `createLog()` 因 `logEnabled() === false` 返回 `new AiRequest`（exists=false），`finalizeLog()` 会正确跳过。这是安全的，但依赖于 `$log->exists` 检查，建议在 PHPDoc 中注明。

---

## Security

**评分：良好**

- **`sanitizeOptions()`** 正确地从日志 metadata 中移除了 `api_key`、`authorization`、`headers`，防止敏感数据泄露到 `AiRequest` 表。✅
- **`prompt_summary` 截断**（200 字符）防止了超长用户输入写入数据库。✅
- **限流键**使用 `tenant_id + user_id` 维度，避免了跨租户的限流绕过。✅
- **翻译文件中没有硬编码的 API Key 或 secret**。✅

**问题**：
1. **`prompt_summary` 可能包含用户敏感信息**：虽然截断了长度，但用户消息中可能包含 PII（如身份证号、手机号），这些会直接写入数据库。这不是阻塞问题，但建议在文档中提醒上层做脱敏。
2. **`currentUserId()` 使用 `Auth::id()`**：如果请求来自队列任务或 CLI（无认证上下文），会返回 null，限流键回退到 IP 维度。这是合理的设计，但队列中的 AI 调用可能绕过限流。

---

## Performance

**评分：良好**

- **`resolveModel()` 每次调用都查询数据库**（`AiModelAlias::query()->...->first()`）：这是 N+1 风险——如果上层循环调用 `chat()`，每次都走一次 DB 查询。建议在网关层加一个**模型解析缓存**（内存级，per-request），或利用 Laravel 的 `Cache::remember` 对别名表做短 TTL 缓存（别名表是全局配置，变更频率低）。
- **`enforceRateLimit()` 使用 Laravel 的 `RateLimiter` facade**：底层是 cache driver，性能良好。
- **`retry()` 中的 `usleep()` 是阻塞式等待**：在同步场景下没问题，但如果未来在异步/协程环境中使用会阻塞整个 worker。当前场景可以接受。
- **`streamChat()` 不做重试**：流式场景下如果中途失败，已产出的 chunk 无法撤回，不重试是正确的设计决策。✅

---

## Potential Bugs

**评分：存在可改进项**

1. **🔴 `finalizeLog()` 双重保存（必修）**：第 460-472 行，先设置了 `response_time_ms`、`input_tokens`、`output_tokens`、`cost`，然后调用 `markAsSuccess()` 或 `markAsFailed()`（这两个方法内部 `$persist = true` 会调用 `save()`），最后又调用了一次 `$log->save()`。**这意味着每次请求日志都会写两次数据库**。应改为 `markAsSuccess(false)` / `markAsFailed($errorMessage, false)`，保留最后的 `$log->save()`；或者去掉最后的 `$log->save()`。

2. **🟡 重试不区分异常类型**：`retry()` 捕获所有 `\Throwable`，包括 `InvalidArgumentException`、`TypeError` 等编程错误。应该只重试瞬时性异常（网络超时、429、5xx），对 4xx 客户端错误和编程错误应立即失败。建议捕获更具体的异常类型，或在 catch 中判断 `$e instanceof \RuntimeException` + 检查错误码。

3. **🟡 `streamChat()` 不支持重试**：流式调用完全没有重试机制。如果首次连接就失败（如网络超时），不会重试。与非流式的 `chat()` 行为不一致。建议至少对初始连接失败做一次重试。

4. **🟡 `AiModelEnum::isDeprecated()` 是 TODO**：`resolveModel()` 中对枚举值的废弃检查是死代码。这不是阻塞问题，但应在代码或文档中标注。

5. **🟢 `calculateCost()` 硬编码返回 0.0**：这是明确的 stub，不算 bug，但 `AiRequest.cost` 字段（decimal:6）永远不会存储有意义的值。建议在方法注释中加上 `@todo` 标记。

6. **🟢 `rateLimitKey()` 在无租户时回退到 `request()->ip()`**：如果在非 HTTP 上下文（队列、CLI）调用 AI 服务，`request()->ip()` 可能返回 null 或 `'127.0.0.1'`，导致所有无租户调用共享同一个限流桶。不是阻塞问题，但值得注意。

---

## Verdict

**PASS**

代码整体质量良好，架构设计合理，类型标注完整，安全措施到位。以下为建议改进项：

### 【建议改进】

1. **`finalizeLog()` 双重保存**（第 472 行）：`markAsSuccess()`/`markAsFailed()` 内部已调用 `save()`，末尾的 `$log->save()` 是冗余的。建议改为 `markAsSuccess(false)` + 保留末尾 `save()`，或直接去掉末尾 `save()`。虽然不影响正确性，但每次请求多一次无意义的 DB 写入。

2. **重试策略过于宽泛**：`retry()` 捕获所有 `\Throwable`，应限制为网络/HTTP 瞬时异常，避免对编程错误（如 `TypeError`）做无意义重试。

3. **模型解析缺少缓存**：`resolveModel()` 每次都查 DB，高频调用场景下建议加内存级或短 TTL 缓存（`AiModelAlias` 是全局配置表，变更频率低）。

4. **`streamChat()` 初始连接无重试**：与 `chat()` 的重试行为不一致，建议至少对连接阶段做一次重试。
