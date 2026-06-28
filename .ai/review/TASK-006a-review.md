## Architecture

迁移文件结构合理：coupons 表与 coupon_usages 表在同一迁移中创建，符合"同一业务域一张迁移"的惯例。trial 字段追加迁移单独拆分，职责清晰。`down()` 方法正确处理了外键依赖顺序（先删 `coupon_usages` 再删 `coupons`）。与框架既有模式一致（`unsignedBigInteger` 主键、`HasGlobalId` 命名风格）。

**无架构问题。**

## Code Quality

迁移代码整洁，comment 注释完整覆盖每个字段。`$table->after()` 定位新列位置合理。Tenant 模型新增字段与已有 `trial_ends_at` 对齐排列，一致性好。

`coupon_usages` 表的 `currency` 字段与 `coupons.currency` 重复存储——这是有意为之的冗余（优惠券币种可能变更），可接受但建议在 comment 中说明原因。

**无严重质量问题。**

## Type Safety

类型标注完整：`decimal(12,2)` 用于金额，`unsignedSmallInteger` 用于有限范围值，`boolean` + `datetime` cast 正确。`max_uses` 使用 `unsignedInteger` 而非 `unsignedBigInteger`，在优惠券场景下足够（单券使用次数不会超过 42 亿）。

**无类型安全问题。**

## Security

- `Schema::create` 使用 Blueprint API，无 SQL 注入风险
- 外键 `onDelete('cascade')` 删除优惠券时级联清理使用记录，避免孤立数据
- `is_active` 默认 true，配合 `starts_at`/`expires_at` 时间窗口控制，设计合理
- `metadata` 使用 JSON 类型，框架层自动 cast

**无安全问题。**

## Performance

`coupons` 表索引覆盖了 `code`（unique）、`subscription_plan_id`、`is_active`、`expires_at`，满足常见查询。

`coupon_usages` 表索引：
- ✅ `coupon_id` (foreign key 自动索引)
- ✅ `[coupon_id, tenant_id]` 复合索引
- ✅ `invoice_id`
- ❌ **`user_id` 缺少独立索引**

`user_id` 为 nullable，但查询某用户的所有优惠券使用记录是标准场景（用户中心展示"我的优惠券"）。无索引将导致全表扫描。

## Potential Bugs

1. **`coupon_usages.tenant_id` 为 nullable**：框架通过 `TenantScope` 全局作用域自动添加 `WHERE tenant_id = ?`。如果某条 usage 记录的 `tenant_id` 为 NULL，它对所有租户查询不可见（admin 域名除外）。优惠券兑换必须绑定租户，建议改为 `NOT NULL`。

2. **`coupons.used_count` 与 `coupon_usages` 记录数的一致性**：两者需要同步更新。如果 `used_count` 只增不减（不处理退款撤销），则无问题；若支持撤销，需要在业务层保证原子性。当前迁移层面无法判断，属于业务层风险。

3. **`subscription_plan_id` 外键缺失**：`coupons.subscription_plan_id` 和 `coupon_usages.subscription_plan_id` 都没有外键约束。删除一个 subscription plan 后，引用它的优惠券不会被级联处理。这可能是有意为之（保留历史数据），但值得确认。

## Verdict

**PASS** — 代码质量、架构、安全、类型标注均达标。

### 【建议改进】（非阻塞）

1. `coupon_usages` 表的 `user_id` 列应增加索引：`$table->index('user_id')`，避免按用户查询优惠券使用记录时全表扫描。
2. `coupon_usages.tenant_id` 建议改为 `NOT NULL`，避免 `TenantScope` 过滤掉孤立记录。
