## Architecture

**严重偏离任务范围。** TASK-001a 明确限定只允许修改 `StripeService.php`、`PayPalService.php`、`UnionPayService.php` 三个文件，禁止修改其他文件和数据库 Schema。实际 diff 涉及 14+ 个文件（config、lang、models、services、provider、tests），新增了 9 个数据库迁移文件和多个新服务文件。核心的三个目标文件反而**完全不在 diff 中**。

从架构角度看，`PayService` 新增的 `validateConfig`/`testConnection`/`exportPaymentConfig` 方法将验证、测试、导出逻辑内聚在同一服务中，违反单一职责。`SocialiteService` 从 ~200 行膨胀到 ~500+ 行，承载了 PKCE、State 校验、Token 刷新/撤销、配置导入导出等过多职责，应拆分为独立的 `OAuthSecurityService` 或 `OAuthTokenService`。

`TenancyServiceProvider::registerCoreServices()` 将 15+ 个服务无条件注册为单例，即使未使用也会被实例化，与 TASK-001a 无关且增加了启动开销。

## Code Quality

- **命名：** `testConnection()` 实际只校验配置完整性，并不发起真实连接测试，命名具有误导性。`mapRefundStatus()` 使用 match 表达式是好的，但 `'closed' => 'refund_closed'` 这个内部状态未在其他地方定义或使用。
- **重复代码：** `PayService` 中 `getConfig()` 对 paypal/stripe/unionpay 三个驱动使用了大量 `if ($driver === 'xxx')` 分支，每增加一个驱动就要加一个完整 if 块。应改为配置映射数组。
- **退款理由校验：** `RefundService` 中 `$reasonRequired = true` 且 `$reasonMin = 5`，但 `refund()` 方法签名 `$reason = ''` 有默认值，调用方可以不传 reason 然后被静默拒绝——API 契约不清晰。
- **硬编码：** `SocialiteService::getTokenUrl()` 和 `getRevokeUrl()` 中硬编码了各提供商的 URL，未从配置读取，增加维护成本。

## Type Safety

- `RefundService::wechatRefund()` 参数类型从 `$pay` 改为 `Pay $pay`，但 `Pay` 是 `Yansongda\Pay\Pay` 的 facade 类，`createPayInstancePublic()` 返回的实际类型取决于 yansongda/pay 版本，可能存在类型不匹配。
- `SocialiteService::refreshToken()` 中 `$oauthAccount->tenant_id` 被 `(int)` 强转，但 `OauthAccount` 模型的 `tenant_id` 可能为 `null`（迁移中 `nullable()`），传入 `getOAuthConfig` 后会在 `TenantSetting::get` 中产生意外行为。
- `PayService::validateConfig()` 的 `match` 表达式 `default => []` 会使未知驱动直接跳过必填字段检查，返回 `['valid' => true]`——应抛出异常或返回 false。

## Security

**这是最严重的维度——TASK-001a 的核心目标（4 个 Critical 安全修复）完全未实现。**

在已有的代码变更中：

- ✅ `RefundService` 退款回调现在按 `tenant_id` 范围查询，修复了潜在的跨租户数据访问。
- ✅ `SocialiteService::validateState()` 实现了一次性 state 校验（读取后删除），防止 CSRF 重放。
- ✅ `SocialiteService` 邮箱为空时拒绝创建用户，防止空标识符账户。
- ⚠️ `SocialiteService::getRevokeUrl()` 中 `config('socialite.github.client_id', '')` 读取的是全局 config 而非租户级 TenantSetting，在多租户场景下会使用错误的 client_id 进行撤销请求。
- ⚠️ `SocialiteService::refreshToken()` 中 Azure AD 的 token URL 使用 `config('services.azure_ad.tenant', 'common')` 而非租户配置，同样的多租户问题。
- ⚠️ `SocialiteService::revokeToken()` 的 catch 块静默吞掉异常不记录日志，攻击者可利用撤销失败而不留痕迹。
- ⚠️ `PayService::exportPaymentConfig()` 将 `publishable_key`（Stripe）和 `client_id`（PayPal）未标记为敏感字段，这些虽然不是 secret_key 级别的敏感信息，但在某些场景下暴露仍有风险。
- ❌ **StripeService 仍使用 `withBasicAuth()` 而非 `withToken()`（验收标准 #1）**
- ❌ **StripeService webhook secret 未配置时仍跳过校验（验收标准 #2）**
- ❌ **PayPalService 缺少 `verifyWebhookSignature()`（验收标准 #3）**
- ❌ **UnionPayService 仍返回 true 而非真实 RSA 验签（验收标准 #4）**

