# TASK-005d: [Auto-split from TASK-005]


**目标:** 实现 TaxService（多地区税率计算、税号校验）和 InvoiceService（发票生成、状态管理、PDF 输出）

**只允许修改:**
- `src/Services/TaxService.php`（新建）
- `src/Services/InvoiceService.php`（新建）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 2 小时

**依赖:** TASK-005a, TASK-005b, TASK-005c

**实现要点:**
- TaxService:
  - `calculateTax(string $region, float $amount, ?string $productType = null): array` — 返回 [tax_rate, tax_amount, total, is_exempt]
  - `validateTaxNumber(string $region, string $taxNumber): bool` — 中国税号 15/18/20 位、EU VAT 格式校验、UK VAT 格式校验
  - `getApplicableRate(string $region, ?Carbon $date = null): TaxRule` — 按生效日期选取
  - `isExempt(string $region, ?string $productType = null): bool`
  - 支持 CN(13%/9%/6%/0%)、US(state tax)、EU(VAT)、UK(20%) 四个地区
- InvoiceService:
  - `createInvoice(array $data): Invoice` — 生成发票号(INV-{YYYYMM}-{seq})，创建发票及明细
  - `issueInvoice(int $invoiceId): Invoice` — draft → issued
  - `markPaid(int $invoiceId): Invoice` — issued → paid
  - `voidInvoice(int $invoiceId): Invoice` — issued → void（保留记录不删除）
  - `cancelInvoice(int $invoiceId): Invoice` — draft → cancelled
  - `generatePdf(int $invoiceId): string` — 调用 PdfService::generateInvoice() 输出 PDF
  - `getInvoices(array $filters): Collection` — 按租户/时间/状态筛选
  - 发票号生成需保证唯一性，使用 DB::lock 或唯一约束防并发

---



## 状态
READY
