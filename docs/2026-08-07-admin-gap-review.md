# Admin 后台功能缺口全面 Review（框架 vs 项目层归属）

**会话时间**: 2026-08-07
**范围**: 框架 multi_tenant_saas（37 模块）+ 项目 scrm-platform（20 模块）
**触发**: 用户反馈——租户订阅/购买/订单处理、租户 AI credit 消耗缺管理面；平台无商品池供租户销售/分销
**结论先行**: 后端能力远比 admin 暴露面完整，缺口集中在「API/Service 已有、admin UI 未建」与「菜单未注册」两类；商业核心归框架层，分销运营归项目层

---

## 一、现状盘点

### 1.1 框架 37 模块 admin 覆盖矩阵

| 分类 | 模块 | 说明 |
|---|---|---|
| **有 admin 页 + 路由**（17） | Ai(1) ApiToken(1) Auth(3) Billing(3) Commerce(2) DeveloperPortal(1) Domain(1) Infrastructure(8) Logging(1) Notification(2) Operator(1) Payment(1) Platform(5) Plugin(1) Sms(1) SSL(1) User(3) | 括号内为 element-plus 页面数 |
| **有 admin 路由、无页面**（3） | Monitoring Storage Workflow | 接口就绪，纯缺 UI |
| **完全无 admin**（17） | AiStreaming Campaign Contracts Conversation Coupon Course Event Form Ibot Knowledge Logistics Lottery Order Pay Product Ticket Voting | 多为租户自服务域（合理无 admin）或旧模块（见 §4.3） |

### 1.2 项目层 scrm-platform（20 模块）

全部模块只有 `resources/console/`（租户后台），**admin 资源 = 0**。
Distribution（分销）模块已相当完整：9 模型（佣金规则/分销关系/账本/提现/结算单）+ 27 路由 + console 页 `DistributionManagement.vue`（租户自服务），但无平台侧监管面。

---

## 二、用户点名方向的缺口分析

### 2.1 租户订阅 / 购买 / 订单处理 —— 后端完备，admin 暴露 ~30%

**后端已有**（Billing 模块）：
- `SubscriptionPlan` / `SubscriptionService`：订阅/取消/变更/历史，租户侧 API 完整（`tenant.php` 15 条路由）
- `PaymentOrder` + `PayService`：微信/支付宝/Stripe/PayPal/银联驱动 + 回调验签
- `RefundService` / `InvoiceService` / `DunningService`（催收）/ `FinancialRecord` / `SubscriptionHistory` / `TaxService`
- admin 已暴露：仅**套餐 CRUD**（Plans.vue）+ **积分批量充值**（Credits.vue）
- Payment 模块 `PaymentOrders.vue`：支付订单列表只读

**缺口**：

| # | 缺口 | 后端现状 | 工作量 |
|---|---|---|---|
| B1 | 租户订阅总览（谁在订什么套餐、到期/续费/取消状态、手动干预） | Service 全有，缺 admin 端点 | 端点+页 |
| B2 | 购买订单生命周期（状态推进、回调排查、手动补单/关单） | PaymentOrder 模型 + PayService 已有，admin 仅列表只读 | 端点+页 |
| B3 | 退款操作面 | RefundService 已有，无 admin 入口 | 端点+页 |
| B4 | 发票与财务对账（订单/流水/发票三方） | InvoiceService/FinancialRecord 已有 | 端点+页 |
| B5 | 催收/欠费处置（DunningService） | 服务已有，无运营入口 | 端点+页 |

**归属：框架层**（多租户 SaaS 通用计费，与业务无关）。

### 2.2 租户 AI credit 消耗 —— 记账链路完整，缺消耗透视

**后端已有**：
- `CapabilityBillingService`：按能力计价扣积分（text/image/video 分别计价）
- `AiUsageService`：配额预算、超额告警，与 Billing `UsageService` 集成写 `usage_records`
- `credit_transactions`（流水）/ `ai_requests`（请求日志）/ `ai_usage_quotas`（配额）
- admin 已暴露：`Credits.vue` 总览（总消耗统计 + 批量充值）、`Quotas.vue`、AiSettings「租户配置」tab（仅配置，无消耗）

**缺口**：

| # | 缺口 | 后端现状 | 工作量 |
|---|---|---|---|
| C1 | 租户 AI 消耗明细（按租户/模型/能力/周期聚合，跨租户对比） | 数据全在 ai_requests/usage_records/credit_transactions，缺 admin 聚合端点 | 端点+页 |
| C2 | 扣费异常排查（扣费失败、预算超限、告警记录） | 日志分散，无集中视图 | 端点+页 |
| C3 | 能力计价配置后台化（capability_pricing 仍在 config） | P1 已建后台化机制，扩展即可 | 小 |

**归属：框架层**（AI 网关与积分账户都在框架）。

### 2.3 租户商城 / 平台商品池 / 分销 —— 缺口最集中处

