## Architecture

实际交付物（`ToolRegistry.php`、`ToolHandlerContract.php`）**完全不在 diff 中**。diff 仅包含 `.ai/` 元数据变更和一个与 TASK-039（ToolRegistry）**完全无关**的 `coupons` 表定义。`coupons`/`coupon_usages` 混入此 PR 违反任务边界，应拆分为独立任务。review 文档引用了仓库中不存在的代码（`ToolRegistry.php`、`ToolHandlerContract.php`、`Tool` DTO），结论建立在假设之上。

**评价：FAIL**

## Code Quality

- Schema 定义结构清晰，字段命名规范，索引覆盖合理
- review 文档措辞比旧版改进明显，但内容基于不存在的代码，实际价值为零
- guardian.json 状态流转逻辑混乱：TASK-037 `skipped` + review `PASS` 矛盾；TASK-039 review 结论 FAIL 但 guardian 未记录修复项

**评价：FAIL**

## Type Safety

- `Schema::create` 中 Blueprint 类型使用正确（`unsignedBigInteger`、`decimal(12,2)`、`json` 等）
- `nullable()`/`default()` 语义明确
- `coupon_usages` 的 `tenant_id`/`user_id`/`invoice_id`/`subscription_plan_id` 均为 `unsignedBigInteger` 但**缺少外键约束**，无法保证引用完整性

**评价：有缺陷**

## Security

1. **`coupons.type` 无 enum 约束**：默认 `'fixed'` 但允许任意字符串注入，上层无校验则可存储恶意值
2. **`coupons.applies_to` 同理**：默认 `'subscription'` 但无数据库级限制
3. **`coupon_usages.tenant_id` 无外键约束**：可插入不存在的 tenant_id，破坏租户隔离数据完整性
4. **`coupon_usages.user_id`/`invoice_id`/`subscription_plan_id` 无 FK**：同上
5. **`coupons.metadata json nullable`**：无结构约束，依赖应用层校验
6. **`coupons.subscription_plan_id` 无 FK**：可引用不存在的 plan

**评价：有风险**

## Performance

- 索引设计基本合理：`code` unique、`(coupon_id, tenant_id)` 复合索引、`is_active`/`expires_at` 单列索引
- `coupons` 缺少 `(is_active, expires_at)` 复合索引——"查找有效且未过期的优惠券"是典型查询路径，单列索引不如复合索引高效
- 无 N+1 风险（纯 DDL）

**评价：基本通过**

## Potential Bugs

1. **`coupon_usages` 缺少级联删除策略**：`coupon_id` 有 `onDelete('cascade')`，但 `tenant_id`/`user_id`/`invoice_id` 无 FK，级联删除不会传播到这些关联
2. **`subscription_plan_id` 在两张表中都存在但均无 FK**：数据完整性完全依赖应用层
3. **review 文档自相矛盾**：TASK-037 review PASS + guardian SKIPPED；TASK-039 review FAIL + guardian 无修复记录
4. **`used_count` 无并发保护**：无乐观锁或原子更新策略，高并发下可能超卖（`max_uses` 检查与 `used_count` 更新非原子）

**评价：有 bug**

## Verdict

**FAIL**

【必须修复】：

1. **回滚 `tests/TestCase.php` 中 coupons 相关变更**：不属于 TASK-039 范围，混入此变更违反任务边界，应拆分为独立任务
2. **补齐实际交付物**：diff 中缺少 `ToolRegistry.php` 和 `ToolHandlerContract.php`，这才是 TASK-039 的核心产出
3. **review 文档必须基于实际存在的代码撰写**：当前两个 review 引用了仓库中不存在的文件，结论不可信
4. **`coupon_usages` 补齐外键约束**：`tenant_id`、`user_id`、`invoice_id`、`subscription_plan_id` 均需 FK 引用（如适用）或在文档中明确说明无约束的设计理由
5. **`coupons.type` 和 `coupons.applies_to` 添加 enum 约束**：至少在 migration 中使用 `->enum()` 限定合法值
6. **统一 guardian.json 状态与 review 结论**：消除 TASK-037 SKIPPED+PASS 和 TASK-039 FAIL+无记录的矛盾