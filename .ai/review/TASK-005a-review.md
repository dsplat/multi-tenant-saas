Now I have a complete picture. Here is the review:

---

## Architecture
模块边界清晰，三个模型职责单一：Invoice 聚合发票头信息，InvoiceItem 为明细行，TaxRule 管理税率规则。关系定义合理（Invoice 1:N InvoiceItem，InvoiceItem 有 morphTo 多态关联）。`BelongsToTenant` trait 复用得当，自动实现租户隔离。

**问题：缺少 `HasGlobalId` trait**。项目中 PaymentOrder、Tenant、SubscriptionPlan 等模型均使用 `HasGlobalId` 生成 16 位随机 ID，这是项目的核心设计决策（防 ID 枚举、全局唯一、分布式安全）。三个新模型均未使用该 trait，迁移也使用了 `$table->id()`（自增 ID），与项目既有模式不一致。如果这是有意为之需在文档中说明，否则应补上 `HasGlobalId` 并调整迁移。

Invoice 模型的 `subscription_id` 和 `payment_order_id` 在 `$fillable` 和迁移中均有外键字段，但未定义对应的 `subscription()` / `paymentOrder()` 关联方法。虽然不在 Task 明确要求范围内，但作为外键字段应有关联定义以便后续使用。

## Code Quality
命名规范、中文 docblock 风格与既有模型一致。状态常量定义清晰，`STATUSES` 数组便于校验。cast 使用 method-based 风格，符合项目惯例。

scope 方法 `scopeByStatus`、`scopeByRegion` 缺少 `Builder` 类型提示和返回类型声明，与 Tenant 模型的 `scopeActive(Builder $query): Builder` 风格不一致。但 PaymentOrder 等简单模型同样省略，属于项目内灰色地带。

`InvoiceItem::related()` 中 `morphTo(__FUNCTION__, 'related_type', 'related_id')` 参数显式传入列名，虽然 Laravel 默认值相同，但显式声明增强了可读性，值得肯定。

## Type Safety
cast 定义完整，金额字段统一 `decimal:2`，税率 `decimal:4`，布尔/日期类型均已声明。`morphTo` 返回类型 `MorphTo` 已标注。`HasMany` 和 `BelongsTo` 返回类型均已声明。

**不足**：scope 方法缺少 `Builder` 类型提示（`$query` 无类型标注），IDE 无法提供自动补全和静态分析支持。

## Security
- **SQL 注入**：所有 scope 使用 Eloquent 参数化绑定，无风险。
- **租户隔离**：`BelongsToTenant` 通过全局作用域自动注入 `WHERE tenant_id`，创建时自动填充 `tenant_id`，隔离机制完备。
- **XSS**：模型层无输出逻辑，不涉及。
- **敏感数据暴露**：模型未定义 `$hidden` 或 `$visible`，依赖 API Resource 层做脱敏（项目已有的模式）。
- **morphTo 注入**：`related_type` 接受任意类名，Laravel 的 morph map 可限制可实例化的类，但当前未见 morph map 配置。恶意 `related_type` 值可能导致意外类实例化——风险低但值得关注。

## Performance
无 N+1 问题（关联已定义但未强制 eager load，由调用方控制）。TaxRule 的 `scopeEffective` 利用 `effective_date` 索引进行范围查询，迁移中已有 `effective_date` 索引支撑。`nullableMorphs` 自动创建 `(related_type, related_id)` 复合索引。Invoice 迁移有 `(tenant_id, status)` 复合索引，覆盖按状态筛选场景。

无不必要的循环或内存风险。

## Potential Bugs
- **TaxRule `isEffective()` 对 null `effective_date` 的处理**：迁移中 `effective_date` 为 NOT NULL，但 `isEffective()` 中 `$this->effective_date` 的 null 检查意味着如果通过代码设为 null，方法会跳过日期判断直接返回 `true`。虽然数据库约束阻止了这种情况，但防御性编程角度应加 `assert` 或抛异常。
- **morphTo 安全**：未配置 morph map，`related_type` 若被注入恶意类名（如 `Illuminate\Support\Facades\App`），Laravel 会尝试实例化。建议在 `TenancyServiceProvider` 中配置 `Relation::morphMap()` 限制可关联的模型。
- **外键完整性**：`subscription_id` 和 `payment_order_id` 未在迁移中定义外键约束，删除关联记录后会产生孤儿记录。这是迁移层面的问题，不影响模型代码本身。

---

## Verdict
**PASS**

### 【建议改进】

1. **（中）评估是否需要 `HasGlobalId`**：项目所有核心模型均使用 `HasGlobalId` 生成 16 位随机 ID，三个新模型使用自增 ID 是显著的模式偏离。若有意为之，建议在迁移文件或模型 docblock 中注明理由；若为疏漏，应补上 `HasGlobalId` trait 并将迁移的 `$table->id()` 改为兼容方式。

2. **（低）Invoice 补充关联方法**：为 `subscription_id` 和 `payment_order_id` 定义 `subscription()` / `paymentOrder()` belongsTo 关联，便于后续业务代码使用。

3. **（低）scope 方法补全类型提示**：`scopeByStatus` 和 `scopeByRegion` 加上 `Builder` 参数类型和返回类型，与 Tenant 模型风格统一。

4. **（低）配置 morph map**：在 `TenancyServiceProvider` 中为 `InvoiceItem::related()` 配置 `Relation::morphMap()`，限制可实例化的类白名单，防止潜在的类注入风险。
