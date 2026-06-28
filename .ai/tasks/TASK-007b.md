# TASK-007b: [Auto-split from TASK-007]


**目标:** 实现 UsageService、DunningService、PlanChangeService 三个服务（纯服务代码，不含测试和翻译）

**只允许修改:**
- `src/Services/UsageService.php`（新建）
- `src/Services/DunningService.php`（新建）
- `src/Services/PlanChangeService.php`（新建）

**具体工作:**

**UsageService（全部 static 方法）：**
- `record(int $tenantId, string $metric, float $value, ?string $period = null): UsageRecord` — 记录用量，period 默认当前 YYYYMM
- `aggregate(int $tenantId, string $metric, string $period): array` — 聚合指定周期的总用量
- `query(int $tenantId, ?string $metric = null, ?string $periodFrom = null, ?string $periodTo = null): Collection` — 按条件查询
- `checkOverage(int $tenantId, string $metric, float $value): array` — 读取 SubscriptionPlan.metered_price JSON 阶梯规则，返回 `{allowed, overage, price}`；硬限制时 `allowed=false`；软限制+超额费时返回超额部分和费用；阶梯定价时按阶梯计算
- `enforceRateLimit(int $tenantId): int` — 读取 SubscriptionPlan.rate_limit_rpm，调用 `RateLimitService::dynamicLimit()` 返回该租户当前 RPM 上限（**注意：RateLimitService 是实例方法，需 new 实例调用，只读不修改**）

**DunningService（全部 static 方法）：**
- `processFailedPayment(int $tenantId): array` — 检查该租户失败支付记录，按重试策略（默认 3 次，间隔 1/3/7 天）决定是否重试，返回 `{action: 'retry'|'suspend'|'none', next_retry_at}`
- `sendExpiryReminder(int $tenantId): void` — 到期前 7/3/1 天发送提醒（调用 NotificationService）
- `suspendTenant(int $tenantId): void` — 超过宽限期后暂停租户（更新 `tenants.status = 'suspended'`），记录审计日志
- `getDunningStatus(int $tenantId): array` — 返回 `{retry_count, max_retries, grace_period_days, next_retry_at, status}`
- 宽限期默认 7 天，配置通过 `config('tenancy.dunning')` 读取

**PlanChangeService（全部 static 方法）：**
- `calculateProration(int $tenantId, int $newPlanId, string $effectiveTiming = 'immediate'): array` — 计算按比例差价：读取当前订阅剩余天数、当前计划价格、新计划价格，返回 `{proration_amount, direction: 'charge'|'credit', effective_at}`
- `changePlan(int $tenantId, int $newPlanId, string $effectiveTiming = 'immediate'): SubscriptionHistory` — 执行变更：记录到 subscription_histories，更新 tenant 的 subscription_plan_id；immediate 立即生效，period_end 下个周期生效
- `getChangeHistory(int $tenantId): Collection` — 查询该租户的变更历史

**禁止:** 修改其他文件、新增依赖

**预估时间:** 2 小时

**依赖:** TASK-007a（需要 UsageRecord 模型和 SubscriptionPlan 新字段）

---



## 状态
READY
