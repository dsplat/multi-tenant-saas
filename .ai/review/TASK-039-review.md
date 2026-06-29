Let me find and read the actual implementation files first.
## Architecture

Clean separation: `ToolRegistryContract` (framework boundary) → `Tool` DTO → `ToolRegistry` implementation. Dual-source merge (DB + runtime, runtime 优先) 设计合理。`ToolHandlerContract` 统一 handler 接口，通过容器实例化，解耦良好。`HANDLER_NAMESPACE_PREFIX` 作为安全白名单边界是正确的做法。

依赖链清晰，无循环依赖。`ToolRegistryContract` 放在 `Contracts/` 下允许派生项目替换绑定。

## Code Quality

命名规范，PHPDoc 完整，符合 Laravel 风格。`toFunctionCallingFormat()` 遵循 OpenAI 标准。代码结构清晰，每个方法职责单一。

有一个重复模式：`get()` 和 `execute()` 中都有对 `tool === null` 的检查和 `str_starts_with` 的 handler 校验，但 `execute()` 已经通过 `get()` 取到了 `$tool`，所以没有实际重复，只是 `get()` 和 `getToolDefinitions()` 各自独立查询 DB，可接受。

## Type Safety

PHP 8.1+ readonly properties + constructor promotion，类型标注完整。`execute()` 返回 `mixed` 是合理的。`$schema` 在 DTO 中是 `array`，缺乏内部结构约束——这是可接受的 tradeoff（JSON Schema 本身就是动态结构）。`Tool::fromArray()` 对 `$data` 参数只标注了 `array`，没有对内部键的类型校验。

## Security

- **handler_class 白名单**：`str_starts_with` 限制了可实例化的命名空间前缀，防止了任意类实例化（防 gadget chain），这是好的。但仅检查前缀不检查类是否存在——攻击者可枚举该前缀下所有类名进行探测（information disclosure via exception messages）。
- **SQL 注入**：全部通过 Eloquent 参数化查询，安全。
- **租户隔离**：⚠️ **`all()` 查询不过滤 tenant_id**，会返回所有租户的工具。`get()` 和 `getToolDefinitions()` 同样不过滤。虽然 `isAvailable()` 做了租户判断，但它只返回 bool，不阻止数据泄露。`all()` 的调用方如果不额外过滤，会暴露其他租户的工具元数据（slug、name、description）。
- **异常信息泄露**：`execute()` 的异常消息包含 handler 类名，生产环境建议降级。
- **XSS**：tool 结果会返回给 AI，下游需确保输出编码。此处无直接 XSS 入口。

## Performance

- **`all()` 无缓存**：每次调用都查 DB + 构建 Collection，高频场景下应考虑缓存。
- **`isAvailable()` N+1**：如果在循环中对多个 slug 调用 `isAvailable()`，每次都会触发 `get()` → DB 查询。缺少批量版本。
- **`getToolDefinitions()` 已用 `whereIn`**：避免了 N+1，做法正确。
- **`runtimeTools` 无上限**：如果注册不受控，内存会无限增长（低概率风险）。

## Potential Bugs

1. **`all()` 合并顺序**：`$dbTools->merge($runtimeTools)` — Laravel Collection 的 `merge` 是后者覆盖前者，逻辑正确（runtime 优先）。✅
2. **`Tool::fromArray()` 无防御**：如果 `$data` 缺少 `slug` 或 `handler_class` 键，会抛出未类型化的 `ErrorException`（Undefined index），而非有意义的业务异常。
3. **`isAvailable()` 对 tenantId=0 的 DB 工具行为**：DB 中 `tenant_id=0` 的系统工具对所有租户可用——语义正确。但如果 DB 中有一条 `tenant_id=X` 且 `enabled=false` 的记录，`get()` 已经过滤了 `enabled=true`，所以不会泄露。✅
4. **并发注册**：`register()` 覆盖同 slug 的运行时工具，无警告。多进程场景下 runtimeTools 是进程隔离的，不存在跨进程竞争。✅

---

## Verdict

**PASS**（带建议）

【建议改进】（非阻塞）：

1. **`all()` 加租户过滤**：建议增加可选的 `int $tenantId = null` 参数，查询时 `where('tenant_id', 0)->orWhere('tenant_id', $tenantId)`，避免跨租户数据泄露风险。
2. **`fromArray()` 加 required key 校验**：在 `Tool::fromArray()` 中用 `array_key_exists` 或 `isset` 校验 `slug` 和 `handler_class`，抛出 `InvalidArgumentException`。
3. **异常消息脱敏**：`execute()` 中异常消息包含完整类名和错误细节，生产环境建议仅记录日志，返回通用错误。
4. **`isAvailable()` 批量版本**：提供 `areAvailable(array $slugs, int $tenantId): array` 方法，避免循环中的 N+1。
5. **`all()` 结果缓存**：考虑用 Laravel Cache 缓存 DB 查询结果（带 TTL），降低高频场景的 DB 压力。