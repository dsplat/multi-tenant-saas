Background agent completed. My review above already covers the most critical issues, but the deep dive into all 16 new service files revealed additional findings I should incorporate. Let me provide an updated supplement:

---

## Supplementary Findings (from deep review of all 16 new service files)

### Additional Critical Issues

**5. 【必须修复】StripeService 使用错误的认证方式**（`src/Services/StripeService.php:79,105,141`）：`Http::withBasicAuth($secretKey, '')` — Stripe API 要求 Bearer Token 认证，不是 Basic Auth。所有 Stripe API 调用（`createCheckoutSession`、`createPaymentIntent`、`refund`）都会因认证失败而报错。**修复**：改为 `Http::withToken($secretKey)`。

**6. 【必须修复】UnionPayService 签名验证是空实现**（`src/Services/UnionPayService.php:243`）：`verifySignature()` 直接返回 `true`，`handleNotify()` 不做任何签名验证就接受通知。部署后任何人可伪造银联异步通知篡改订单状态。**修复**：实现真实的银联证书验签逻辑，或至少在未实现时抛出异常拒绝处理。

### Additional High-Priority Issues

7. **`orWhereNull` 逻辑 bug 出现在 3 个服务中**：`AlertService::listRules()`、`RateLimitService::listRules()`、`PluginService::findPlugin()` 都使用了 `->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))->orWhereNull('tenant_id')` 模式。`orWhereNull` 在 `when` 闭包外部，导致它始终生效，破坏了租户隔离过滤。**修复**：将 `orWhereNull` 移入 `when` 闭包内，或使用 `->where(function($q) { $q->where(...)->orWhereNull(...); })` 包裹。

8. **QueueService 硬依赖 Horizon 类**（`src/Services/QueueService.php:8-10`）：直接 `use Laravel\Horizon\JobRepository` 等 import，如果 Horizon 未安装会导致 autoload 失败。`isHorizonAvailable()` 运行时检查无法挽救编译期的 import 错误。**修复**：移除顶部 import，在运行时通过 `class_exists()` 检查后动态解析。

9. **ExportService 跨租户下载绕过**（`src/Services/ExportService.php:195`）：`downloadTaskFile` 仅在 `$tenantId` 和 `$task->tenant_id` 都非空时检查租户归属。如果 `TenantContext::getId()` 返回 null，跨租户检查被完全跳过。**修复**：当无租户上下文时拒绝下载，或强制要求认证。

10. **PerformanceService::metricCacheKey 时间窗口计算错误**（`src/Services/PerformanceService.php:214`）：`$window = (int) floor(time() / 60) - (time() % $windowMinutes)` — 先除以 60 再取模 `$windowMinutes`，产生不一致的时间桶边界，导致聚合数据混乱。**修复**：改为 `(int) floor(time() / ($windowMinutes * 60)) * ($windowMinutes * 60)`。

### Updated Verdict

鉴于新发现的 StripeService 认证 bug（#5）和 UnionPayService 签名验证空实现（#6），**FAIL 判定不变**，必须修复的问题从 4 个增加到 **10 个**：

| # | 严重性 | 问题 | 文件 |
|---|--------|------|------|
| 1 | Critical | Stripe Webhook 签名验证可绕过 | StripeService.php:201 |
| 2 | Critical | PayPal Webhook 无签名验证 | PayPalService.php:199 |
| 3 | Critical | StripeService 使用 Basic Auth 而非 Bearer Token | StripeService.php:79,105,141 |
| 4 | Critical | UnionPayService 签名验证是空实现 | UnionPayService.php:243 |
| 5 | Critical | 23 个 payment.* 翻译键 + 20 个 common.* 翻译键缺失 | lang/en, zh_CN |
| 6 | High | OAuth state 校验可被绕过（$state 为 null 时跳过） | SocialiteService.php:249 |
| 7 | High | `orWhereNull` 逻辑 bug 破坏租户隔离 | AlertService, RateLimitService, PluginService |
| 8 | High | QueueService 硬依赖 Horizon import | QueueService.php:8-10 |
| 9 | Medium | ExportService 跨租户下载绕过 | ExportService.php:195 |
| 10 | Medium | PerformanceService 时间窗口计算错误 | PerformanceService.php:214 |