**后端已有**（Commerce 模块，按 `tenant-commerce-plan.md` Phase 1/2/3 落地）：
- **统一 SKU 商品池**：`CommerceSku` + admin API 完整（`/admin/commerce/skus` CRUD）——**但零 admin 页面，平台无法建商品池**（用户痛点直接根因）
- **供给授权**：`SupplyGrant` + admin API 完整（总览/停供/恢复）——同样零页面
- **平台内容库**：`PlatformContent/Pack` + admin API + `ContentLibrary.vue` 页面（有，但**菜单无入口**）
- **订单履约**：`CommerceOrder` + fulfillment handlers + `CommerceOrders.vue`（有，但**菜单无入口**）
- **租户侧自服务已就绪**：console 有 `SkuCatalog.vue` / `OrderList.vue` / `OrderDetail.vue`（租户选品下单）——即「平台建好池子，租户即可选入销售」只差平台侧供给页

**缺口**：

| # | 缺口 | 后端现状 | 工作量 |
|---|---|---|---|
| M1 | **SKU 商品池管理页**（积分包/模块开通项/SKU 包：建品、定价、上下架） | admin API 已全，纯 UI | 纯前端 |
| M2 | **供给授权管理页**（租户选入记录、停供/恢复） | admin API 已全，纯 UI | 纯前端 |
| M3 | Commerce 菜单入口（commerce-orders / content-library / 新增两页） | 路由已注册，菜单硬编码漏配 | 极小 |
| M4 | 分销平台监管（项目层 Distribution：提现审批、佣金规则抽查、跨租户视图） | 项目层 API 27 条，仅租户自服务页 | 端点+页（项目层） |

**归属**：M1-M3 **框架层**（平台供给是 SaaS 核心商业模式）；M4 **项目层**（SCRM 分销运营）。

---

## 三、框架 vs 项目层归属原则

| 层 | 判定标准 | 本次缺口归属 |
|---|---|---|
| **框架** multi_tenant_saas | 任何多租户 SaaS 平台通用、与 SCRM 业务无关：计费/账户/支付/发票、平台供给（SKU/内容）、模块开通、配额、审计、租户生命周期 | B1-B5、C1-C3、M1-M3 |
| **项目** scrm-platform | SCRM 业务域：客户/营销/抽奖/优惠券/会员（User 积分商城）/分销运营，及其平台监管延伸 | M4（分销监管）；其余 19 模块维持 console 自服务 |

**铁律复核**（身份模型）：购买/选入主体 = Operator（scope=tenant）；消费受众 = User；禁止 customer 作为身份主体。分销佣金主体一律 user_id。

---

## 四、系统性问题（根因层）

1. **菜单硬编码**（`AdminLayout.vue` navSections）：新页面不自动出现。CommerceOrders/ContentLibrary/Sandbox/TenantApplications 等有路由无菜单。建议改为「模块 routes.ts 声明 meta.menu 分组 → 布局动态聚合」，根治入口遗漏（同类缺陷已发生 3 次：AI 配置、Commerce 双页、Sandbox）。
2. **视图自动发现与自定义 routes.ts 互斥规则**：有 routes.ts 的模块必须手工声明全部页面（P2 已踩坑修复 SystemSettings 白屏）。与问题 1 一并纳入菜单/路由机制改造。
3. **旧模块处置**：`Product` / `Order` / `Pay` 三模块仅存 api.php 与模型，商业化已由 Commerce 统一承接（tenant-commerce-plan 明确）。建议标记 deprecated、评估下线，避免误导「商品池在旧模块」。
4. **项目层 admin 为零**：scrm 20 模块无 admin 资源属正常（租户自服务域），但涉及平台资金/合规的监管面（分销提现）应补。

---

## 五、建议实施优先级（P3+ 候选）

| 优先级 | 项 | 理由 |
|---|---|---|
| **P3**（纯前端、1-2 天） | M1 SKU 商品池页 + M2 供给授权页 + M3 菜单入口 | admin API 全就绪，直接打通「平台建池 → 租户选入销售」闭环，用户核心痛点 |
| **P3.5**（小） | C3 能力计价后台化 + B2 订单手动补单/关单 | 复用 P1 机制 / PayService 已有 |
| **P4**（中） | C1 消耗明细 + B1 订阅总览 | 需新增 admin 聚合端点 + 页 |
| **P5**（中） | B3 退款 + B4 发票对账 + B5 催收 | 财务运营完善 |
| **P5**（项目层） | M4 分销监管 | scrm-platform 内实施 |
| **架构债** | §4.1 菜单动态化改造 | 建议与 P3 同批做，杜绝入口遗漏复发 |

---

## 六、附：关键文件索引

- 设计文档：`docs/tenant-commerce-plan.md`（已实施基线）、`docs/commerce-sku.md`、`docs/commerce-module-plan.md`
- Commerce admin API：`src/Modules/Commerce/Routes/admin.php`（SKU/订单/供给/内容 34 条）
- Billing：`src/Modules/Billing/Routes/{admin,tenant,api}.php`、`Services/{Subscription,Refund,Invoice,Dunning,Pay,Usage}Service.php`
- AI 计费：`src/Modules/Ai/Services/Capability/CapabilityBillingService.php`、`AiUsageService.php`
- 项目层分销：`scrm-platform/app/Modules/Distribution/`（9 模型 + 27 路由 + console 页）
