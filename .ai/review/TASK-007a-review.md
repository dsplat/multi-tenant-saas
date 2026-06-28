Now I have all the context. Let me write the review.

---

## Architecture
模型层遵循项目既有的 `HasGlobalId + BelongsToTenant` 组合模式（与 FinancialRecord、Invoice、InvoiceItem 等一致），边界清晰。`usage_records` 迁移独立于 `SubscriptionPlan` 的按量计费字段扩展，职责分离合理。`period` 使用 `string(7)` 存储 YYYYMM 格式作为计费周期索引键，设计上避免了按月分表的复杂度，是合理的退化选择。`metered_price` 用 JSON 存储（而非单独的价格表），对早期阶段足够，后续如需支持多维度阶梯计价可能需要重构为关联表——但当前不需要过度设计。

## Code Quality
- 命名一致：`usage_record_id`、`metric_type`、`recorded_at` 遵循项目 snake_case 惯例。
- `UsageRecord` 结构精简，无冗余方法，与 `FinancialRecord` 风格一致。
- `metered_unit => 'string'` cast 是无操作 cast（数据库列本身就是 string），不影响运行但属于冗余声明。非阻塞。
- 迁移文件有 `->comment('计费周期，格式 YYYYMM')` 但 TestCase schema 没有对应 comment，不一致但不影响功能。

## Type Safety
- `value: decimal:4` ↔ 迁移 `decimal(18, 4)` ✓
- `recorded_at: datetime` ↔ 迁移 `timestamp` ✓
- `metadata: array` ↔ 迁移 `json nullable` ✓
- `overage_price: decimal:4` ↔ 迁移 `decimal(10, 4)` ✓
- `period` 无 cast（默认 string），与迁移 `string(7)` 一致 ✓
- `metered_unit: string` 无害的冗余 cast（见 Code Quality）

## Security
- `BelongsToTenant` 提供全局租户隔离作用域，`tenant_id` 在 creating 时自动填充，防止跨租户数据泄露 ✓
- 无原始 SQL、无用户输入直接拼接 ✓
- `metadata` 为 JSON nullable，写入时由 Laravel 自动序列化，无注入面 ✓
- 迁移未对 `tenant_id` 添加外键约束——与项目其他表（financial_records 等）一致，不影响安全性但限制了参照完整性

## Performance
- 复合索引 `[tenant_id, metric_type, period]` 精确覆盖典型查询模式（按租户+指标+周期聚合用量），设计合理 ✓
- 无 N+1 风险（模型层未定义 eager load 关系）✓
- `value` 精度 `decimal(18,4)` 足够存储大额用量，不会溢出

## Potential Bugs
1. **`period` 列宽度与格式注释不一致**：迁移注释 `格式 YYYYMM`（6 字符），但列宽设为 7。如果是 YYYYMM 不需要 7；如果允许 YYYY-MM 则注释应更新。不影响运行但存在歧义。
2. **Task spec 要求 `bigIncrements` 但实现用 `unsignedBigInteger()->primary()`**——实际上后者才是正确的，因为 `HasGlobalId` 将 `getIncrementing()` 设为 `false`，用 `bigIncrements` 反而会冲突。实现优于 spec，无问题。

## Verdict
**PASS**

【建议改进】
1. 统一 `period` 的列宽或注释——建议改为 `string('period', 6)` 以精确匹配 YYYYMM 格式，或保留 7 并更新注释为 `YYYYMM 或 YYYY-MM`。
2. `metered_unit => 'string'` cast 可移除（无操作），或添加注释说明这是为了文档化意图。
3. 迁移中 `period` 的 `->comment()` 在 TestCase schema 中缺失——如 TestCase 需要精确镜像迁移定义，可补上；如不需要则无影响。
