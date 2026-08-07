# P5 订阅与订单运营建设（Admin 干涉面 Phase 5）

**会话时间**: 2026-08-07（P4 后续会话）
**范围**: 框架 multi_tenant_saas → neihang.com 生产部署（admin.neihang.com）
**状态**: 部署验证中
**前置**: [P4 供货结算](./2026-08-07-p4-supply-settlement.md)、[admin 功能缺口 Review](./2026-08-07-admin-gap-review.md)
**用户指示**: 分销相关事项（提现审批、M4 分销监管）本期起暂停，不纳入任何阶段

---

## 一、本期范围

| 项 | 内容 | 实现 |
|---|---|---|
| **B1 租户订阅总览** | 跨租户订阅列表（套餐/状态/到期/试用/续费开关）、汇总卡、手动取消/恢复续费/变更套餐、订阅历史 | `SubscriptionAdminController` + `Subscriptions.vue` |
| **B2 订单运营** | 订单列表/详情、手动补单（mark_paid）、关单（close） | `PaymentOrderAdminController` + `PaymentOrders.vue` 扩展 |

不做：B3 退款 / B4 报表 / B5 催收（Phase 6）、分销监管（暂停）。

## 二、后端

### 2.1 B1 订阅总览（Billing 模块）

新控制器 `src/Modules/Billing/Http/Controllers/Admin/SubscriptionAdminController.php`（ensureSuperAdmin + AuditService）：

| 端点 | 说明 |
|---|---|
| `GET /api/v1/admin/billing/subscriptions` | 跨租户列表（keyword/plan/status 过滤）+ summary（租户总数/订阅中/30天内到期/到期不续费） |
| `GET /api/v1/admin/billing/subscriptions/{tenantId}/history` | 订阅历史（SubscriptionHistory 直查） |
| `POST .../cancel` | 取消自动续费（SubscriptionService::cancel，到期降级 free） |
| `POST .../resume` | 恢复自动续费（cancel 逆操作 + renew 历史记录） |
| `POST .../change-plan` | 手动变更套餐（SubscriptionService::changePlan，含按比例退补计算） |

派生状态 `sub_status`：`trial`（试用中）/ `active`（订阅中）/ `pending_cancel`（到期不续费）/ `expired`（已过期）/ `free`（免费版）。

### 2.2 B2 订单运营（注册在 Billing，URL 保持 /payments）

新控制器 `src/Modules/Billing/Http/Controllers/Admin/PaymentOrderAdminController.php`：

| 端点 | 说明 |
|---|---|
| `GET /api/v1/admin/payments/orders` | 跨租户订单列表（tenant_id/status/driver 过滤） |
| `GET /api/v1/admin/payments/orders/{orderId}` | 详情 |
| `POST .../mark-paid` | 手动补单：仅 pending 可操作 → paid + paid_at + transaction_id（留空自动生成 `MANUAL-` 前缀）+ extra.manual_paid/manual_note |
| `POST .../close` | 关单：仅 pending → cancelled + extra.manual_closed/close_note |

补单仅记账改状态；履约联动由项目层按 `extra` 中业务引用自行接线。

### 2.3 过程中发现并修复的两个存量缺陷

1. **Payment 模块 default_enabled=false**：`src/Modules/Payment` 在 composer.json extra 声明默认禁用，其 `Routes/admin.php` 从不加载 → 现有 PaymentOrders 页的 `/api/v1/admin/payments/orders` 在生产实际 404（页面长期空列表）。修复：订单 admin 路由全部迁至 Billing 模块（PaymentOrder 模型本就在 Billing），URL 路径不变。
2. **TenantScope 拦截 admin 查询**：`PaymentOrder`/`SubscriptionHistory` 带 `BelongsToTenant`，admin 上下文无 TenantContext 时 fail-closed（WHERE 1=0）→ 全部 `withoutGlobalScope(TenantScope::class)` 直查（沿用 P4 模式）。
3. **admin/console 域名隔离缺口（生产 nginx）**：四域名共用一个 server 块，`admin.neihang.com/console/`、`/app/` 静态 SPA 可访问（API 层已有 `RejectPlatformDomain` 403 防线，登录不通，但入口暴露）。修复：`/etc/nginx/conf.d/neihang.conf` 加两个域名条件 location——平台域名（admin/www）禁 `/console`、`/app`（403），租户域名（console/app）禁 `/admin`（403），原配置备份于服务器 `/root/neihang.conf.bak.*`。验证矩阵：admin×{console,app}=403、console×admin=403、各自本域 SPA=200。

## 三、前端

- `Subscriptions.vue`（Billing element-plus，新建）：四汇总卡 + 关键字/套餐/状态过滤 + 表格（租户/套餐/状态标签/到期/试用/续费开关）+ 变更套餐弹窗 + 取消/恢复续费 + 历史抽屉
- `PaymentOrders.vue`（Payment element-plus，扩展）：pending 行新增「补单」（弹窗填交易号+备注）/「关单」（prompt 填原因）按钮；详情弹窗补交易号与 extra JSON 展示；修复详情接口误用 order_no 作路径参数的存量 bug
- 菜单：`Subscriptions` 登记进 `module-loader.ts` knownPaths（路径 `subscriptions`），两版 AdminLayout「系统管理」分组加「订阅总览」（紧跟订阅计划）

## 四、测试

`tests/AdminSubscriptionPaymentTest.php` 10 用例（platform Operator token 认证）：

1. 列表+汇总+派生状态（active/expired/trial）✓
2. 关键字过滤 ✓
3. 取消续费（auto_renew=false + cancel 历史）✓
4. 恢复续费（auto_renew=true + renew 历史）✓
5. 变更套餐（plan 更新 + 到期顺延）✓
6. 订阅历史端点 ✓
7. 补单（status/paid_at/transaction_id/extra）✓
8. 补单拒绝非 pending（422）✓
9. 关单 ✓
10. 关单拒绝已支付（422）✓

回归：Subscription/TenantPayment/SupplySettlement/PaymentSecurity/Schema/TenantCredit 共 64 用例全绿；SPA `npm run build:element-plus` 4.24s 构建成功。

## 五、部署记录

- 框架 commit：`3294d32`（主体）
- 部署与验证结果：待补

## 六、遗留（Phase 5.5+）

- C1-C3 AI credit 消耗透视（Phase 5.5）
- B3 退款操作面 / B4 营收报表 / B5 催收处置（Phase 6）
- 补单履约联动：项目层按 extra 业务引用接线
- 分销监管与提现审批：按用户指示暂停
