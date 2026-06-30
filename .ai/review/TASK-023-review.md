Now I have all the context needed. Here is my review:

---

## Architecture

整体架构设计合理。`MetricsService` 负责采集与聚合，`SlaService` 负责可用性与违约检测，职责分离清晰。`CollectMetrics` 命令支持 `--aggregate-only` 和 `--with-sla` 选项，调度灵活性好。数据模型中 `tenant_id` 作为维度字段（非外键），适用于系统级聚合表，不使用 `BelongsToTenant` 是合理的，与 `AlertService` 等系统级服务的模式一致。

**MetricsService 和 SlaService 已在 `TenancyServiceProvider:172-173` 注册为 singleton**，符合任务要求。

`storeSnapshot()` 和 `recordEvent()` 直接使用 `DB::table()->insert()` 而非 Eloquent 模型写入，绕过了 `HasGlobalId` trait，需手动调用 `IdGeneratorContract`。虽然可工作，但与项目中通过模型操作的惯用法有一定偏差。鉴于 `AlertService` 也采用相同模式（直接 `DB::table()`），这属于可接受的系统级服务设计选择。

**无阻塞问题。**

## Code Quality

命名规范、常量定义、PHPDoc 注释整体质量良好。中文注释符合规范。常量定义（粒度、指标名、事件类型、严重级别）完整且语义清晰。

**问题：**

- `MetricsService` 中 `getErrorRate()`（L160-180）、`getEndpointDistribution()`（L211-229）、`collectSnapshot()` 中的系统级合并（L86-91）三个位置各自独立调用 `readSamples(null)` + `discoverTenantKeys()` + 遍历合并，存在明显的代码重复。应抽取一个 `getAllSamples()` 辅助方法。
- `discoverTenantKeys()` 既查数据库 `tenants` 表，又逐个读缓存判断活跃性，逻辑较为复杂且职责不够单一。
- `SlaEvent::range()` 和 `MetricsSnapshot::range()` 使用 `DB::table()` 静态查询而非 Eloquent scope，与其他模型的 query scope 模式不一致。但鉴于这些是只读查询且不涉及租户隔离，可接受。
- `lang/en/common.php` 的翻译 key `metrics_aggregated` 使用 `:from-granularity` 而非 `:from`（与 `lang/zh_CN` 的 `:from` 不一致），可能导致参数替换异常。实际调用处传的是 `'from' => $from`，英文翻译中应改为 `:from`。

## Type Safety

`TenantContext::getId()` 返回 `?string`，但 `MetricsService::recordRequest()` 和 `SlaService::recordEvent()` 的参数类型均为 `?int`。**代码中已做显式转换**：

- `MetricsService:53`: `$tenantId = $tenantId ?? ($contextId !== null ? (int) $contextId : null);`
- `SlaService:95`: 同样模式

转换已到位，无隐式类型转换风险。

`storeSnapshot()` 的 PHPDoc 标注 `$sampledAt` 为 `\DateTimeInterface|string|null`，实际代码中已转为 Carbon 对象再传入 `DB::insert()`，依赖 DB 层隐式转换为字符串，属于 Laravel 惯用法，可接受。

**无阻塞问题。**

## Security

无 SQL 注入风险（所有查询使用 Laravel query builder 参数绑定）。无 XSS 风险（纯后端服务）。`SlaService::history()` 中的过滤条件均通过 `where()` 参数绑定。告警信息通过 `trans()` 翻译，不直接暴露内部数据。`metadata` 字段使用 `json_encode` 存储，无注入风险。

**无阻塞问题。**

## Performance

- **`discoverTenantKeys()` 存在 N+1 查询风险**：先查 `tenants` 表获取所有活跃租户 ID，再逐个调用 `readSamples()` 读缓存。当租户数量大时（数千+），每次 `collectSnapshot()` 都会产生大量缓存读取。这是最值得关注的性能问题。
- **`aggregate()` 全量加载到内存**：`$rows = DB::table(...)->get()` 将一个时间窗口内所有原始快照加载到 Collection。若分钟级数据量大（高并发系统），内存压力显著。应考虑使用 `chunk()` 或 `cursor()`。
- `getErrorRate()` 和 `getEndpointDistribution()` 各自独立遍历全部样本并调用 `discoverTenantKeys()`，可合并为一次遍历。
- `collectSnapshot()` 中系统级聚合与租户级聚合是串行的，且调用了两次 `discoverTenantKeys()`（L88 和 L95），存在重复查询。

## Potential Bugs

- **`resolveEvent()` 存在竞态条件**：先 SELECT 再 UPDATE（L167-189），两个并发请求可能同时读到 `status=active`，然后都尝试 UPDATE。虽然第二个 UPDATE 会因 `WHERE status=active` 条件不满足而返回 0（不会产生数据错误），但 duration_sec 计算可能不准确。更稳健的做法是直接用单条 `WHERE status='active' -> UPDATE` 并从返回的旧行计算 duration。不过鉴于 SLA 事件解决频率极低，此问题实际影响很小。

- **`lang/en/common.php` 翻译 key 不一致**：`metrics_aggregated` 英文使用 `:from-granularity` 而中文使用 `:from`，`trans()` 传入的参数 key 是 `from`，英文翻译将无法正确替换。

- `calculatePercentiles()` 使用 floor 索引（L459: `floor(($count - 1) * $p)`），对于小样本量（如 10 个样本，P99 取 index=8 即第 9 个值），与行业标准 NIST 线性插值有偏差。但任务要求"排序后取百分位索引"，实现符合规格。

- `SlaEvent::range()` 的时间范围查询（L107-111）逻辑：`started_at <= to AND (ended_at IS NULL OR ended_at >= from)`，正确处理了跨窗口和进行中事件。

## Verdict

**PASS**

【建议改进】：

1. **`discoverTenantKeys()` N+1 优化** — 对于大规模租户场景，逐个读缓存判断活跃性开销大。建议在 `recordRequest()` 中维护一个轻量级的"活跃租户 ID 集合"缓存 key（如 `metrics:active_tenants`，用 Set/Sorted Set），`discoverTenantKeys()` 直接读取而非遍历全部租户。

2. **`aggregate()` 内存优化** — 将 `DB::table(...)->get()` 改为 `->cursor()` 或 `->chunk()`，避免高数据量时的内存压力。

3. **英文翻译 key 修复** — `lang/en/common.php` 的 `metrics_aggregated` 中 `:from-granularity` 应改为 `:from`，保持与中文翻译和调用处参数一致。

4. **抽取 `getAllSamples()` 辅助方法** — `getErrorRate()`、`getEndpointDistribution()`、`collectSnapshot()` 中重复的"读 null 缓存 + 枚举租户 + 合并"逻辑应抽取为一个方法，减少代码重复和多次调用 `discoverTenantKeys()` 的开销。
