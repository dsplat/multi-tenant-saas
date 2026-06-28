# TASK-005e: [Auto-split from TASK-005]


**目标:** 更新 TestCase 测试基础设施并编写 InvoiceService 和 TaxService 的完整测试

**只允许修改:**
- `tests/TestCase.php`（追加 invoices、invoice_items、tax_rules 三张表 schema）
- `tests/InvoiceServiceTest.php`（新建）
- `tests/TaxServiceTest.php`（新建）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 1.5 小时

**依赖:** TASK-005a, TASK-005b, TASK-005d

**实现要点:**
- TestCase.php 的 `setUpDatabase()` 末尾追加三张表的 `Schema::create()`，结构与 migration 一致，不加外键约束（SQLite 兼容）
- InvoiceServiceTest 需覆盖: 发票创建与发票号规则、状态流转全链路(draft→issued→paid, draft→issued→void, draft→cancelled)、PDF 生成、开票历史查询、作废后不可重复操作
- TaxServiceTest 需覆盖: CN/US/EU/UK 四地区税率计算、中国税号校验(15/18/20位)、EU VAT 格式校验、UK VAT 格式校验、免税判断、税率生效日期筛选
- 运行 `php vendor/bin/phpunit` 确认全绿


## 状态
READY
