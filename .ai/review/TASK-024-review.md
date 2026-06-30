Now I have a complete picture. The existing review at `.ai/review/TASK-024-review.md` contains **factual errors** about the code. Let me provide my independent assessment.

---

## Architecture

**模块边界合理。** `CostService` 负责成本分摊与盈亏分析，`ResourceService` 负责资源监控与告警，职责划分清晰。`CostAllocation` 模型使用 `BelongsToTenant` + `HasGlobalId` 符合项目既有模式。

**DI 模式正确。** 两个 Service 均通过构造器注入 `TenantContextContract`，遵循项目新代码规范（"New code should use DI injection of TenantContextContract with instance methods"）。`CostService` 注入 `AiUsageService` 实现成本联动，依赖方向合理。

**Singleton 注册已完成。** `TenancyServiceProvider:162-163` 已注册 `CostService` 和 `ResourceService` 为 singleton。

**一个设计瑕疵：** `CostAllocation::periodRecords()` 使用 `DB::table('cost_allocations')` 直接查询，绕过了 `BelongsToTenant` 全局作用域。虽然手动过滤 `$tenantId`，但与 trait 设计意图矛盾——其他查询路径通过 Eloquent 模型会自动应用租户隔离，这条路径不会。若未来有人在该表上添加软删除等 Eloquent 特性，此方法也会绕过。

---

## Code Quality

**可读性良好。** 注释充分，PHPDoc 完整，方法命名清晰。

**重复查询问题：**

1. `allocateAiCost()` 对 `ai_requests` 表执行两次几乎相同的查询（`SUM(cost)` 和 `GROUP BY model`）。可在一次 `GROUP BY model` 查询后，用 PHP `array_sum` 聚合总额。

2. `getStorageUsage()` 执行两次 `file_uploads` 查询（`SUM(size)` 和 `COUNT(*)`），可用单次 `selectRaw('SUM(size) as total_bytes, COUNT(*) as file_count')` 替代。

**方法过长：** `checkAlertThresholds()` 约 80 行，4 个指标检查逻辑可提取为独立方法（如 `checkDbConnections()`, `checkQueueBacklog()` 等），提升可测试性。

**`NotificationService` 的显式导入**（`ResourceService.php:10`）是多余的，因两者同属 `MultiTenantSaas\Services` 命名空间。但作为显式文档意图，保留无害。

---

## Type Safety

**类型标注完整。** 所有方法参数和返回值均有类型声明，PHPDoc `@return array{...}` 形状描述准确。

**一个不一致：** `CostService::resolveTenantId(?int $tenantId)` 参数无默认值（调用时必须传），而 `ResourceService::resolveTenantId(?int $tenantId = null)` 有默认值。功能上无 bug（`checkAlertThresholds` 内部调用 `$this->resolveTenantId()` 不传参，依赖默认值 null），但两个 Service 的同名辅助方法签名不一致，降低了可预测性。

---

## Security

**无高危问题。**

- `SHOW STATUS` 查询无用户输入拼接，安全。
- 所有 DB 查询使用参数绑定（Laravel query builder），无 SQL 注入风险。
- `financial_records` 和 `ai_requests` 查询均有 `Schema::hasTable()` 守卫。
- 成本数据无敏感字段暴露风险。

**低风险注意：** `getTenantResourceRatios()` 返回所有租户的存储占比。若未来暴露为 API，需考虑跨租户信息泄露（一个租户看到其他租户的资源占用比例）。当前仅内部服务调用，无风险。

---

## Performance

**循环查询问题（中等）：** `forecastCostTrend()` 按月循环调用 `aggregateTotalCost()`，默认 6 个月历史 = 6 次 DB 查询。可改为单次 `GROUP BY period` 查询，减少为 1 次。

**前述重复查询**（`allocateAiCost()` 2 次、`getStorageUsage()` 2 次）在数据量小时影响不大，但在高频调用场景下浪费连接资源。

**`periodRange()` 每次创建 Carbon 实例**，批量处理时有微小开销，可接受。

**线性回归计算**使用简单循环，数据量极小（≤12 个月），无性能问题。

---

## Potential Bugs

**1. `getStorageUsage()` 中除零保护缺失（低风险）**

```php
'total_mb' => round($totalBytes / 1024 / 1024, 2),
```

当 `$totalBytes = 0` 时结果为 `0.0`，数学上正确，无除零错误。无实际 bug。

**2. `allocateAiCost()` 无租户上下文时的边界行为**

当 `$tid === null` 且 `ai_requests` 表有全局（无租户）记录时，会聚合所有租户的 AI 成本。这可能是设计意图（系统级成本），但文档未明确说明。建议在 PHPDoc 中补充说明 null tenantId 的语义。

**3. `sendAlertNotifications()` 中 `class_exists()` + 非限定类名**

```php
if (class_exists(NotificationService::class) && method_exists(NotificationService::class, 'sendToTenantAdmins')) {
```

`NotificationService` 已在文件顶部通过 `use` 导入，`NotificationService::class` 解析正确。`class_exists()` 检查作为防御性编程（防止未来依赖变更导致类不存在），是合理的。此处无 bug。

**4. `checkAlertThresholds()` 中 `getStorageUsage()` 的 Schema 守卫**

`checkAlertThresholds()` 调用 `getStorageUsage()` 前未检查 `file_uploads` 表是否存在，但 `getStorageUsage()` 内部有 `Schema::hasTable('file_uploads')` 守卫，返回零值。无 bug，但告警逻辑会在表不存在时比较 `0 >= threshold`，可能产生误报（若阈值设为 0）。测试中已覆盖此场景。

**5. `forecastCostTrend()` 时间敏感性**

`projectForecast()` 内部使用 `now()->copy()->addMonths($i)`。测试中 mock 了 `Carbon::setTestNow('2026-06-15 12:00:00')`，正确但需注意：若测试运行在月末（如 1 月 31 日），`addMonths(1)` 会产生 2 月 28 日，预测周期可能不均匀。当前测试日期为 15 日，无此问题。

---

## Verdict

**PASS**

现有审查文件 (`.ai/review/TASK-024-review.md`) 中列出的 4 个 "必须修复" 问题均已在代码中解决：
- ✅ Singleton 注册：`TenancyServiceProvider.php:162-163` 已注册
- ✅ `resolveTenantId()` 参数：`ResourceService.php:273` 已接受 `?int $tenantId = null`
- ✅ TenantContextContract DI：`ResourceService.php:31` 构造器已注入
- ✅ NotificationService 导入：`ResourceService.php:10` 已显式导入

**【建议改进】（非阻塞）：**

1. **合并 `allocateAiCost()` 的两次查询为一次** — 减少 DB 调用，代码更简洁。
2. **合并 `getStorageUsage()` 的两次查询为一次** — `selectRaw('SUM(size) as total_bytes, COUNT(*) as file_count')`。
3. **将 `forecastCostTrend()` 的循环查询改为单次 `GROUP BY period`** — 减少 6 次查询为 1 次。
4. **统一 `resolveTenantId()` 方法签名** — `CostService` 与 `ResourceService` 的同名方法参数默认值不一致。
5. **提取 `checkAlertThresholds()` 中的各指标检查为独立方法** — 提升可测试性和可读性。
6. **在 `allocateAiCost()` PHPDoc 中补充 `$tenantId = null` 的语义说明** — 明确系统级成本聚合行为。
