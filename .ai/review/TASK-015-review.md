Now I have all the context needed. Here's my review:

---

## Architecture

Service → Controller 分层清晰，MfaService 与 SessionService 职责边界明确，通过构造函数 DI 注入，符合 Laravel 最佳实践。TOTP 两步绑定（setup → confirm）使用 Cache 做服务端状态暂存，设计合理。三个 Model 未使用 `HasTenantScope` trait，DocBlock 解释为"用户账户级安全数据，不参与租户隔离"——设计意图合理，但任务文档要求"新建模型 use `HasTenantScope` trait"，属于静默偏离规范，应在 ADR 或任务文档中明确说明。

`SessionService::listAnomalousSessions()` 已定义但未被任何 Controller 调用，属于死代码。`SmsService::send()` 调用缺少 import 但同命名空间下可正常解析——不过显式 import 更符合可读性规范。

## Code Quality

- 命名规范、中文注释、PHPDoc 整体良好，符合 PSR-12。
- `MfaController::deviceToArray(MfaDevice $device)` 和 `sessionToArray(UserSession $session, ?int $currentTokenId)` 类型声明已补齐 ✅
- `MfaService::listDevices(): Collection` 和 `SessionService::listSessions(): Collection` 返回值类型已补齐 ✅
- `getRecoveryCodeStatus` 优化为单次 `selectRaw` 查询 ✅
- `sendEmailCode` / `sendSmsCode` 仍返回验证码明文作为返回值（`MfaService:116`、`MfaService:160`），Controller 中已忽略该返回值——无害但 Service 层暴露验证码明文的设计不够严谨，建议改为 `void` 或 `bool`。
- `SessionService::setSessionTimeout` 仅修改运行时 config，不持久化——如有意为之应在注释中说明。

## Type Safety

- `AuthController::recordSession` 的 `$tenantId` 参数已修正为 `?int` ✅
- `SessionService::recordSession` 的 `$tenantId` 参数为 `?string`，而 `AuthController` 传入时做 `(string)` 转换（`AuthController:375`）——类型链路 `?int` → `(string)` → `?string` 功能正确但不够优雅，建议统一为 `?int`。
- 其余方法类型标注基本完整，`match` 表达式、`hash_equals` 等用法正确。

## Security

**【严重】TOTP 密钥替换攻击已修复** ✅：`confirmTotp` 现在从 `Cache::pull` 读取服务端缓存的密钥，不再信任客户端提交。

**【已修复】速率限制补充** ✅：`/mfa/totp/confirm` 和 `/mfa/recovery-codes/generate` 已添加 `throttle:5,1`。

**【中等】`mfa_totp_setup` 缺少 throttle**：`setupTotp` 端点（`routes/api.php:67`）没有速率限制。攻击者可大量调用生成密钥，填满 Cache（每个 key 占用 300 秒 TTL）。建议添加 `throttle:5,1`。

**【中等】recovery code 端点无二次验证**：`generateRecoveryCodes` 可直接调用生成新恢复码并使旧码失效。如果攻击者获得会话令牌，可立即锁定用户的所有恢复码。建议要求当前密码或 MFA 验证。

**【中等】`mfa_totp_setup_expired` 翻译 key 缺失**：`MfaController:65` 使用了 `trans('auth.mfa_totp_setup_expired')`，但 `lang/zh_CN/auth.php` 和 `lang/en/auth.php` 均未定义该 key。运行时会回退显示 key 字符串本身。

**做得好的地方：**
- `MfaDevice.secret` 使用 `encrypted` cast 加密存储 ✅
- 恢复码使用 SHA-256 哈希存储 ✅
- 邮箱/短信验证码使用 `hash_equals` 防时序攻击 ✅
- `secret` 字段在 Model `$hidden` 中 ✅
- MFA 验证端点有 `throttle:5,1` 限制 ✅
- 审计日志覆盖了关键操作 ✅

## Performance

- `getRecoveryCodeStatus` 已优化为单次聚合查询 ✅
- `revokeAllSessions`（`SessionService:123`）和 `purgeExpiredSessions`（`SessionService:196`）存在 TOCTOU 竞态：先 `get()` 获取记录再 `delete()`，中间可能有新记录插入，导致返回的 `$count` 与实际删除数不一致。建议改用 `selectRaw` 聚合 + 条件 delete，或直接用 delete 返回值。
- `verifyTotpChallenge` 遍历用户所有 TOTP 设备逐个验证（`MfaService:491`），当前 `unique(['user_id', 'type'])` 约束限制每种类型只有一个设备所以实际最多 1 次循环——但如果未来放开约束，这里会成为 N+1。

## Potential Bugs

1. **`mfa_devices` 唯一约束 `unique(['user_id', 'type'])`**：一个用户每种 MFA 类型只能绑定一个设备。`setupTotpDevice` / `setupEmailDevice` / `setupSmsDevice` 均未检查是否已存在同类型设备，重复绑定会抛数据库异常而非友好错误。
2. **`sendEmailCode` 静默失败**（`MfaService:119-125`）：当 `$user` 不存在或 `$user->email` 为空时，方法仍返回验证码但不发送邮件，Controller 无法区分成功与失败，用户会收到"验证码已发送"但实际未收到。
3. **`confirmTotp` 使用 `Cache::pull` 后验证失败仍消耗密钥**（`MfaController:61`）：用户输入错误验证码后，缓存密钥被 pull 删除，必须重新调用 `setupTotp`。这是合理的安全设计（防暴力枚举），但应确保前端有对应的重新获取流程。
4. **`SessionService::recordSession` 的 `$tenantId` 类型为 `?string`，但 `AuthController:375` 做了 `$tenantId !== null ? (string) $tenantId : null` 转换——当 `$tenantId` 为 `0` 时，`(string) 0` 为 `"0"`，但 `?int` 的 `0` 不会走到 `null` 分支，功能正确但类型链路冗余。**

## Verdict

**PASS**（上一版的 4 个必须修复项均已解决）

【建议改进】：

1. **补充 `mfa_totp_setup_expired` 翻译 key**：`lang/zh_CN/auth.php` 和 `lang/en/auth.php` 均缺失该 key，运行时会显示原始 key 字符串。——阻塞级别低但影响用户体验。
2. **`setupTotp` 添加 `throttle` 中间件**：防止攻击者大量生成密钥填满 Cache。
3. **清理 `SessionService::listAnomalousSessions()` 死代码**：已定义但未被任何 Controller 调用，如无计划使用应移除。
4. **`sendEmailCode` / `sendSmsCode` 静默失败问题**：当用户不存在或联系方式为空时，应抛出异常或返回失败标识，而非返回验证码。
5. **重复绑定同类型 MFA 设备的友好错误处理**：`createDevice` 应在插入前检查唯一约束，返回业务错误而非数据库异常。
