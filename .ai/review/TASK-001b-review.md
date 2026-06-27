Now I have the complete picture. Let me deliver the review.

---

## Architecture

**评估：⚠️ 严重范围越界**

TASK-001b 明确规定"只允许修改 6 个文件"，但 diff 实际修改了 **20+ 文件**，包括：

- `config/pay.php`、`config/socialite.php`（配置扩展）
- `src/Models/OauthAccount.php`、`src/Models/PaymentOrder.php`（Model 层修改——**明确禁止**）
- `src/Services/PayService.php`、`src/Services/RefundService.php`（不在范围内）
- `lang/en/common.php`、`lang/zh_CN/common.php` 等 4 个语言文件
- `CHANGELOG.md`、`docs/README.md`、`TenancyServiceProvider.php`、`tests/TestCase.php`

`PaymentOrder` 新增 `BelongsToTenant` trait 是一个**行为变更**——它会自动注入 `tenant_id` scope 到所有查询，可能影响已有代码路径（如回调处理、跨租户对账报表）。这类变更应由独立 task 跟踪并做回归测试。

**核心修复（4 项验收标准）在 diff 中的覆盖情况：**

| 验收标准 | diff 中是否包含 | 实际文件状态 |
|----------|----------------|-------------|
| SocialiteService state null 拒绝 | ✅ 包含 | 文件未应用此 diff |
| AlertService/RateLimitService/PluginService orWhereNull 闭包 | ❌ 不在 diff 中 | **已修复**（在更早的 commit） |
| QueueService 移除 Horizon import | ❌ 不在 diff 中 | **已修复**（在更早的 commit） |
| ExportService 用户级权限检查 | ❌ 不在 diff 中 | **已修复**（在更早的 commit） |

4 项验收标准中只有 1 项出现在 diff 中，其余 3 项的修复是在之前的 commit 中完成的。diff 混合了安全修复与功能扩展（OAuth 14 种提供商、PayPal/Stripe/银联等），违反了 task 的 scope 约束。

---

## Code Quality

**评估：⚠️ 中等**

**正面：**
- SocialiteService 新增的 PKCE/State/Token 刷新/撤销逻辑结构清晰，方法职责分明
- PayService 新增 `validateConfig()` / `testConnection()` 提供了配置完整性校验能力
- RefundService 退款记录从单次改为数组结构（`$extra['refunds'][]`），支持多次部分退款
- `bccomp` / `number_format` 精确比较替代了 `floatval` 直接比较，修复了浮点精度问题
- 退款号从 `rand()` 改为 `Str::random()`，更安全

**负面：**
- PayService 的 `getConfig()` 使用链式 `if ($driver === 'xxx')` 而非 `match` 或数组映射，随着驱动增多会持续膨胀
- SocialiteService 的 `getSupportedProviders()` 硬编码了 14 个提供商定义，应考虑从配置或注册表加载
- RefundService 的 `queryRefundStatus()` 使用 `end($refunds)` 取最新退款，但 `end()` 会移动数组内部指针，且如果 `$extra` 是 object（从 JSON 反序列化），行为不可预期
- `wechatMiniapp()` 调用 `->mp()` 但注释说"与 jsapi 同接口"——应确认 yansongda/pay 的 `mp()` 方法确实对应小程序支付，而非公众平台

---

## Type Safety

**评估：⚠️ 中等**

- `RefundService::wechatRefund()` 和 `alipayRefund()` 参数类型从 `$pay` 改为 `Pay $pay`，这是正确的类型收紧
- `PayService::exportPaymentConfig()` 返回类型注解 `@return array<string, array<string, string>>` 完整
- `SocialiteService::generatePkce()` 返回类型 `@return array{code_verifier: string, code_challenge: string}` 完整
- **问题：** `RefundService::queryRefundStatus()` 中 `$extra = $order->extra ?? []`——如果 `$order->extra` 从数据库读出是 JSON string（而非 decoded array），`$extra['refunds']` 会失败。需要确认 PaymentOrder 模型是否有 `casts` 将 `extra` 字段设为 `array`
- **问题：** `SocialiteService::refreshToken()` 中 `$oauthAccount->tenant_id === null` 严格比较——但数据库字段可能返回 `0` 或字符串 `"0"`，取决于驱动

---

## Security

**评估：🔴 存在 High 问题**

**[HIGH-S1] SocialiteService state 校验：异常而非 403**
Acceptance Criteria 明确要求"return 或 abort(403)"，但实现是 `throw new \RuntimeException()`。如果调用方没有 catch 这个异常，它会变成 500 Internal Server Error，泄露堆栈信息（取决于 debug 设置）。正确的做法是 abort(403) 或抛出专门的 HTTP 异常。

