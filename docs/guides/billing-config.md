# 计费配置指南

**最后更新**: 2026-06-29

---

## 1. 计费体系总览

框架的计费由三个层面组成：

```
┌──────────────────────────────────────────────┐
│  订阅（Subscription）  — 按月/年固定费用       │
│  plans: free / basic / pro / enterprise       │
└──────────────────┬───────────────────────────┘
                   │
┌──────────────────▼───────────────────────────┐
│  积分/配额（Credit & Quota）— 预付费消耗       │
│  credit_accounts / credit_transactions        │
└──────────────────┬───────────────────────────┘
                   │
┌──────────────────▼───────────────────────────┐
│  AI 用量计费（AiUsage）— 按 token/张/秒计费    │
│  ai_usage_quotas（月度配额 + 预算 + 超额策略） │
└──────────────────┬───────────────────────────┘
                   │
┌──────────────────▼───────────────────────────┐
│  成本核算（CostService）— 平台损益分析         │
│  cost_allocations（基础设施/AI/第三方分摊）    │
└──────────────────────────────────────────────┘
```

---

## 2. 订阅计划配置

### 2.1 订阅计划

订阅计划由 `SubscriptionPlan` 维护，种子数据提供 4 档：

| 计划 | 适用 | limits 示例 |
|------|------|-------------|
| `free` | 试用 | 成员 5、存储 1GB |
| `basic` | 小团队 | 成员 20、存储 10GB |
| `pro` | 成长型 | 成员 100、存储 100GB |
| `enterprise` | 大客户 | limits 为 null（不限） |

### 2.2 管理计划

```php
use App\Http\Controllers\Api\SubscriptionController;
// 需 rbac.permission:subscription.manage

// 通过 API
POST   /api/v1/subscription/plans
PUT    /api/v1/subscription/plans/{planId}
DELETE /api/v1/subscription/plans/{planId}
```

### 2.3 订阅/变更/取消

```php
POST /api/v1/tenants/{tenantId}/subscription/subscribe   // 订阅
POST /api/v1/tenants/{tenantId}/subscription/change      // 升降级
POST /api/v1/tenants/{tenantId}/subscription/cancel      // 取消
GET  /api/v1/tenants/{tenantId}/subscription             // 当前订阅
GET  /api/v1/tenants/{tenantId}/subscription/history     // 历史
```

### 2.4 试用期

```env
# .env
TRIAL_DAYS=14
```

租户开通时自动设置 `trial_ends_at`，到期前触发 `SubscriptionExpiring` 通知。

---

## 3. 积分与配额

### 3.1 租户积分账户

```php
use MultiTenantSaas\Services\TenantCreditService;

$credit = app(TenantCreditService::class);

// 充值
$credit->recharge($tenantId, 1000.00, 'admin', '季度充值');

// 消耗（自动扣减并记录）
$credit->consume($tenantId, 5.00, 'ai.text', ['model' => 'gpt-4o-mini']);

// 余额
$account = $credit->getAccount($tenantId);
// $account->balance  $account->total_recharged  $account->total_consumed

// 退款
$credit->refund($tenantId, 5.00, 'ai.text.refund', [...]);
```

### 3.2 配额检查

```php
// 辅助函数
check_quota('customers', 1);     // 检查是否还能新增 1 个客户
check_quota('storage', 1024);    // 检查存储配额
```

### 3.3 积分过期

`credit_accounts.expires_at` / `expired_at` + `credit_transactions.expires_at` / `expired`，由 `ProcessCreditExpiry` 定时任务处理过期积分。

---

## 4. AI 计费配置

### 4.1 租户级配置

```php
use MultiTenantSaas\Services\AiConfigService;

$cfg = app(AiConfigService::class);

// 月度预算（超出按策略处理）
$cfg->setMonthlyBudgetLimit(500.00);

// 超额策略：block（拒绝）/ warn（告警放行）/ allow（放行计费）
$cfg->setOverageAction('block');
```

### 4.2 用量记录与配额

