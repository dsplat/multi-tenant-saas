# TASK-005b: [Auto-split from TASK-005]


**目标:** 创建 invoices、invoice_items、tax_rules 三张表的数据库迁移

**只允许修改:**
- `database/migrations/2026_06_27_000010_create_invoices_table.php`（新建）
- `database/migrations/2026_06_27_000011_create_invoice_items_table.php`（新建）
- `database/migrations/2026_06_27_000012_create_tax_rules_table.php`（新建）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 1 小时

**依赖:** 无

**实现要点:**
- 命名序号接续现有最后一个 `2026_06_27_000009`
- invoices 表: id, tenant_id(string), invoice_number(unique string), subtotal(decimal 12,2), tax_amount(decimal 12,2), total(decimal 12,2), currency(string 3 ISO 4217), status(string default 'draft'), issued_at(datetime nullable), due_date(date nullable), subscription_id(nullable), payment_order_id(nullable), timestamps
- invoice_items 表: id, invoice_id(unsignedBigInteger), description, quantity(decimal 8,2), unit_price(decimal 12,2), amount(decimal 12,2), tax_rate(decimal 5,4), tax_amount(decimal 12,2), related_type(nullable string), related_id(nullable), timestamps；不加外键约束（SQLite 兼容）
- tax_rules 表: id, region_code(string 如 CN/US/EU/UK), tax_rate(decimal 5,4), tax_name(string), effective_date(date), expiry_date(date nullable), is_default(boolean default false), timestamps

---



## 状态
READY
