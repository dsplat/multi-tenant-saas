## Architecture
三个服务职责清晰、边界合理。`UsageService` 处理用量计量与超额判定，`DunningService` 处理催收与暂停，`PlanChangeService` 处理计划变更与按比例计费。共享的计划解析逻辑通过 `ResolvesPlan` trait 复用，避免了重复代码。依赖方向正确：服务层 → 模型层，无循环依赖。`DunningService` 将通知调用放在事务外（`suspendTenant` L183），设计合理——通知失败不应回滚暂停操作。

## Code Quality
命名规范，方法语义清晰，PHPDoc 完整且包含结构化返回类型标注。代码组织合理，复杂逻辑拆分为私有方法（`evaluateFlatRules`、`evaluateTieredRules`、`calculateTieredPrice`）。`DunningService` 的配置读取统一提取为 `getMaxRetries()` / `getRetryIntervals()` 等辅助方法，便于测试和配置覆盖。

`ResolvesPlan` trait 的 `use` 语句缺少分号（`UsageService.php:29`、`PlanChangeService.php:28`），虽然 PHP 允许但不符合 PSR-12 风格一致性。

## Type Safety
类型标注整体完整。`getChangeHistory()` 正确使用 `Illuminate\Database\Eloquent\Collection` 而非 `Illuminate\Support\Collection`。`checkOverage` 返回类型 `array{allowed: bool, overage: float, price: float}` 与实际一致。`UsageRecord.value` cast 为 `decimal:4` 但服务层用 `float` 处理——Laravel 会自动转换，无实际问题。

`SubscriptionPlan.price_monthly` cast 为 `integer`，proration 计算中做浮点除法（`PlanChangeService.php:202-203`），对合理金额范围无精度问题，但若计划价格极大（>2^53 分）理论上会丢精度。

## Security
- SQL 注入：所有查询使用 Eloquent Builder 参数化方法，安全。
- 租户隔离：所有方法通过显式 `tenant_id` 参数隔离，`BelongsToTenant` trait 在模型层提供额外保护。
- 敏感操作：`changePlan` 正确校验 `is_active` 和 `tenant->status`，`suspendTenant` 使用事务+审计日志。
- 无 XSS 风险（纯服务层，无输出渲染）。

## Performance
- `ResolvesPlan::resolveCurrentPlan()` 最多 3 次 DB 查询（tenant → plan_id → plan_name → free），在 `checkOverage` 中每次调用都会触发。高频场景建议缓存当前计划。
- `processFailedPayment` 正确使用 `lockForUpdate()` 防止并发重试竞争。
- `calculateProration` 内部 3 次查询（tenant、newPlan、oldPlan），可接受。
- 无 N+1 问题。

## Potential Bugs

1. **`detectFreeLimit` 对非连续免费阶梯的误判**（`UsageService.php:289-303`）：假设免费阶梯一定在前、付费阶梯一定在后。若 tiers 为 `[{"up_to": 1000, "price": 0.01}, {"up_to": 5000, "price": 0}, {"up_to": null, "price": 0.05}]`，第一个 tier `price > 0` 就 break 了，返回 `null`，丢失了 5000 的免费额度。虽然 `evaluateTieredRules` 会先排序 tiers，但排序按 `up_to` 而非 `price`，不保证免费 tier 在前。

2. **`sendExpiryReminder` 的提醒标记在通知发送前就写入**（`DunningService.php:138-140`）：若 `NotificationService::notifySubscriptionExpiring` 抛异常，settings 已保存但通知未发出，且无重试机制。应将 `save()` 放在通知调用之后，或用 try-catch 保护。

3. **`changePlan` 缺少幂等保护**（`PlanChangeService.php:90`）：若两次调用参数相同（同一 tenant→同一 newPlan），第一次成功后 `oldPlan` 已变为 `newPlan`，第二次会命中 `plan_unchanged` 检查抛异常——这是正确的。但如果第一次事务提交后、`subscription_plan_id` 更新前有并发调用，可能绕过检查。低频操作，风险可接受。

4. **`computeProrationAmount` 对已过期订阅返回 0 但 `changePlan` 仍继续执行**（`PlanChangeService.php:182-183`）：已过期的订阅变更计划不会有任何 proration 提示，用户可能不知情。建议在 `changePlan` 中对已过期订阅单独处理或发出警告。

## Verdict
**PASS**

【建议改进】
1. `detectFreeLimit` 建议改为扫描所有 `price === 0` 的 tier 取最大 `up_to`，而非遇到 `price > 0` 就 break，以应对非标准 tier 排列。
2. `sendExpiryReminder` 中 `$tenant->save()` 应移到 `notifySubscriptionExpiring` 成功之后，避免标记已发送但通知失败的不一致状态。
3. `ResolvesPlan` trait 的 `use` 语句补充分号，保持 PSR-12 一致性。
4. `ResolvesPlan::resolveCurrentPlan` 在高频路径（如 `checkOverage`）中可考虑短期缓存（如 request-level memo），减少重复查询。
