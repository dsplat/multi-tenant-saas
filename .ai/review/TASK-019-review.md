Now I have full context of the codebase patterns. Here's my review:

---

## Architecture

整体架构合理，模块边界清晰：`WebhookService` 负责业务逻辑，`ProcessWebhookDelivery` 负责异步投递，两个 Model 各司其职。`BelongsToTenant` + `HasGlobalId` 的使用符合项目惯例。

**问题：**
1. **WebhookService 未注册为 singleton**：任务规范要求"Service 类通过 TenancyServiceProvider 注册为 singleton"，但 diff 中未包含 `TenancyServiceProvider` 的修改。通过 `app(WebhookService::class)` 调用虽然能工作（Laravel 默认 transient），但违反了项目规范。
2. **路由使用闭包而非 Controller**：规范要求"所有 Controller 必须使用 API Resource 返回数据，禁止直接返回模型或数组"。当前所有 webhook 路由用闭包实现，直接返回 `response()->json([...])`，绕过了 API Resource 约定。虽然任务范围未列出 Controller 文件，但这属于架构妥协，应至少标注 TODO。
3. **Webhook 和 WebhookDelivery 都重新定义了 `tenant()` 关系**：`BelongsToTenant` trait 已定义完全相同的 `tenant()` 方法，模型中的重复定义是冗余代码。

## Code Quality

命名规范、中文注释、PSR-12 格式整体良好。常量定义清晰，方法拆分合理。

**问题：**
1. **`webhook_inactive`、`webhook_event_invalid`、`webhook_signature_invalid` 翻译 key 从未被使用**：定义了但代码中无引用，属于死代码。
2. **`ProcessWebhookDelivery::handle()` 多次 `update()` 调用**：同一个 delivery 对象在成功路径上调用了两次 `update()`（先写响应数据，再写状态），可合并为一次以减少 DB 写入。
3. **`dispatchEvent()` 不校验事件类型**：`isSupportedEvent()` 方法已实现但从未在分发流程中调用，任何字符串都会被当作合法事件分发。

## Type Safety

类型标注总体完整，方法参数和返回值都有类型声明。`casts()` 方法使用现代 Laravel 风格。

**问题：**
1. **`listWebhooks()` 和 `getDeliveries()` 返回类型未标注**：返回值是 `Collection` 但方法签名无返回类型，PHPDoc 写了 `@return` 但缺少实际的 `: Collection` 或 `: \Illuminate\Database\Eloquent\Collection` 类型声明。
2. **`dispatchEvent()` 的 `$payload` 参数类型为 `array`，但无子类型标注**：`array<string, mixed>` 在 PHPDoc 中有，但 PHP 签名只有 `array`。这是 PHP 的限制，可接受。
3. **路由闭包中 `$id` 参数类型为 `int`**：`HasGlobalId` 生成的是 16 位数字，PHP 64 位下 `int` 可容纳，但若在 32 位环境会溢出。项目目标环境应为 64 位，风险低。

## Security

**关键发现：**

1. **Job 中 TenantScope 绕过问题（中风险）**：`ProcessWebhookDelivery::handle()` 使用 `WebhookDelivery::where('webhook_delivery_id', ...)` 查询。由于 `BelongsToTenant` 会添加 `TenantScope` 全局作用域，当队列 worker 执行 Job 时若无租户上下文，查询可能失败或返回空。当前实现依赖 `TenantScope` 在无上下文时不添加过滤条件——这意味着 Job 实际上**绕过了租户隔离**。虽然 Job 只按主键查询单条记录，风险有限，但这是一个安全隐患。应使用 `WebhookDelivery::withoutGlobalScope(TenantScope::class)` 明确声明意图。
2. **`events` 数组未校验元素内容**：路由验证只检查 `events` 是数组，不校验每个元素是否为支持的事件类型。攻击者可注册任意事件名。`webhook_event_invalid` 翻译已准备好但未使用。
3. **`response_body` 无截断存储**：目标 URL 返回的响应体完整存入 `text` 字段。恶意 webhook 端点可返回超大响应，消耗数据库存储。
4. **`secret` 在 `regenerateSecret` 后旧签名仍可验证**：如果 webhook 端点缓存了旧 secret 生成的签名，重新生成 secret 后无法区分新旧。这是设计层面的考量，非 bug。
5. **无 webhook URL 白名单/黑名单**：可注册内网地址（SSRF 风险）。`http://169.254.169.254/` 等元数据服务地址可被利用。任务范围未要求，但值得记录。
6. **`$request->ip()` 在审计日志中**：通过代理时可能获取到代理 IP，需确认 `TrustedProxies` 中间件配置。

