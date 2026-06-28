All 28 tests pass. Now I have full context to write the review.

---

## Architecture

TrialService 职责清晰：初始化/延长/提醒/到期处理，与 SubscriptionService/CouponService 平行。静态方法（startTrial/extendTrial/isInTrial/getTrialStatus）用于同步操作，实例方法（processExpiringTrials/processExpiredTrials）用于定时批处理，区分合理。依赖链全部指向已有服务（SubscriptionService、NotificationService、SubscriptionHistory、FinancialRecord），无新增外部依赖。Tenant 模型变更最小化——仅新增 `trial_extended`（boolean）和 `trial_notification_sent_at`（datetime）到 fillable + casts。翻译文件结构一致，coupon/trial 分组清晰。

**评价：良好。** 模块边界清晰，符合既有架构风格。

## Code Quality

- PHPDoc 完整，`@param` / `@return` 均有标注，`getTrialStatus` 使用 PHPStan 形状注解 `array{in_trial: bool, ...}`。
- 命名一致：`startTrial`、`extendTrial`、`isInTrial`、`getTrialStatus`、`processExpiringTrials`、`processExpiredTrials`。
- `convertToPaidSubscription` 中 `$financialRecord` 变量在事务外声明、事务内赋值，catch 中更新状态——模式正确但略显技巧性，可读性尚可。
- `processExpiringTrials` 的三个阈值循环写法简洁，`chunk(100)` 内逐条 save 略显低效但可接受。
- 静态/实例方法混合使用——`startTrial` 等是 static，`processExpiredTrials` 等是 instance。测试中 `$this->service = new TrialService()` 只用于后者，前者直接 `TrialService::startTrial()`。风格统一性一般，但与项目既有模式一致。

**评价：良好。** 代码可读，注释到位，无明显重复。

## Type Safety

- `SubscriptionHistory::record()` 第一个参数声明为 `string $tenantId`，但 `$tenant->tenant_id` 是 `int`。PHP 隐式转换不会报错，但严格模式下会触发 TypeError。这是 **既有债务**（SubscriptionService 也这样调用），非本次引入。
- `$plan->price_monthly` 是 `int`（cast），传给 `FinancialRecord.amount` 也是 `int` cast——一致。
- `getTrialStatus` 返回值 PHPDoc 与实际返回完全匹配。
- `processExpiringTrials` 中 `$days === 0` 时 `$start` 和 `$end` 使用 `now()->startOfDay()` / `endOfDay()`，逻辑正确。

**评价：良好。** 仅有既有的 int/string 不一致，本次代码无新增类型问题。

## Security

- 全程 Eloquent ORM，无原生 SQL，无注入风险。
- 服务层无 HTML 输出，XSS 不适用。
- `startTrial` / `extendTrial` 无权限检查——但这是 Service 层，授权应在 Controller/Middleware 处理，合理。
- 订单号格式 `TRIAL-{date}-{tenant_id}` 可预测，但仅作 metadata 标识，无安全影响。
- `url('/console/subscription')` 硬编码路径——如使用子目录部署可能不一致，但非安全问题。

**评价：通过。** 无 OWASP Top 10 风险。

## Performance

- `processExpiringTrials` 对三个阈值各发一次 DB 查询——理论上可合并为一条 OR 查询，但阈值固定为 3 个、数据量小，影响可忽略。
- `chunk(100)` 防止内存膨胀，正确。
- `processExpiredTrials` 中每个租户的转换/暂停各自在独立事务中，粒度正确——单个失败不影响其他租户。
- `processExpiringTrials` 中每个租户单独 `save()` 更新 `trial_notification_sent_at`——N 次写入。可考虑批量 update，但考虑到 chunk=100 且是定时任务，可接受。

**评价：良好。** 无 N+1 查询风险，批处理内存安全。

## Potential Bugs

1. **`extendTrial` 允许延长已过期的试用**（`TrialService.php:145`）：当 `isInTrial($tenant)` 为 false 时，`$base = now()`，即从今天重新开始计算延长天数。`startTrial` 会在试用期内抛异常，但 `extendTrial` 只检查 `!$tenant->trial_ends_at`（line 141），已过期但 `trial_ends_at` 非 null 的情况会通过。这可能是**有意设计**（管理员给过期租户二次机会），但缺乏文档说明。

2. **`convertToPaidSubscription` 中 `FinancialRecord` 的 `status` 初始为 `pending`**（line 270）：事务成功后 status 仍为 `pending`，没有更新为 `completed` 或 `paid`。如果后续没有其他流程更新此状态，该记录会永远停留在 `pending`。这取决于是否有独立的支付确认流程——如果没有，则是 bug。

3. **`processExpiringTrials` 中通知发送失败会中断 chunk 处理**：如果 `NotificationService::sendToTenantAdmins` 抛出异常，当前 chunk 中剩余租户不会被处理，且已处理租户的 `trial_notification_sent_at` 已更新（在 save 之后才可能抛异常——实际上 save 在通知之后，所以通知失败时 save 未执行，下次会重试）。重新确认：line 195 发通知 → line 203 save。如果 line 195 抛异常，line 203 不执行，该租户下次会重试——**这是正确的 fail-safe 行为**。但同一 chunk 中前面已处理的租户（通知+save 成功）不受影响，后面的被跳过——下次 cron 会补上。可接受。

4. **翻译 key `trial_plan_not_found` 定义但未使用**：TrialService 中没有任何代码引用 `trans('subscription.trial_plan_not_found')`。`convertToPaidSubscription` 在 `$plan` 为 null 或 free 时直接调用 `suspendOnTrialExpiry`，没有使用该翻译。这不是 bug，但是死代码（翻译 key 冗余）。

**评价：有两个需关注点（#1 和 #2），但不构成阻塞。**

---

## Verdict

**PASS**

【建议改进】（非阻塞）：

1. **`convertToPaidSubscription` 中 `FinancialRecord` 状态未终态化**（`TrialService.php:270`）：事务成功后 `status` 仍为 `pending`。如果存在后续支付确认流程则无碍；如果 trial conversion 是免支付的自动操作，应在事务内将 status 设为 `completed`，否则会产生悬空 pending 记录。建议确认业务流程后补充状态更新。

2. **`extendTrial` 对已过期试用的行为缺乏文档**（`TrialService.php:145`）：管理员延长已过期试用期是从 `now()` 重新开始计算，这与"延长"语义略有偏差。建议在 PHPDoc 中明确说明此行为，或在方法内增加 `isInTrial` 检查并抛出明确异常。

3. **翻译 key `trial_plan_not_found` 未被引用**：属于预留 key 还是遗漏？如果是预留可保留；如果是遗漏应在 `convertToPaidSubscription` 的 plan-not-found 分支中使用。
