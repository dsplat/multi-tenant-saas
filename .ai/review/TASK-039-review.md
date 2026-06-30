## Architecture

职责划分清晰：`ToolHandlerContract` 定义工具处理类的统一契约（`__invoke`），`ToolRegistry` 实现 `ToolRegistryContract` 负责双源合并、容器实例化和执行。`ToolHandlerContract` 放在 `Services/Agent/Contracts/` 而非顶层 `Contracts/`，与 `ToolRegistry` 同模块，边界合理。依赖方向正确：`ToolRegistry` → `ToolRegistryContract`（向上）+ `Tool` DTO + `AgentTool` Model（向下）。

## Code Quality

- **命名规范**：类名、方法名、变量名与现有 `AgentService` 风格一致。
- **可读性**：PHPDoc 完整，方法职责单一，逻辑清晰。
- **重复代码**：`loadDbTools()` 和 `findDbTool()` 中租户过滤的闭包逻辑完全相同，可提取为私有方法（非阻塞）。
- **`all()` 方法**：`$dbTools->keyBy('slug')->toArray()` → 合并 → `array_values()` → `collect()`，链路略显绕，可简化为直接合并两个 Collection。

## Type Safety

- 接口实现签名与 `ToolRegistryContract` 完全匹配。
- 返回类型标注完整（`Collection`、`?Tool`、`mixed`、`bool`）。
- `register()` 中 `new Tool(name: $slug, description: '')` — 运行时注册的 Tool 的 `name` 和 `description` 丢失真实值，但这不是类型错误，是数据完整性问题。

## Security

- **`withoutGlobalScope(TenantScope::class)` 使用模式**：`ToolRegistry` 绕过 `TenantScope` 后手动添加 `tenant_id` 过滤。这与 `AgentService::getAgentTools()` 完全一致，是项目已建立的模式。关键区别在于 `TenantScope` 的宏方法 `withoutTenantScope()` 有 `enforceAdminContext()` 检查，而直接调用 `withoutGlobalScope()` 没有——但两者在项目中已被并行使用，属于有意设计（全局工具查询需要跨租户）。
- **静态调用 `TenantContext::getId()`**：`AgentService` 通过构造函数注入 `TenantContextContract`，而 `ToolRegistry` 的 `loadDbTools()`/`findDbTool()` 直接静态调用 `TenantContext::getId()`。如果派生项目通过容器重新绑定了 `TenantContextContract`，`ToolRegistry` 会绕过替换。这是一个**架构一致性问题**，可能导致多租户隔离在替换场景下失效。
- `execute()` 中 `$handler($arguments, $tenantId)` 显式传入 tenantId 而非依赖 `TenantContext`，符合契约设计，安全。

## Performance

- **`getToolDefinitions()` 的 N+1**：对每个 slug 调用 `get()`，每个 `get()` 可能触发一次 DB 查询。当传入多个不存在的 slug 时，会产生 N 次无结果查询。可优化为批量查询。
- **`all()` 无缓存**：每次调用都查 DB。在同一请求内多次调用 `all()` 会重复查询。
- 无内存泄漏风险，`$runtimeTools` 是实例属性，随请求销毁。

## Potential Bugs

1. **`register()` 数据丢失**：`new Tool(slug: $slug, name: $slug, description: '', ...)` — 运行时注册的 Tool 的 `name` 和 `description` 被强制设为 slug 和空字符串。如果后续代码通过 `get()` 拿到 Tool 并使用 `$tool->name` 或 `$tool->description`（如展示给用户），会得到无意义的值。`register()` 签名不接受 name/description 参数，这是接口限制（`ToolRegistryContract::register` 只有 3 个参数），但实现层应至少在 PHPDoc 中说明这一取舍。

2. **`all()` 合并逻辑中的类型混合**：`$dbTools->keyBy('slug')->toArray()` 将 `Tool` 对象转为数组（`Tool` 是 `final class`，`toArray()` 不存在自动调用，Eloquent Collection 的 `toArray()` 会调用模型的 `toArray()`，但 `Tool` 不是 Model）——实际上 `keyBy('slug')->toArray()` 返回的是 `[slug => Tool]` 的数组（`Tool` 对象不会被序列化，因为 `final class` 没有 `__toArray`）。这部分逻辑正确，但注释中应说明。

3. **空 TenantId 回退行为**：`loadDbTools()` 和 `findDbTool()` 在 `TenantContext::getId()` 返回 null 时，只查询 `tenant_id = 0`（全局工具）。这意味着在无租户上下文的环境（如 CLI、队列）中，工具注册表只返回全局工具——这是合理行为，但缺少注释说明。

## Verdict

**PASS**

【建议改进】（非阻塞）：

1. **注入 `TenantContextContract` 而非静态调用 `TenantContext::getId()`**：`loadDbTools()` 和 `findDbTool()` 中的静态调用破坏了 `AgentService` 建立的 DI 模式。建议构造函数增加 `TenantContextContract $tenantContext` 参数，保持一致性，并在派生项目替换绑定时行为正确。
2. **`getToolDefinitions()` 优化为批量查询**：将不存在的 slug 收集后单次 `whereIn` 查询，避免 N+1。
3. **`all()` 结果在同一请求内缓存**：避免重复 DB 查询。
4. **`register()` 的 PHPDoc 补充说明**：明确运行时注册的 Tool 的 name/description 为占位值，仅用于 Function Calling 转换，不适合 UI 展示。
5. **`loadDbTools()` / `findDbTool()` 租户过滤闭包提取为 `tenantFilter()` 私有方法**：消除重复代码。