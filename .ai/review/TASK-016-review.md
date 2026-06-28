## Architecture

**合理，无阻塞问题。**

- `PasswordPolicyService` 和 `SsoService` 均已在 `TenancyServiceProvider` 中注册为 singleton（第 154-155 行），符合项目规范。
- `findOrCreateUser` 整体包裹 `DB::transaction`，User + OauthAccount + TenantUser 三步操作保持原子性，防止部分失败导致孤立数据。
- `recordFailedLogin` 改用 Eloquent `increment()` + `refresh()`，再以 Query Builder 单独更新 `locked_until`，读写分离语义清晰。
- SAML 证书校验从"可选"改为"必须"（先判空再验签），消除了条件分支歧义，逻辑更简洁。
- SSO 回调端点无认证中间件、管理端点有 RBAC 控制，路由分层正确。

---

## Code Quality

**整体良好，有 1 处小瑕疵。**

- `recordFailedLogin` 改用 `$user->increment()` + `$user->refresh()` 替代手动算术，代码更简洁。
- `findOrCreateUser` 从 `User::unguarded(function() { ... })` 改为直接 `new User` + 赋值 + `save()`，可读性更好。
- `decodeJwtPayload` 从静默返回空数组改为抛出 `RuntimeException`，调用方能区分"无数据"和"解析失败"。
- `ssoCallback` 使用 `$request->only([...])` 限制传入参数，攻击面收窄。
- 翻译 key `sso_state_invalid`、`saml_certificate_missing`、`oidc_jwt_invalid` 已同步添加到 `lang/zh_CN/auth.php` 和 `lang/en/auth.php`，无缺失。

**瑕疵：** `recordFailedLogin` 中同一方法内先用 Eloquent（`$user->increment()`）再用 Query Builder（`DB::table()->update()`）更新同一张表，风格不统一。建议统一为 Eloquent：`$user->locked_until = now()->addMinutes($lockMinutes); $user->save();`，可省去末尾多余的 `refresh()`。

---

## Type Safety

**无问题。**

- 所有方法参数和返回值类型声明完整。
- `$user->increment()` 返回受影响行数（int），与后续 `refresh()` 配合使用，类型无歧义。
- `Session::pull('sso_state')` 返回 `mixed`，`$request->input('state')` 返回 `mixed`，`hash_equals` 要求 `string`。实际场景中 `Str::random(32)` 恒为非空字符串，且前置 `! $expectedState || ! $incomingState` 的 falsy 检查已过滤 null/空串，运行时不会触发 `TypeError`。不过显式 cast 为 `(string)` 会更严谨。

---

## Security

**本次修复解决了上一轮 review 的关键安全问题，有 1 个中等问题。**

✅ **SAML/OIDC state 验证** — `ssoRedirect` 将 state 存入 session，`ssoCallback` 中用 `hash_equals` 常量时间比较，`Session::pull()` 确保一次性使用，防止 CSRF。

✅ **SAML 签名强制校验** — 证书为空时直接抛 `RuntimeException`，不再静默接受未签名响应。

✅ **回调输入收窄** — `$request->only(['SAMLResponse', 'RelayState', 'code', 'state'])` 替代 `$request->all()`。

✅ **敏感字段保护** — `SsoProvider` 模型 `$hidden` 包含 `client_secret` 和 `certificate`，`toArray()` 不会暴露。`ssoProviderToArray` 额外添加 `has_client_secret` / `has_certificate` 布尔标志，设计合理。

**中等问题：**

1. **`storeSsoProvider` 直接传递 `$request->all()` 给 `createProvider`** — 虽然 Service 内部有 `validateProviderInput()`，但 `all()` 包含所有请求字段（含框架内部字段如 `_token`），且 Service 层校验失败会抛 `RuntimeException` 而非返回 422 结构化错误。应使用已 validate 的 `$request->validated()` 替代 `$request->all()`。

---

## Performance

**无新增问题。**

- `increment()` 是原子数据库操作，并发安全。
- `findOrCreateUser` 事务内的查询（OauthAccount、User、TenantUser）均有明确 where 条件，无 N+1 风险。
- `refresh()` 引入一次额外 SELECT，但此处仅在 `recordFailedLogin` 单次登录失败时调用，频率低，可接受。

---

## Potential Bugs

**有 1 个功能性问题。**

1. **【中等】`recordFailedLogin` 的锁定逻辑从未被 `login()` 方法调用** — `AuthController::login()` 在密码验证失败时直接返回 401，既没有调用 `PasswordPolicyService::recordFailedLogin()` 记录失败次数，也没有在登录前调用 `isLocked()` 检查锁定状态。这意味着：
   - 暴力破解锁定功能完全不生效（`login_attempts` 永远为 0）
   - 即使账号被其他路径锁定（如管理员手动锁定），`login()` 也不会拒绝
   
   同理，登录成功路径也未调用 `recordSuccessfulLogin()` 重置失败计数。`PasswordPolicyService` 虽然正确实现了所有方法，但作为 dead code 未集成到登录流程中，验收标准"暴力破解锁定正常"无法通过。

2. **【低】`ssoCallback` 中 state 校验使用 falsy 检查** — `! $expectedState || ! $incomingState` 在 state 恰好为 `"0"` 时会误判为无效（虽然 `Str::random(32)` 不会产生 `"0"`，实际无风险，但属于防御性编程缺陷）。

3. **【低】`storeSsoProvider` 存在重复校验** — Controller 调用 `$request->validate(...)` 后又传 `$request->all()` 给 Service，Service 内部再次 `validateProviderInput()`，两套校验规则略有差异（Controller 允许 `certificate`、`slo_url` 等字段，Service 的 `validateProviderInput` 未包含 `certificate`），可能导致 Service 校验漏过 Controller 层已校验的字段。

---

## Verdict

**PASS**

本次 diff 修复了上一轮 review 的两个关键问题（`increment()` 返回值用法、翻译 key 缺失），代码质量有明显提升。

【建议改进】（非阻塞）：

1. **将 `PasswordPolicyService` 集成到 `AuthController::login()` 流程** — 在密码验证失败时调用 `recordFailedLogin()`，在登录前检查 `isLocked()`，登录成功时调用 `recordSuccessfulLogin()`。当前锁定功能为 dead code，验收标准"暴力破解锁定正常"无法通过。建议作为 follow-up task 或在本 task 内追加。
2. `storeSsoProvider` 使用 `$request->validated()` 替代 `$request->all()`，避免传递未预期字段并获得结构化 422 响应。
3. state 校验中 `$expectedState` 和 `$incomingState` 显式 cast 为 `(string)` 以增强类型安全。
4. `recordFailedLogin` 中统一使用 Eloquent 更新 `locked_until`，保持风格一致。
