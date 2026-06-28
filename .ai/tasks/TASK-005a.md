# TASK-005a: [Auto-split from TASK-005]


**目标:** 创建 Invoice、InvoiceItem、TaxRule 三个 Eloquent 模型，建立发票与税务的数据模型层

**只允许修改:**
- `src/Models/Invoice.php`（新建）
- `src/Models/InvoiceItem.php`（新建）
- `src/Models/TaxRule.php`（新建）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 1 小时

**依赖:** 无

**实现要点:**
- 所有模型使用 `BelongsToTenant` trait（位于 `src/Concerns/BelongsToTenant.php`），实现租户隔离
- Invoice: `fillable` 包含 tenant_id, invoice_number, subtotal, tax_amount, total, currency(3), status, issued_at, due_date, subscription_id, payment_order_id；状态常量 draft/issued/paid/void/cancelled；关联 items() hasMany、tenant() belongsTo
- InvoiceItem: `fillable` 包含 invoice_id, description, quantity, unit_price, amount, tax_rate(decimal 5,4), tax_amount, related_type, related_id
- TaxRule: `fillable` 包含 region_code, tax_rate(decimal 5,4), tax_name, effective_date, expiry_date, is_default；scope `effective()` 按日期筛选生效规则
- 金额字段 cast 为 `decimal:2`

---



## 状态
READY
