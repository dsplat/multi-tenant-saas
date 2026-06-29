## Architecture

架构设计合理，模块边界清晰：

- `EventBusService`（订阅管理 + 发布 + 死信）与 `DispatchEventJob`（单订阅者分发）职责分离良好
- 复用 `WebhookService` 的事件类型定义（`SUPPORTED_EVENTS`）和 HMAC 签名机制，避免重复定义
- 与 Laravel 原生 `Event::dispatch()` 集成，兼容现有监听器
- `EventBusService` 已在 `TenancyServiceProvider::register()` 中注册为 singleton ✅
- 构造函数注入 `WebhookService`，依赖方向正确

**无阻塞问题。**

## Code Quality

命名规范、可读性好，遵循项目既有风格：

- PSR-12 规范，中文注释 + PHPDoc 完整
- 分区注释（`// ---- 订阅管理 ----`）与 WebhookService 风格一致
- `$fillable`、`$hidden`、`casts()` 均符合项目 Model 模式
- 测试覆盖全面：订阅 CRUD、事件路由、内部/外部分发、死信队列、翻译 key
- `generateSecret()` 已正确委托给 `$this->webhookService->generateSecret()`，无重复实现 ✅

**问题：**

1. `EventSubscription` 未定义 `STATUS_ACTIVE` / `STATUS_INACTIVE` 常量（与 `DeadLetter` 定义了三个状态常量形成不对称）。非阻塞，但建议保持一致性。
2. 部分翻译 key 已定义但未在代码中使用：`event_subscription_not_found`、`dead_letter_not_found`、`dead_letter_resolved` — Service 方法返回 `bool`/`null` 而非抛异常使用这些 key。属于死翻译 key。

## Type Safety

类型标注完整：

- 所有方法参数和返回值均有类型声明
- `Collection<int, EventSubscription>` 等 PHPDoc 泛型标注正确
- `nullable` 类型使用 `?` 前缀，规范

**问题：**

- `dispatchInternal()` 中 `app($handler)` 解析的类无接口约束，`$instance->handle(...)` 调用依赖运行时 `method_exists` 检查。建议定义 `EventHandler` 接口（如 `handle(string $eventType, array $payload): void`），在 subscribe 时校验 handler 实现了该接口。非阻塞，但可提高类型安全。

## Security

安全措施到位：

- 外部 Webhook 使用 HMAC-SHA256 签名，复用 `WebhookService::generateSignature()`
- `BelongsToTenant` trait 保证租户隔离
- `EventSubscription::$hidden` 隐藏 `secret` 字段
- 事件类型通过白名单校验（`isSupportedEvent`）

**问题：**

- `dispatchInternal()` 使用 `app($handler)` 实例化任意类 — handler 类名存储在数据库中，若被注入恶意类名可触发任意代码执行。当前有 `class_exists` + `method_exists` 运行时检查（会抛异常而非静默执行），风险可控但不够主动。建议在 subscribe 时校验 handler 类存在性和可调用性。

## Performance

无明显性能问题：

- `publish()` 中订阅查询为单条 SQL，无 N+1
- 每个订阅独立 Job，单个失败不影响其他订阅者
- 指数退避 `[5, 15, 30]` 合理

**问题：**

- `getDeadLetters()` 和 `listSubscriptions()` 无分页，大量数据时有内存风险。非阻塞，管理接口通常数据量可控，但建议后续迭代添加 `paginate()`。

## Potential Bugs

1. **`backoff()` 硬编码数组长度与 `$tries` 不匹配风险（`DispatchEventJob.php:53`）：** `backoff()` 返回 `[5, 15, 30]`（3 个值），而 `$tries` 从 config 读取（默认 3）。若 config 设置 `max_retries = 5`，第 4/5 次重试会使用最后一个值（30s）。Laravel 行为如此，非 bug，但建议 `backoff()` 根据 `$tries` 动态生成。

2. **`dead_letters.subscription_id` 无外键约束（迁移 `000017`）：** `subscription_id` 是 `unsignedBigInteger` 但未定义 `foreign()` 关联 `event_subscriptions`。订阅删除后死信记录的 `subscription_id` 成为悬空引用。考虑到软删除的存在，风险较低。

3. **`retryDeadLetter()` 状态转换语义微妙（`EventBusService.php:195`）：** 标记 `STATUS_RETRIED` 后 dispatch job — 若 job 再次失败，`failed()` 会创建新的 dead_letter（`STATUS_FAILED`），原记录保持 `retried`。语义上可接受但可能造成困惑：`retried` 并不代表重试成功。

## Verdict

**PASS**

整体设计合理，代码质量高，核心功能（订阅 CRUD、事件路由、异步分发、死信队列）实现完整。`EventBusService` 已正确注册为 singleton，`DispatchEventJob` 已正确读取 config，`failed()` 已包含 trace 信息，`generateSecret()` 已委托给 WebhookService — 前一次 review 的多个关注点在实际代码中已解决。测试覆盖全面，翻译 key 中英双语齐全。

【建议改进】（非阻塞，可在后续迭代中处理）

1. `getDeadLetters()` / `listSubscriptions()` 后续添加分页支持
2. `dispatchInternal()` 建议定义 `EventHandler` 接口，在 subscribe 时校验 handler 类，提高类型安全
3. `dead_letters.subscription_id` 建议添加外键约束（或在文档中说明有意不加的原因）
4. `backoff()` 建议根据 `$tries` 动态生成数组长度，避免 config 变更时行为不直观
5. 清理未使用的翻译 key（`event_subscription_not_found`、`dead_letter_not_found`、`dead_letter_resolved`），或在对应 Service 方法中实际使用它们
