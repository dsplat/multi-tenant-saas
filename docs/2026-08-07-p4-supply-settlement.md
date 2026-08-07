# P4: 供货结算与预存体系（规避二清）

**会话时间**: 2026-08-07（P3 后续会话）  
**范围**: 框架 multi_tenant_saas → neihang.com 生产部署（admin.neihang.com）  
**状态**: ✅ 已完成并验证  
**前置**: [P3 商业后台建设](./2026-08-07-p3-commerce-admin.md)、[admin 功能缺口 Review](./2026-08-07-admin-gap-review.md)

---

## 背景与设计前提

**无支付牌照前提**（前次会话决策）：平台默认无支付牌照，不支持代收/二清。
租户面向自己用户的收款走租户自有商户号，平台不经手该资金流。

**供货结算模型**（本期落地）：

```
租户预存货款给平台（平台自营收款，合法）
        ↓ 平台记 supply_prepay 账户（预收账款负债）
平台划拨商品给租户（supply_grants 批次化，锁结算价）
        ↓
用户向租户购买（租户商户号收款，平台不经手）
        ↓ 租户侧收款确认后调平台结算 API
平台扣预存 + 库存下发（原子事务 + 幂等）
        ↓ 扣款时确认收入
失败/退款 → 补偿：回补预存 + 库存回 remaining
```

**财务口径**：预存余额 = 预收账款（负债），settle 扣款才确认收入；
保证金 = 其他应付款。报表必须区分「预存余额」与「已确认收入」。

---

## 实施清单

### S1/S2 账户与表结构 ✅

- `CreditAccount.account_type` 扩展两类账户（不新建表，流水复用 `credit_transactions`）：
  - `supply_prepay` 预存货款（不允许负余额）
  - `domain_deposit` 二级域名保证金（独立台账，不与预存混用）
- 迁移 [2026_08_07_000001_supply_settlement_accounts.php](../src/Modules/Billing/Database/migrations/2026_08_07_000001_supply_settlement_accounts.php)：
  - MySQL enum 扩展（account_type / transaction type 加 `release` / user_id 可空）
  - 补齐 `credit_accounts` 缺失的 `gift_balance / recharge_balance` 列（模型已引用、原表缺失，幂等 hasColumn 保护）
- `supply_grants` 库存化（[迁移](../src/Modules/Commerce/Database/migrations/2026_08_07_000001_supply_grant_inventory.php)）：
  `allocated_qty / remaining_qty / locked_qty`，一次划拨 = 一个批次；
  存量授权三字段 0 = 非库存型，行为向后兼容

### S3 结算服务 SupplySettlementService ✅

[SupplySettlementService.php](../src/Modules/Commerce/Services/SupplySettlementService.php)

| 方法 | 语义 |
|---|---|
| `prepayAccount / depositAccount` | get-or-create 租户级账户 |
| `rechargePrepay` | admin 人工记账充值（线下到账后） |
| `lockStock / unlockStock` | 用户下单锁库存（防超卖）/ 取消释放 |
| `settle` | 扣预存 + 核销锁定量；DB 事务 + lockForUpdate + 幂等键（related_type+related_id）；预存不足抛 DomainException 回滚，调用方拒绝订单 |
| `compensate` | 退还已结算货款 + 库存回补 remaining；幂等 |
| `lockDeposit / releaseDeposit / deductDeposit` | 保证金锁定 / 退还 / 违规扣除 |
| `warnIfLowBalance` | 结算后低于阈值（auto_recharge_threshold 或默认 ¥100）告警，24h 去抖，通知租户全体 Operator（CreditLowNotification） |

**要点**：
- admin/系统上下文无 TenantContext，全部流水读写走 `withoutGlobalScope(TenantScope)` 直查（fail-closed 规避）
- `settlement.supply_price` 以**元**存储（兼容历史口径），`supplyPriceFen()` 结算时换算分
- 免费划拨（supply_price=0）记 0 元 consume 流水保持幂等键一致

### S4 Admin 端点 ✅

