# TASK-005c: [Auto-split from TASK-005]


**目标:** 追加发票相关配置项和中英文翻译 key

**只允许修改:**
- `config/pay.php`（追加 invoice 配置段）
- `lang/zh_CN/payment.php`（追加发票翻译 key）
- `lang/en/payment.php`（追加发票翻译 key）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 0.5 小时

**依赖:** 无

**实现要点:**
- `config/pay.php` 追加 `invoice` 段: prefix(`INV`), number_format(`INV-{YYYYMM}-{seq}`), pdf_template(`pdf.invoice`), storage_path, default_currency(`CNY`), default_due_days(30), tax_rules 配置
- 翻译 key 需覆盖: invoice_created, invoice_issued, invoice_paid, invoice_voided, invoice_cancelled, invoice_not_found, invoice_status_invalid, tax_rule_not_found, tax_region_unsupported, tax_number_invalid, tax_number_format_error, tax_exempt, invoice_pdf_generated, invoice_already_void, invoice_cannot_void
- 中英双语各约 15-16 个 key，保持与现有 51 条翻译风格一致

---



## 状态
READY
