## Architecture

模块边界清晰，职责划分合理。`InAppNotificationService` 负责站内通知生命周期管理，`BroadcastingService` 负责实时推送，两者无交叉依赖。两个模型均正确使用 `BelongsToTenant` + `HasGlobalId` + `SoftDeletes`，与项目现有模式一致。`BelongsToTenant` 会自动注册 `TenantScope` 全局作用域，所有查询自动按当前租户隔离，`creating` 时自动填充 `tenant_id`。

**问题：**
1. **缺少 Service 注册（违反任务规范）：** 任务明确要求 "Service 类通过 `TenancyServiceProvider` 注册为 singleton"，但 `InAppNotificationService` 和 `BroadcastingService` 均未在 `TenancyServiceProvider::register()` 中注册。测试中通过 `$this->app->singleton()` 手动注册绕过了此问题，但生产环境中 `app(InAppNotificationService::class)` 每次会创建新实例（非 singleton），可能导致不必要的对象创建和潜在的状态不一致。
2. **路由全部使用闭包而非 Controller：** 项目规范要求 "所有 Controller 必须使用 API Resource 返回数据"，但新增的 ~15 个路由全部使用匿名闭包，无法复用、无法应用 Controller 中间件、不利于 API 文档生成。虽然现有 notification 路由也有闭包写法，但新代码不应延续此模式。

## Code Quality

命名规范，PHPDoc 注释完整（含 `@param array{...}` 形状注解），测试组织清晰且覆盖全面。路由中 `auth()->id()` 与 `$request->user()->id` 混用（如 line 186 vs line 226），风格不统一但功能等价。`GeneralNotification` 扩展干净，仅追加 `broadcast` 渠道和 `toPush()` 预留接口。翻译 key 中英文双语均已同步。

## Type Safety

所有方法参数和返回值类型声明完整。模型 `casts()` 正确定义（`is_read` → boolean，`payload` → array，`read_at` → datetime 等）。PHPDoc 数组形状注解准确。`BroadcastEvent::dispatch()` 的 `$tenantId` 参数为 `?int`，与 `TenantContext::getId()` 返回 `?string` 的转换正确。无明显类型安全问题。

## Security

1. **无跨租户数据泄露：** 之前的 review 文件声称 `broadcast/history` 存在跨租户泄露，但这是**错误的**。`BroadcastEvent` 使用了 `BelongsToTenant` trait，`TenantScope` 全局作用域自动应用于 `BroadcastEvent::query()`，所有查询已按当前租户隔离。路由也未接受 `tenant_id` 查询参数。
2. **RBAC 中间件已正确配置：** `broadcast/history` 和 `broadcast/status` 均已附加 `rbac.permission:tenant.view`，`broadcast/retry` 有 `rbac.permission:tenant.update`。站内通知路由基于用户归属校验（`user_id` 过滤 + `TenantScope`），属于用户私有数据，不使用 RBAC 中间件是合理的设计。
3. **用户归属校验正确：** `markAsRead`、`delete`、`markBatchRead` 等操作均通过 `$userId` 过滤确保只能操作自己的通知，配合 `TenantScope` 实现双重隔离。
4. **无 SQL 注入风险：** 全部使用 Eloquent 参数化查询。敏感字段未暴露。

## Performance

无 N+1 问题。`in-app-notifications` 列表接口中 `getUnreadCount` + `getUnreadCountByType` 各发一次查询，加上分页查询共 3 次 DB 调用——可接受但可优化为单次查询。迁移文件中的复合索引设计合理（`idx_tenant_user_read`、`idx_tenant_user_type` 等）。`retryPending` 限制 100 条——合理。

## Potential Bugs

1. **Service 未注册为 singleton（高优先级）：** `InAppNotificationService` 和 `BroadcastingService` 未在 `TenancyServiceProvider` 中注册。路由中通过 `app(InAppNotificationService::class)` 调用时，Laravel 会自动解析但不保证 singleton 行为（除非在某处注册）。如果服务无状态则影响不大，但违反了任务规范且可能导致非预期行为。

2. **`auth()->id()` 与 `$request->user()->id` 混用：** 部分路由使用 `auth()->id()`（如 markAsRead、delete），部分使用 `$request->user()->id`（如 list、unread-count）。虽然功能等价，但风格不一致。建议统一使用 `$request->user()->id`，因为在闭包路由中 `$request` 已可用且更明确。

3. **降级测试语义歧义：** `test_broadcast_degrades_gracefully` 断言 `$event->is_sent === false`，这在 null driver 下是正确的（`isAvailable()` 返回 false → `sendToBroadcaster` 返回 false）。但测试名 "degrades gracefully" 与断言 `is_sent=false` 的组合可能引起误解——建议在测试中额外断言 `assertNull($event->error_message)` 或添加注释说明降级语义。

4. **`lang/en/notification.php` 未出现在 diff 中但实际已更新：** 文件已包含所有新增 key（已确认），但 diff 未显示该文件变更。可能是之前已提交或 diff 工具遗漏，不影响功能但需确认版本一致性。

## Verdict

**PASS**

【建议改进】（非阻塞）

1. **将 `InAppNotificationService` 和 `BroadcastingService` 注册到 `TenancyServiceProvider::register()`** — 遵循任务规范和项目既有模式，确保 singleton 生命周期。
2. **统一路由中获取用户 ID 的方式** — 全部使用 `$request->user()->id` 替代 `auth()->id()`，保持风格一致。
3. **考虑将闭包路由重构为 Controller** — 便于复用、中间件管理和 API 文档生成。
4. **`getHistory()` 的 `limit` 参数上限 500 可能偏大** — 对于用户级查询，建议默认 50、上限 200，减少大结果集的内存压力。