[CommerceSupplyAdminController.php](../src/Modules/Commerce/Http/Controllers/Admin/CommerceSupplyAdminController.php)，
路由前缀 `/api/v1/admin/commerce`：

- `POST /supply-grants` 创建划拨批次（仅 supply 角色 SKU）
- `POST /supply-grants/{id}/adjust-qty` 追加/缩减额度（缩减不得超 remaining）
- `GET /prepay-accounts` 预存账户总览（含 summary 负债合计）
- `POST /prepay/recharge` 充值；`GET /prepay/transactions?tenant_id=` 流水
- `GET /deposits` 保证金列表；`POST /deposits/{lock|release|deduct}` 操作
- 全部 `ensureSuperAdmin` + AuditService 审计

### S5 Admin 页面 ✅

`src/Modules/Commerce/resources/admin/ui/element-plus/views/`：

- `PrepayAccounts.vue`：负债汇总卡 + 账户列表（余额 ≤¥100 标红）+ 充值弹窗 + 流水抽屉
- `Deposits.vue`：保证金锁定/退还/违规扣除（扣除强制填事由 + 二次确认）
- `SupplyGrants.vue` 扩展：余量/划拨列、锁定标签、发起划拨弹窗（SKU 下拉拉 supply SKU）、调额弹窗
- routes.ts 增 `prepay-accounts / deposits` 两路由 + meta.menu（商业运营分组）

---

## 测试

`tests/SupplySettlementServiceTest.php` 15 用例：账户幂等建户、锁库存/超量拒绝/停供拒绝/
非库存型拒绝、结算扣款、不足拒绝回滚、结算幂等、免费划拨 0 元流水、补偿回补+幂等、
保证金锁/退/扣、超退拒绝、预存与保证金台账隔离。

回归：CommerceSupplyGrantTest（9）、CommerceFulfillmentTest（8）、
Schema/、CapabilityBilling/TenantCredit（30）全绿。

---

## 部署记录

- 框架 commit：`c43f22c`（主体）→ `9a655b5`（文档）→ `f7e06a6`（预存页新建户入口）→ `235622c`（关联 FQCN 热修复），split 全部 ✓
- scrm-platform composer.lock 两次同步（`7417f7d` 主体 / `619ce61` 热修复），deploy.py incremental 均成功
- 迁移：deploy.py 未检测框架包内迁移，**服务器手动 `php artisan migrate --force`** 执行两条新迁移（均 <60ms）
- SPA：本地 build:element-plus 后 rsync public/admin/ 到生产
- 验证中发现并修复：`CreditAccount::tenant()/user()` 与 `CreditTransaction` 同方法引用未导入的 Tenant/User 类
  （PHP 解析到当前命名空间，`with('tenant')` 首次触发即 500；历史潜伏 bug，此前无记录未引爆）。
  热修复期间 `optimize:clear` 后必须重建 config/route/view 缓存，否则报 Class "view" not found

## 生产验证（超管浏览器端到端，全部 PASS，验证后数据已清理、密码已还原）

| 步骤 | 结果 |
|---|---|
| 商业运营菜单含预存货款/域名保证金 | ✅ |
| SKU 商品池创建 supply SKU（mall_supply/grant） | ✅ |
| 新租户充值 ¥50 → 列表余额/汇总卡/流水抽屉 | ✅ |
| 行内追加充值 ¥30 → 余额 ¥80 | ✅ |
| 发起划拨（数量 10 / 供货价 ¥5）→ 余量/划拨 10/10 | ✅ |
| 调额 +5 → 15/15 | ✅ |
| 保证金锁定 ¥10 → 退还（二次确认）→ ¥0 | ✅ |

## 遗留（Phase 5+）

- Domain 模块生命周期联动保证金（开通自动锁定 / 退出触发退还）—— 当前为独立台账 + 人工操作
- 项目层（scrm-platform）接线：用户下单 → `lockStock` → 收款确认 → `settle`，
  失败走 `compensate`；预存不足拒绝订单并提示充值
- 分销提现审批列表（人工审核线下打款，记账口径已在 settlement 保留）