## Performance

- `PayService::exportPaymentConfig()` 遍历 5 个驱动 × 每个驱动 6 次 `TenantSetting::get` = 30 次数据库查询。`SocialiteService::exportConfig()` 类似，14 个提供商 × N 次查询。虽然是管理界面操作，但仍应考虑批量查询优化。
- `RefundService::queryRefundStatus()` 中 `end($refunds)` 修改数组内部指针，虽然无功能影响，但使用 `array_key_last()` 或 `$refunds[count($refunds) - 1]` 更清晰。
- `TenancyServiceProvider` 无条件注册 15+ 个单例服务，即使应用完全不使用这些功能也会被实例化。

## Potential Bugs

1. **`SocialiteService::refreshToken()` 中 `tenant_id` 可能为 null：** `OauthAccount.tenant_id` 是 `nullable`，`(int) null` 变为 `0`，传入 `getOAuthConfig(0, $provider)` 会查询不存在的租户配置。
2. **`PayService::validateConfig()` 的 `default` 分支：** 未知驱动返回 `['valid' => true]`，应返回 `['valid' => false, 'message' => 'Unsupported driver']`。
3. **`RefundService::refund()` 的退款理由校验逻辑：** 当 `$reasonRequired = false` 且 `$reason` 为空字符串时，`mb_strlen('') > $reasonMax` 为 false，不会报错。但当 `$reasonRequired = true` 且 `$reasonMin = 5` 时，空字符串长度 0 < 5 会抛异常。这是正确的，但 `refund()` 的默认参数 `$reason = ''` 会让调用方困惑——如果 reason 必填，不应有默认值。
4. **`SocialiteService::revokeToken()` 中 GitHub 的撤销 API 需要 DELETE 方法而非 POST：** 代码使用 `Http::withToken($accessToken)->post($revokeUrl)`，但 GitHub 文档要求 `DELETE /applications/{client_id}/token`。
5. **`RefundService` 的退款数据结构从 `extra.refund_no` 改为 `extra.refunds[].refund_no`：** 存量数据迁移未处理，旧订单的 `queryRefundStatus()` 会找不到退款记录。

## Verdict

**FAIL**

核心原因：本次 diff 未包含 TASK-001a 要求的任何验收标准变更（StripeService / PayPalService / UnionPayService 三个文件完全不在 diff 中），反而包含了大量超出任务范围的修改。

【必须修复】

1. **实现 StripeService.php 的 `withBasicAuth()` → `withToken()` 替换**（验收标准 #1，当前未修改）
2. **实现 StripeService.php webhook secret 未配置时抛出 `\RuntimeException`**（验收标准 #2，当前未修改）
3. **实现 PayPalService.php 的 `verifyWebhookSignature()` 方法**（验收标准 #3，当前未修改）
4. **实现 UnionPayService.php 的真实 RSA 签名验证**（验收标准 #4，当前未修改）
5. **`SocialiteService::revokeToken()` GitHub 撤销应使用 DELETE 方法而非 POST**
6. **`SocialiteService::refreshToken()` 需处理 `tenant_id` 为 null 的情况**
7. **`PayService::validateConfig()` 的 `default` 分支应返回 `valid: false`**

【建议改进】（非阻塞）

- `SocialiteService` 职责过重，建议拆分 Token 刷新/撤销逻辑到独立类
- `SocialiteService::getRevokeUrl()` 和 `getTokenUrl()` 中的全局 config 引用在多租户场景下可能不正确
- `RefundService` 存量数据兼容性：旧格式 `extra.refund_no` 需要迁移或向后兼容处理
- `SocialiteService::revokeToken()` 的 catch 块应添加日志记录
- `TenancyServiceProvider::registerCoreServices()` 考虑延迟加载而非无条件注册
