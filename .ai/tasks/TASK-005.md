# TASK-005: 发票与税务

**Sprint:** sprint-002  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF（开发性问题按最优解处理）

---

## 目标

实现发票生成(PDF)和税费计算引擎，使框架具备完整的发票开具和税务计算能力。

---

## 范围

**允许修改：**
- `src/Services/InvoiceService.php`（新建）
- `src/Services/TaxService.php`（新建）
- `src/Models/Invoice.php`（新建）
- `src/Models/InvoiceItem.php`（新建）
- `src/Models/TaxRule.php`（新建）
- `database/migrations/` 下新增 invoice 相关迁移
- `config/pay.php`（追加发票配置）
- `lang/zh_CN/payment.php`、`lang/en/payment.php`（新增发票翻译 key）
- `tests/InvoiceServiceTest.php`（新建）
- `tests/TaxServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### InvoiceService

1. **发票生成**: 基于现有 PdfService 生成 PDF 发票
2. **发票号规则**: `INV-{YYYYMM}-{序号}`，如 `INV-202606-0001`
3. **发票状态管理**: draft → issued → paid → void / cancelled
4. **开票历史查询**: 按租户、按时间范围、按状态筛选
5. **发票作废**: 已开具发票可标记 void，保留记录不删除

### TaxService

1. **多地区税率**: 支持 CN(13%/9%/6%/0%)、US(state tax)、EU(VAT)、UK(20%)
2. **税号校验**: VAT/GST 格式校验（中国税号、欧盟 VAT、英国 VAT）
3. **免税标记**: 部分地区/商品免税
4. **税前/税后金额计算**: 根据税率自动计算税额和总额
5. **税率生效日期**: 支持税率变更，按生效日期选取适用税率

### 数据模型

1. `invoices` 表: 租户ID、发票号(unique)、金额、税额、总额、币种、状态、出具日期、到期日、关联订阅ID、关联支付订单ID
2. `invoice_items` 表: 发票ID、描述、数量、单价、金额、税率、税额、关联类型(subscription/payment)、关联ID
3. `tax_rules` 表: 地区代码、税率、税种名称、生效日期、失效日期、是否默认

### TestCase 补充

在 TestCase.php 的 `setUpDatabase()` 中追加 `invoices`、`invoice_items`、`tax_rules` 三张表的 schema，结构须与 migration 一致，不加外键约束（SQLite 兼容）。

---

## 验收标准

- [ ] InvoiceService 可生成 PDF 发票，发票号符合规则
- [ ] 发票状态流转正常（draft → issued → paid → void）
- [ ] TaxService 支持 CN/US/EU/UK 四个地区的税率计算
- [ ] 税号校验功能正常（至少覆盖中国税号和欧盟 VAT）
- [ ] 三张表 migration 正常执行
- [ ] TestCase 追加新表 schema，`php vendor/bin/phpunit` 全绿
- [ ] 新增翻译 key 无缺失（zh_CN 和 en 双语）
- [ ] config/pay.php 追加发票相关配置项

---

## 给 AI 的补充说明

- 现有 PdfService 在 `src/Services/PdfService.php`，InvoiceService 应调用它生成 PDF
- 所有新模型必须 use `HasTenantScope` trait 和实现租户隔离
- 迁移文件命名格式: `2026_06_27_000010_create_invoices_table.php`（序号接续现有）
- 发票金额使用 decimal(12,2)，币种使用 string(3) ISO 4217
- TaxService 税率存储为 decimal(5,4)（如 0.1300 = 13%）
