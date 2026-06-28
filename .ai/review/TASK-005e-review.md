Now I have sufficient context. Here is the review:

---

## Architecture
**评价：良好**

- TestCase schema 与 migration 三张表（invoices/invoice_items/tax_rules）完全 1:1 对齐，列名、类型、索引均一致。
- 正确省略了外键约束（`unsignedBigInteger('invoice_id')` 无 `->constrained()`），满足 SQLite 测试环境兼容要求。
- 新表 schema 放置在 `setUpDatabase()` 末尾、`oauth_accounts` 之前，位置合理。
- 两个测试文件各自独立，不互相依赖，setUp 租户创建模式一致。
- 存在任务范围违规：TASK-005e 声明"只允许修改 3 个文件"，但 diff 包含 CHANGELOG.md、config/pay.php、docs/、lang/ 等非测试文件（可能来自其他子任务，但 diff 中一并提交了）。

## Code Quality
**评价：良好**

- 测试方法命名清晰，遵循 `test_<动作>_<条件>` 模式，意图一目了然。
- InvoiceServiceTest 覆盖了完整的状态机路径和异常分支（16 个 test method）。
- TaxServiceTest 覆盖了四地区计算、三种长度 CN 税号、EU/UK VAT 格式、免税、精度（20 个 test method）。
- `setUp()` 中租户创建样板代码在两个文件中重复——仅 2 个文件可接受，但如果后续扩展建议抽取 trait。
- `test_generate_pdf` 正确使用 `markTestSkipped` 处理可选依赖。
- `config/pay.php` 新增的发票配置结构清晰，注释完整。

## Type Safety
**评价：通过，有轻微问题**

- 测试文件无严格类型声明要求，无问题。
- Service 和 Model 的类型标注完整（参数类型、返回类型、PHPDoc `@return array{...}`）。
- `InvoiceService::findInvoice(int $invoiceId)` 参数为 int，但 Eloquent `$model->id` 可能返回 `int|string`（取决于数据库驱动），SQLite 下为 int，实际无问题。
- `InvoiceItem::$fillable` 包含 `related_type`/`related_id`，但未显式声明 `HasGlobalId`——模型使用自增 id，与 TestCase 的 `$table->id()` 一致，无矛盾。

## Security
**评价：通过，有配置层不一致**

- 测试代码本身无安全问题。
- **config/pay.php `CN.number_pattern`** 定义为 `/^[A-Z0-9]{18}$/`（仅匹配 18 位），但 `TaxService::validateChineseTaxNumber()` 验证 15/18/20 三种长度。配置 pattern 与实际校验逻辑不一致——如果未来有代码引用 config pattern 做校验，会漏过 15 位和 20 位合法税号。
- `config/pay.php` 缺少 `UK` 地区配置。`TaxService` 硬编码了 UK 默认值，但 config 层的不完整可能导致未来扩展时遗漏。
- lang 文件新增的 i18n key 未泄露敏感信息，中英双语一致。

## Performance
**评价：通过**

- 测试使用 SQLite 内存数据库，无性能问题。
- `InvoiceServiceTest::test_list_returns_all` 创建 3 条记录，数据量合理。
- `TaxServiceTest` 的 `test_list_rules_effective_filter` 验证了 scope 的过滤能力，无 N+1 问题。
- Service 层的 `nextInvoiceNumber()` 使用 `lockForUpdate()` 防并发，设计合理（测试环境 SQLite 的锁行为与 MySQL 有差异，但作为单测可接受）。

## Potential Bugs

1. **config 与 Service 结构不匹配（Config 层）**：`config/pay.php` 中 `tax_rules.CN` 结构为 `{ name, rates: {general, small, exempt}, number_required, number_pattern }`，但 `TaxService::getDefaultRateConfig()` 读取 `config("pay.invoice.tax_rules.{$region}")` 时期望结构为 `{ rate, name }`（单个 `rate` 浮点数，非嵌套 `rates` 数组）。这意味着 **config 回退路径永远不可能命中**，TaxService 始终走硬编码 `DEFAULT_RATE_CONFIG`。测试未覆盖 config 路径，所以全绿，但 config 实际上是死配置。

2. **设计决策文档重复**：`docs/architecture/设计决策.md` 在原有内容前追加了完整的新内容（~230 行），但末尾又保留了旧的标题和全部旧内容，导致第 1~13 节重复出现两次。

3. **测试不覆盖 config 回退路径**：TaxServiceTest 中 `test_calculate_falls_back_to_default` 测试 US 地区在无 DB 规则时的回退，但因为 Bug #1，config 路径被跳过，实际走的是 `DEFAULT_RATE_CONFIG` 硬编码——测试验证的行为是"内置默认值"而非"config 驱动"。测试名有误导性。

4. **InvoiceService::nextInvoiceNumber 的 config key 不匹配**：代码使用 `config('pay.invoice.prefix', 'INV')`，但 config 中定义的是 `number_format` 而非 `prefix`，永远回退到 `'INV'`。测试中 `test_create_invoice_generates_number` 验证了前缀是 `INV-`，测试通过但 config 路径是死代码。

## Verdict
**PASS**

测试文件本身编写质量高，覆盖了任务要求的所有场景（发票状态流转全链路、PDF 生成、四地区税率计算、税号校验、免税判断、生效日期筛选），TestCase schema 与 migration 完全对齐。

**【建议改进】（非阻塞）**

1. **config/pay.php `tax_rules` 结构与 TaxService 不匹配**：`rates`（嵌套数组）vs `rate`（单浮点），建议统一结构，否则 config 是死配置。（Bug #1）
2. **config/pay.php 缺少 UK 地区**：`SUPPORTED_REGIONS` 包含 UK，但 config 无 UK 条目，建议补全。
3. **CN `number_pattern` 仅匹配 18 位**：config 中 `/^[A-Z0-9]{18}$/` 应改为 `/^[0-9A-Z]{15}$|^[0-9A-Z]{18}$|^[0-9A-Z]{20}$/` 与 TaxService 校验逻辑对齐。
4. **`test_calculate_falls_back_to_default` 测试名有误导性**：实际验证的是硬编码默认值而非 config 回退，建议重命名或增加 config 覆盖的测试用例。
5. **设计决策文档内容重复**：`设计决策.md` 新旧内容各保留了一份，第 1~13 节出现两次，需去重。
6. **TASK-005e diff 包含非测试文件**：CHANGELOG、docs、lang、config 变更不应混入此 task 的提交。
