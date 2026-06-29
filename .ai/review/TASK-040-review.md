Let me read the relevant source files for full context.
## Architecture

**核心问题：`AgentServiceContract` 接口不存在。** `AgentService.php:22` 声明 `implements AgentServiceContract`，但整个代码库中找不到该接口文件。`TenancyServiceProvider.php:10` import 了它但从未绑定（第 111-115 行只绑定了 `AiTextServiceContract`）。运行时必崩 `Error: Class not found`。

**依赖模型全部缺失。** `Agent`（`AgentService.php:13`）、`AgentTool`（`AgentService.php:14`）两个 Eloquent Model 均不存在。所有 `Agent::create()`、`Agent::where()`、`AgentTool::withoutGlobalScope()` 调用运行时都会 fatal error。

**依赖事件类全部缺失。** `AgentCreated`、`AgentDisabled`、`AgentEnabled`（`AgentService.php:10-12`）三个事件类不存在，`Event::dispatch()` 无法触发。

**分层本身合理** — Contract → Service → Model 的依赖方向正确，`TenantContextContract` 强制注入 tenant_id 的设计意图清晰。但当前是一个"空壳"，所有依赖均未落地。

## Code Quality

- `listForTenant(int $tenantId)` 参数 `$tenantId` 完全未使用，实际用 `$this->resolveTenantId()` 获取。接口签名为 `int` 但实际依赖上下文，误导调用方。应去掉参数或使用它。
- `enable()`/`disable()`/`attachTools()`/`detachTools()`/`attachKnowledgeBases()`/`detachKnowledgeBases()` 没有 DB 事务保护，而 `create()`/`update()`/`delete()` 有。一致性差。
- `getBuiltinTemplates()` 和 `cloneFromTemplate()` 抛 `BadMethodCallException` 占位，但 TASK-040 范围声明"禁止改模型"，这两个方法不应出现在本次交付中——它们是 TASK-041 的职责。
- `getDefaultModelConfig()` 中 `preferred_provider` 和 `fallback_provider` 硬编码相同默认值，语义上"首选"和"降级"不应相同。
- 命名规范、方法拆分（`resolveTenantId`、`findAgentForCurrentTenant`）整体合理。

## Type Safety

- `TenantContextContract::resolveId()` 返回 `?string`，`resolveTenantId()` 强转 `(int)` 是安全的，但 null 检查后转 int 的模式在 `$tenantId === '0'` 时会得到 `0`，可能非预期。
- `create()` 使用 `$data['enabled'] ?? true` 处理布尔字段——当调用方显式传入 `false` 时结果正确，但当传入 `null` 时会变成 `true`，语义有歧义。
- `Agent::create()` 的 `$fillable` 未定义（Model 不存在），无法验证字段白名单。
- 所有 `array` 类型参数（`$data`、`$modelConfig`、`$toolSlugs`、`$kbIds`）缺少形状约束，PHPStan 无法静态验证。

## Security

- **租户隔离设计正确** — 所有读写操作通过 `findAgentForCurrentTenant()` 强制 tenant_id 过滤，防止跨租户访问。
- **无 SQL 注入** — 使用 Eloquent 参数化查询。
- **无 XSS** — 纯后端服务层。
- `create()` 未做 `$data` 字段白名单过滤——调用方可传入 `agent_id`、`tenant_id`、`is_builtin` 等非预期字段。虽然 `is_builtin` 有硬编码覆盖，但 `agent_id` 可能被注入。
- `update()` 使用 `??` 回退策略，不会覆盖为 `null`，但攻击者无法通过传 `null` 清空字段（如 `description`），这是安全的副作用但可能不符合业务需求。

## Performance

- `enable()`/`disable()` 各做一次 `findAgentForCurrentTenant`（SELECT）+ `save()`（UPDATE），合理，无 N+1。
- `attachTools()`/`detachTools()` 读-改-写模式在并发场景下存在 race condition：两个并发请求可能互相覆盖对方的工具列表。应使用 `DB::lockForUpdate()` 或原子 JSON 操作。
- `getAgentTools()` 的 `withoutGlobalScope(TenantScope::class)` + 手动 `where` 过滤是正确的做法，避免了 Global Scope 干扰。
- 无明显内存泄漏风险。

## Potential Bugs

1. **运行时必崩：`AgentServiceContract`、`Agent`、`AgentTool`、`AgentCreated`、`AgentDisabled`、`AgentEnabled` 六个类/接口全部缺失。** 任何方法调用都会触发 `Class not found`。
2. **`listForTenant(int $tenantId)` 参数被忽略** — 声明接收 `$tenantId` 但实际使用上下文解析值，接口契约与实现不一致。
3. **`enable()`/`disable()` 并发 race condition** — 读取 `enabled` 状态后直接 `save()`，无乐观锁或悲观锁保护。
4. **`attachTools()`/`detachTools()` 并发丢失更新** — 两个并发的 attach 操作可能只保留最后一个的结果。
5. **`TenancyServiceProvider` 缺少 `AgentServiceContract` 绑定** — import 了但未注册 singleton，容器 resolve 时会失败。
6. **`create()` 中 `metadata` 字段为 `null` 时 Eloquent JSON 列可能报错** — 如果 `metadata` 列定义为 JSON 类型，传入 `null` 取决于数据库驱动和 nullable 设置。

## Verdict

**FAIL**

【必须修复】：

1. **创建 `AgentServiceContract` 接口文件** — 当前不存在，`AgentService` 和 `TenancyServiceProvider` 均引用它，运行时 fatal error。
2. **创建 `Agent` Eloquent Model** — 所有 CRUD 方法依赖它，缺失则全部不可用。
3. **创建 `AgentTool` Model** — `getAgentTools()` 依赖它。
4. **创建 `AgentCreated`、`AgentDisabled`、`AgentEnabled` 事件类** — `enable()`/`disable()`/`create()` 依赖它们。
5. **在 `TenancyServiceProvider::register()` 中添加 `AgentServiceContract` 的 singleton 绑定** — 当前 import 了但未注册。
6. **修复 `listForTenant()` 签名** — 去掉无用的 `$tenantId` 参数，或改为使用传入值而非上下文。