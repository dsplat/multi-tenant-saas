# TASK-007c: [Auto-split from TASK-007]


**目标:** 集成催款逻辑到 ProcessSubscriptions 命令，编写三个 Service 的测试，补充翻译 key

**只允许修改:**
- `app/Console/Commands/ProcessSubscriptions.php`（追加催款逻辑）
- `tests/DunningServiceTest.php`（新建）
- `tests/UsageServiceTest.php`（新建）
- `tests/PlanChangeServiceTest.php`（新建）
- `lang/zh_CN/subscription.php`（追加翻译 key）
- `lang/en/subscription.php`（追加翻译 key）

**具体工作:**

**ProcessSubscriptions.php 追加：**
- `use MultiTenantSaas\Services\DunningService` + `TrialService`
- 在现有逻辑后追加：遍历失败支付的租户 → `$dunning->processFailedPayment()` → 重试或暂停
- 追加 TrialService 调用（`processExpiringTrials` + `processExpiredTrials`）

**翻译 key 追加（每个 key 中英文各一条）：**
- 催款：`dunning_retry_scheduled`、`dunning_payment_failed`、`dunning_grace_period`、`dunning_suspended`、`dunning_expiry_reminder_7d`、`dunning_expiry_reminder_3d`、`dunning_expiry_reminder_1d`
- 用量：`usage_limit_exceeded`、`usage_overage_charged`、`usage_hard_limit_reached`
- 套餐变更：`plan_change_upgraded`、`plan_change_downgraded`、`plan_change_proration_charged`、`plan_change_proration_credited`

**测试要点：**
- `DunningServiceTest`：测试重试策略递增间隔、宽限期内不暂停、超过宽限期后暂停、事件记录
- `UsageServiceTest`：测试用量记录、按周期聚合、硬限制拒绝、软限制超额费、阶梯定价、与 RateLimitService 联动
- `PlanChangeServiceTest`：测试升级按比例补收、降级按比例退款、立即生效 vs 周期末生效、变更历史记录

**禁止:** 修改其他文件、新增依赖

**预估时间:** 2 小时

**依赖:** TASK-007b（需要 UsageService/DunningService/PlanChangeService 的完整实现）


## 状态
READY
