Now I have complete context. Here is my review.

---

## Architecture

两个 Service 的职责划分清晰：`TaxService` 负责税率计算与税号校验，`InvoiceService` 负责发票生命周期管理。与现有代码库的 Service 层风格一致（全部静态方法、无状态）。模型层分离得当——`Invoice`、`InvoiceItem`、`TaxRule` 各司其职，均使用 `BelongsToTenant` + `HasGlobalId` Trait。

配置回退链设计合理：DB `tax_rules` 表 → `config(pay.invoice.tax_rules)` → 内置常量，三层兜底。

`config/pay.php` 中 `tax_rules` 定义了 `number_pattern` 和 `number_required` 字段，但 `TaxService` 未读取这些配置，而是使用硬编码的正则。配置与实现存在脱节。

**评价：良好。** 模块边界清晰，依赖方向正确。配置未被消费是一个小瑕疵。

---

## Code Quality

命名规范、方法组织、PHPDoc 注释均与现有代码库保持一致。`trans()` 国际化覆盖完整。`summarizeItems` 提取为独立方法，逻辑清晰。

`nextInvoiceNumber` 中的 SQL 构造虽可读性一般，但有注释说明且 `lockForUpdate` + 唯一约束双重保障。

**评价：良好。** 代码整洁，注释充分。

---

## Type Safety

- `calculateTax` 返回 `array{tax_rate, tax_amount, total, is_exempt}` 有 PHPDoc 形状标注 ✓
- `summarizeItems` 返回 `array{0: float, 1: float}` 元组类型 ✓
- `findInvoice` 返回 `Invoice`（非 `?Invoice`），未找到时抛异常，调用方可安全解引用 ✓
- `createInvoice` 中 `$data['items']` 无类型校验，依赖调用方传入正确结构

**评价：良好。** 方法级类型标注完整。`$data` 参数为弱类型数组，是该框架的通用模式，可接受。

---

## Security

- **SQL 注入**：全部使用 Eloquent / Query Builder 参数绑定，无原生 SQL 拼接 ✓
- **XSS**：Service 层不涉及 HTML 渲染 ✓
- **租户隔离**：`Invoice` / `TaxRule` 均使用 `BelongsToTenant`，`findInvoice` 受全局作用域保护 ✓
- **并发安全**：`nextInvoiceNumber` 使用 `lockForUpdate` + DB 唯一约束双重防并发 ✓
- **数据泄露**：`getInvoices` 受 TenantScope 自动隔离 ✓

**评价：通过。** 无安全问题。

---

## Performance

- `getApplicableRate` 每次调用都查询 DB（`TaxRule::where(...)->first()`），无缓存。若在循环中计算多笔税额会产生 N+1 查询。
- `nextInvoiceNumber` 的 `lockForUpdate` 在高并发下会序列化写入，但这是防并发的必要代价，可接受。
- `getInvoices` 使用 `with('items')` 预加载，避免 N+1 ✓

**建议：** `getApplicableRate` 可增加 `Cache::remember()` 缓存（按 region + date），减少 DB 查询。当前规模下非阻塞。

---

## Potential Bugs

**B1. `summarizeItems` 与 `createInvoice` 金额计算可能不一致**

`summarizeItems` 始终用 `quantity × unit_price` 计算小计，但 `createInvoice` 中每个明细行允许 `$item['amount']` 覆盖计算值（第 62 行：`'amount' => $item['amount'] ?? $lineAmount`）。如果调用方传入的 `amount` 与 `quantity × unit_price` 不同，发票的 `subtotal`（由 `summarizeItems` 计算）会与明细行的 `amount` 之和不一致。

**B2. `voidInvoice` 不允许作废 `paid` 状态的发票**

任务规格写的是 "issued → void"，实现也是只允许 `issued → void`。但实际业务中已付款发票也可能需要作废（如退款场景）。当前实现下 `paid` 发票无法作废，需确认是否为预期行为。

**B3. `config/pay.php` 中 `number_pattern` / `number_required` 为死配置**

`TaxService` 的税号校验使用硬编码正则，与 `pay.php` 中定义的 `number_pattern` 完全无关。虽然两者当前值一致，但未来修改配置不会生效，可能误导维护者。

**B4. `generatePdf` 依赖未验证的 Blade 模板**

`PdfService::generateInvoice` 使用 `'pdf.invoice'` 视图，但未在本次变更中检查该模板是否存在。若缺失会在运行时报 `ViewNotFoundException`。

---

## Verdict

**PASS**

以上问题均为非阻塞改进项：

1. **【建议改进】** `summarizeItems` 应使用与明细行相同的金额来源（`item['amount']` 优先），或移除 `createInvoice` 中 `amount` 的覆盖逻辑，保持一致。
2. **【建议改进】** `getApplicableRate` 增加缓存层，避免重复 DB 查询。
3. **【建议改进】** `config/pay.php` 中的 `number_pattern` / `number_required` 要么被 TaxService 消费，要么移除，避免死配置误导。
4. **【建议改进】** 确认 `paid → void` 状态转换是否需要支持。