## Performance

1. **`dispatchEvent()` N+1 风险（低）**：先查所有匹配 webhook，再逐个创建 delivery 并 dispatch job。当 webhook 数量大时，循环内多次 `create()` + `dispatch()` 可改为 `insert()` + `dispatch()` 批量处理。但实际场景中单租户 webhook 数量有限，影响不大。
2. **`getDeliveries()` 无分页**：交付记录会随时间增长，`get()` 全量返回可能导致内存问题。应支持分页或限制返回数量。
3. **`getDeliveriesByEvent()` 同样无分页且无租户过滤**：虽然 `BelongsToTenant` 会自动添加作用域，但该方法查询全表 `event_type` 索引，数据量大时性能差。

## Potential Bugs

1. **`ProcessWebhookDelivery::handle()` 中 `attempts` 计数可能不准**：当 Job 因非 2xx 状态码抛出 `RuntimeException` 后被队列重试时，`handle()` 重新从 DB 读取 delivery（此时 `attempts` 已被上次执行更新为 1），再次 `+1` 变为 2。这个逻辑是正确的。但如果 `ConnectionException` 被抛出后，`update()` 中的 `attempts` 更新和异常抛出之间，队列可能并发重试——不过 Laravel 队列默认单线程，此风险极低。
2. **`failed()` 回调中 `error_message` 可能截断**：`\Throwable::getMessage()` 可能返回很长的字符串，而 `error_message` 字段是 `text` 类型，MySQL 下无问题，但 SQLite 下可能有长度限制。
3. **`resend()` 重置 `attempts` 为 0 但不重置 `created_at`/`updated_at`**：交付记录的时间戳不会更新，可能导致按时间排序时重发记录位置不直观。
4. **`WebhookDelivery` 的 `status` 字段无枚举约束**：数据库层接受任意字符串，只有代码层的常量约束。如果直接操作数据库可能插入非法状态值。
5. **`dispatchEvent()` 中 `webhook_id` 写入 payload**：payload 中包含 `webhook_id`，但同一个事件会分发给多个 webhook，每个 webhook 的 payload 都包含自己的 `webhook_id`。这是合理设计，但接收方可能混淆——需文档说明。

## Verdict

**PASS**（有条件）

整体实现质量良好，核心功能完整，测试覆盖充分。无阻塞性安全漏洞。

**【建议改进】（非阻塞）：**

1. **P1** — `ProcessWebhookDelivery::handle()` 查询应使用 `withoutGlobalScope(TenantScope::class)` 明确绕过租户作用域，避免隐式行为导致的潜在安全风险。
2. **P1** — `WebhookService` 应在 `TenancyServiceProvider` 中注册为 singleton，符合项目规范。
3. **P2** — 路由应迁移至 Controller + API Resource，或至少添加 TODO 注释标记技术债。
4. **P2** — `events` 数组元素应校验是否为 `SUPPORTED_EVENTS` 中的值，使用已准备好的 `webhook_event_invalid` 翻译。
5. **P2** — `getDeliveries()` 和 `getDeliveriesByEvent()` 应支持分页。
6. **P3** — 移除 Model 中重复的 `tenant()` 关系定义（trait 已提供）。
7. **P3** — 移除未使用的翻译 key，或在代码中引用它们。
8. **P3** — `ProcessWebhookDelivery::handle()` 中两次 `update()` 可合并为一次。
9. **P3** — 考虑对 `response_body` 做长度截断（如 64KB），防止恶意端点返回超大响应。