```php
use MultiTenantSaas\Services\AiUsageService;

$usage = app(AiUsageService::class);

// 记录用量（通常由 AiTextService/AiImageService/AiVideoService 内部调用）
$usage->recordTextUsage('gpt-4o-mini', $inputTokens, $outputTokens);
$usage->recordImageUsage('dall-e-3', $count, $size);
$usage->recordVideoUsage('gen-3', $durationSeconds, $resolution);

// 检查（不足抛 InsufficientCreditsException / QuotaExceededException）
$usage->checkQuota('text');
$usage->checkBudget();

// 报表
$usage->getUsageSummary();      // 汇总
$usage->getUsageByCategory();   // 按 text/image/video
$usage->getUsageByModel();      // 按模型
```

### 4.3 计费周期与告警

```env
# config/ai.php -> quota
AI_QUOTA_PERIOD=monthly            # 计费周期（仅 monthly）
AI_QUOTA_WARN_THRESHOLD=0.8        # 用量达 80% 触发告警
AI_USAGE_RECORDS_ENABLED=true      # 同步写入 usage_records 表
```

### 4.4 默认值

```env
# config/ai.php -> tenant
AI_TENANT_TEXT_ENABLED=true
AI_TENANT_IMAGE_ENABLED=true
AI_TENANT_VIDEO_ENABLED=true
AI_TENANT_MONTHLY_BUDGET=0         # 0 = 不限预算
AI_TENANT_OVERAGE_ACTION=block
```

---

## 5. 支付配置

### 5.1 多网关

| 驱动 | 包 | 配置 |
|------|----|------|
| `wechat` | yansongda/pay | `config/pay.php` |
| `alipay` | yansongda/pay | `config/pay.php` |
| `paypal` | 独立 Service | 租户级配置 |
| `stripe` | 独立 Service | 租户级配置 |
| `unionpay` | 独立 Service | 租户级配置 |

### 5.2 租户级支付配置

```php
// 通过 API（需 payment.view / payment.create 权限）
GET /api/v1/tenants/{tenantId}/payment/config
PUT /api/v1/tenants/{tenantId}/payment/{driver}

// 下单 / 退款
POST /api/v1/tenants/{tenantId}/payment-orders
POST /api/v1/tenants/{tenantId}/payment-orders/refund
GET  /api/v1/tenants/{tenantId}/payment-orders/refund-status
```

### 5.3 支付安全

- 支付密码：`user_payment_passwords` 表，下单/退款需校验
- 支付日志：`payment_logs` 表，敏感字段脱敏
- 回调验签：支付回调带 `tenant_id` 参数定位租户配置后验签

---

## 6. 发票与税务

### 6.1 发票

```php
use MultiTenantSaas\Services\InvoiceService;

$invoice = app(InvoiceService::class);
// 通过 API 或服务层开具/查询发票（invoices / invoice_items 表）
```

### 6.2 税率与优惠券

```php
use MultiTenantSaas\Services\TaxService;
use MultiTenantSaas\Services\CouponService;

app(TaxService::class);     // tax_rates 表，按地区/类型配置税率
app(CouponService::class);  // coupons / coupon_usages 表，优惠码核销
```

---

## 7. 成本核算（平台侧）

`CostService` 用于平台运营方核算成本与损益，非租户计费：

```php
use MultiTenantSaas\Services\CostService;

$cost = app(CostService::class);

// 分摊基础设施成本
$cost->allocateInfrastructureCost($period, $amount, $driver);

// 聚合 AI 成本（按租户或全平台）
$cost->allocateAiCost($period, $tenantId);

// 第三方成本分摊
$cost->allocateThirdPartyCost($period, $provider, $amount);

// 损益
$pl = $cost->getProfitLoss($period, $tenantId);
// ['revenue' => ..., 'cost' => ..., 'profit' => ...]

// 趋势预测
$trend = $cost->forecastCostTrend(3, $tenantId);

// 月度报表
$report = $cost->getMonthlyReport($period, $tenantId);
```

结果持久化到 `cost_allocations` 表。

---

## 8. 推荐计费模型

| 场景 | 推荐组合 |
|------|----------|
| SaaS 标准订阅 | 订阅计划 + 积分（超额按量） |
| AI 应用 | 订阅计划 + AI 用量计费（月度预算 + block/warn 策略） |
| 按量付费 | 纯积分消耗（充值 → 消耗） |
| 企业定制 | enterprise 计划（limits=null）+ 成本核算对账 |

---

**文档版本**: v1.0.0