**[HIGH-S2] ExportService 租户检查可被绕过**
`ExportService.php:195` 的三重条件检查：
```php
if ($task->tenant_id && $tenantId && (int) $task->tenant_id !== (int) $tenantId) {
```
当 `$task->tenant_id` 为 null（任务创建时未设租户）或 `TenantContext::getId()` 为 null（中间件未设置上下文）时，检查被静默跳过。应 fail-closed：如果任务有 `tenant_id` 但当前无租户上下文，应拒绝访问。

**[MEDIUM-S3] OAuth token 存储**
SocialiteService `refreshToken()` 将 `access_token` 和 `refresh_token` 用 `encrypt()`/`decrypt()` 存储——如果 APP_KEY 泄露，所有租户的 token 都会被暴露。对于多租户 SaaS，应考虑租户级密钥或使用 Vault 等密钥管理服务。

**[LOW-S4] PayService 新增驱动的 webhook 验签**
PayPal/Stripe/UnionPay 的 webhook 验签逻辑不在本 diff 中（由独立 Service 实现），但 config 新增了 `webhook_id` / `webhook_secret` 配置。需确保这些 webhook endpoint 实现了正确的签名验证，否则存在支付回调伪造风险。

---

## Performance

**评估：✅ 良好**

- SocialiteService `refreshToken()` 和 `revokeToken()` 使用 try-catch 包裹 HTTP 调用，失败时返回 false 而非阻塞
- RefundService 退款记录从覆盖改为追加（`$extra['refunds'][]`），避免了读取-修改-写入的竞态问题
- PayService `exportPaymentConfig()` 遍历所有 5 个驱动——如果大部分未配置，会产生不必要的 TenantSetting 查询。可考虑先检查 `isConfigured()` 再读取
- SocialiteService `generateState()` 使用 `Cache::put()` 存储 state，TTL 300 秒——在高并发场景下，Redis 内存占用可控

---

## Potential Bugs

**评估：🔴 存在阻塞问题**

**[BUG-B1] SocialiteService `handleCallback` 签名不兼容**
diff 中 `handleCallback` 新增了 `?string $state = null` 参数，但当前文件中的签名为 `handleCallback(string $provider, int $tenantId)`。如果此 diff 被应用，所有调用 `handleCallback` 的 Controller 都需要更新——但 task 禁止修改路由或 Middleware。**这会导致运行时错误。**

**[BUG-B2] RefundService `queryRefundStatus` 兼容性**
从 `$extra['refund_no']` 改为 `$extra['refunds'][n]['refund_no']`——对于 task 修改前创建的旧退款记录（`extra` 中只有 `refund_no` 而无 `refunds` 数组），查询会返回 `has_refund = false`，用户会看到"无退款记录"。需要添加向后兼容逻辑。

**[BUG-B3] RefundService `mapRefundStatus` 默认返回 `'refunding'`**
对于未知的 gateway 状态（如新的支付网关返回未预期的状态码），默认返回 `'refunding'` 而非抛异常或记录告警。这可能导致订单永远处于 "refunding" 状态，需要人工介入。

**[BUG-B4] PayService `updatePaymentConfig` 前缀变更**
从 `{$prefix}_{$key}` 改为 `{$driver}_{$key}`——对于已存在的配置数据（如微信支付的 `wechat_app_id`），如果 `$driver` 值与之前的 `$prefix` 不同，会导致配置读取失败。需确认所有驱动的 `$driver` 值与已有数据的前缀一致。

---

## Verdict

**FAIL**

---

### 【必须修复】

1. **[HIGH-S1] SocialiteService state 校验应使用 `abort(403)` 而非 `throw new \RuntimeException()`**——Acceptance Criteria 明确要求 return 或 abort(403)，当前实现会导致 500 错误泄露堆栈。

2. **[HIGH-S2] ExportService 租户检查应 fail-closed**——`$task->tenant_id && $tenantId && ...` 三重条件在任一为 null 时跳过检查。应改为：如果任务有 `tenant_id` 且当前无租户上下文，拒绝访问；如果任务无 `tenant_id`，仅依赖用户级检查。

3. **[BUG-B1] `handleCallback` 签名变更是 breaking change**——新增 `$state` 参数后，所有调用方需要同步更新。但 task 禁止修改路由/Middleware。需确保调用方能传递 state 参数，或改为从 `Request` 中获取。

4. **[BUG-B2] RefundService `queryRefundStatus` 缺少向后兼容**——旧退款记录（`extra` 中无 `refunds` 数组）会返回错误结果。需添加 fallback：`$refundNo = $extra['refund_no'] ?? ($latestRefund['refund_no'] ?? null)`。

5. **[Scope Violation] diff 修改了禁止范围外的文件**——`PaymentOrder` model（新增 BelongsToTenant trait）、`PayService`、`RefundService`、config 文件、lang 文件等均不在 task scope 内。应拆分为独立 task 并单独 review。
