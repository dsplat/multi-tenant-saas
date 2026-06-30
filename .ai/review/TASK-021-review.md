Now I have all the evidence needed. Here is my review.

---

## Architecture

SDK 层（`src/SDK/`）独立于 Laravel，仅依赖 PHP 8.2+ 和 ext-curl，符合任务要求。`Client` → `Resource` 分层清晰，`http_handler` 可注入实现测试隔离。`CleanupSandboxJob` 独立 Job 类替代了闭包调度（前一轮 review 的问题已修复），`ShouldQueue` 接口保证队列序列化安全。Service 层在 `TenancyServiceProvider` 注册为 singleton。沙箱路由（GET/DELETE/activate）已加入 `developer_id` 所有权校验（前一轮 review 的问题已修复）。

**致命问题：`SandboxEnvironment` 模型使用了 `BelongsToTenant` trait，但 `sandbox_environments` 表没有 `tenant_id` 列。** `TenantScope::apply()` 会追加 `WHERE sandbox_environments.tenant_id = ?`，该列不存在将导致所有查询抛出 SQL 错误。`BelongsToTenant` 的 `creating` 回调也会向不存在的 `tenant_id` 列写入数据。沙箱数据不是按"开发者所属租户"隔离的——它是按 `developer_id`（用户级）隔离的——使用 `BelongsToTenant` 是语义错误。应移除该 trait。

## Code Quality

PSR-12 规范一致，中文 PHPDoc 完整，命名规范（camelCase 方法、UPPER_SNAKE_CASE 常量）。`FakeHttpHandler` 测试辅助类设计合理，`SdkTest` 覆盖充分（链式调用、鉴权、重试、异常分类）。`DOCUMENTATION` 常量硬编码 9 篇文档条目但无实际内容——作为 MVP 可接受。`getUsageStats()` 已通过路由 `/developer/api-usage` 暴露，不再是死代码（前一轮 review 已修复）。但生产环境中没有中间件或机制在 API 请求时调用 `StructuredLogService::log()` 记录 API 调用，因此统计结果始终为空——功能可用但数据管线未接通。

沙箱所有权校验代码（`findSandbox` + `developer_id` 检查）在三个路由中重复出现，应抽取为中间件或 Service 方法以消除重复。

## Type Safety

方法参数和返回值类型标注完整，使用了 PHP 8.2+ 特性（`readonly`、联合类型）。SDK Resource 统一返回 `array<string, mixed>`，与"无框架依赖"定位一致。`SandboxEnvironment::$casts` 使用了 `protected function casts(): array` 返回值语法（Laravel 12 风格），正确。

`SandboxEnvironment::developer()` 关系引用 `User::class`，由于同属 `MultiTenantSaas\Models` 命名空间可自动解析，无需显式 import。

## Security

- **API Key 明文仅返回一次**：`createApiKey` 返回 `plainTextToken`，`SandboxEnvironment::$hidden` 隐藏 `api_key`，路由层正确处理。✅
- **API Key 吊销双重校验**：`revokeApiKey` 通过 `tokenable_type` + `tokenable_id` 防止跨用户吊销。✅
- **沙箱路由所有权校验已修复**：三个沙箱路由均在 `findSandbox` 后追加 `$sandbox->developer_id !== (int) auth()->id()` 校验并返回 403。`(int)` 强转防 type juggling。✅
- **无 SQL 注入 / XSS 风险**：查询均使用 Eloquent/Query Builder 参数绑定。✅
- **`/developer/*` 路由无 RBAC 中间件**：仅依赖 `auth:sanctum`，无 `rbac.permission:developer.manage` 权限节点控制。对比 webhook 路由均有 `rbac.permission:webhook.manage`。任何认证用户均可访问开发者门户全部功能。对于"开发者即用户"的场景可能是设计如此，但应与既有模式保持一致。

## Performance

- `cleanupExpired()` 使用 `->get()` 一次性加载所有过期沙箱到内存。当大量沙箱同时过期时存在 OOM 风险。建议改用 `chunk()` 或 `cursor()`。
- `getUsageStats()` 对同一基础查询执行三次（count + groupBy + recent），每次都是独立 SQL。可优化为单次查询后在 PHP 层聚合，或至少对 count 使用缓存。
- SDK `usleep()` 阻塞式重试占用 PHP-FPM worker，但作为客户端 SDK 属于正常取舍。

## Potential Bugs

1. **`SandboxEnvironment` 使用 `BelongsToTenant` 但表无 `tenant_id` 列（致命）**：`TenantScope` 追加 `WHERE sandbox_environments.tenant_id = ?`，列不存在导致 SQL 错误。`creating` 回调设置不存在的属性。所有 `SandboxEnvironment` 的查询和创建操作均会失败。必须移除 `BelongsToTenant` trait，改用 `developer_id` 作为隔离维度（或在查询前调用 `withoutGlobalScope(TenantScope::class)`，但这违背 trait 设计初衷）。

2. **`cleanup()` 只软删除 Tenant，沙箱数据物理残留**：`Tenant` 使用 `SoftDeletes`，`$tenant->delete()` 仅标记 `deleted_at`。沙箱租户关联的数据（如果有的话）不会被物理清理。24 小时 TTL 的"自动清理"语义不完整。对于纯空沙箱租户可接受，但应在文档/注释中明确说明。

3. **`/developer/*` 路由缺少 RBAC 中间件**：与项目中其他敏感路由（webhook、credits、api-tokens）使用 `rbac.permission:xxx` 中间件的模式不一致。任意认证用户可无差别访问开发者门户。

4. **沙箱路由所有权校验代码重复**：`GET/DELETE /sandboxes/{id}` 和 `POST /sandboxes/{id}/activate` 三个路由各自重复了"查找沙箱 → 校验所有权 → 返回 403"的逻辑。应抽取为 `SandboxService::findSandboxForDeveloper(int $sandboxId, int $developerId)` 方法或中间件。

---

## Verdict

**FAIL**

【必须修复】的问题：

1. **`SandboxEnvironment` 使用 `BelongsToTenant` 但表无 `tenant_id` 列**：`BelongsToTenant` trait 的 `TenantScope` 全局作用域会追加 `WHERE sandbox_environments.tenant_id = ?`，该列不存在将导致所有查询和插入操作抛出 SQL 错误（`QueryException: no such column`）。沙箱数据按 `developer_id`（用户级）隔离而非 `tenant_id`（租户级），`BelongsToTenant` 在此模型上语义不正确。**必须移除 `use BelongsToTenant` trait**，仅保留 `HasGlobalId`（如果需要）。沙箱的租户隔离通过 `sandbox_tenant_id` 关联实现，不需要全局租户作用域。
