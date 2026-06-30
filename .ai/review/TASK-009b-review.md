Now I have full context. Here is the review.

---

## Architecture

**合理，但 Controller 职责边界有轻微越界。**

- 4 个新方法（`register`、`onboardingStatus`、`onboardingStep`、`onboardingComplete`）正确委托给 `TenantOnboardingService`，Controller 仅负责 HTTP 层（验证、响应格式化、异常映射），符合瘦 Controller 原则。
- 路由放在无需认证的独立 `throttle:10,1` 组内，与认证 API 分离，结构清晰。
- **问题：** `onboardingStep` 方法内嵌了 5 步的字段验证规则（`$stepFields` 硬编码），这些业务规则应属于 Service 层而非 Controller。如果 Service 侧新增/修改步骤，Controller 需同步改动，违反单一职责。不过作为 TASK-009b 的范围限制（只改 Controller + routes），当前实现可接受。
- 非任务相关文件（`.ai/scripts/*`、`.ai/state.json`）也被包含在 diff 中，与 TASK-009b 目标无关，但不影响功能。

## Code Quality

**整体良好，命名规范，可读性高。**

- 方法命名 `register`/`onboardingStatus`/`onboardingStep`/`onboardingComplete` 语义明确。
- 异常处理分层清晰：`InvalidArgumentException` → 422，`RuntimeException` → 400，`\Throwable` → 500，并对用户隐藏内部错误详情。
- **重复代码：** 4 个方法中的 try-catch 结构高度相似（尤其是 token 获取 + 服务调用 + 异常映射），可考虑抽取一个 `callOnboardingService` 辅助方法。但考虑到每个方法的异常类型和响应结构略有不同，当前重复在可接受范围内。
- `$request->validate()` 在 `onboardingStep` 中被调用了两次（第 359 行验证 token，第 374 行验证 step 字段），第一次验证结果未复用，虽无功能问题但略显冗余。
- `array_intersect_key($data, array_flip($allowed))` 这行在 `$request->validate()` 之后是多余的——`validate()` 本身已按规则白名单过滤，不会返回未声明的字段。

## Type Safety

**基本完整，有一处潜在类型问题。**

- 新方法全部标注了 `JsonResponse` 返回类型，比旧方法（无返回类型）更规范。
- `$step` 参数声明为 `int`，路由约束 `->where('step', '[1-5]')` 保证了范围，但 `$stepFields[$step] ?? []` 在 `$step` 超出 1-5 时会返回空数组，导致 `$request->validate([])` 无规则通过——虽然路由约束已拦截，但缺少对未知 step 的显式防御（如抛出 400）。
- `onboardingStatus` 中 `$status` 的返回类型未知（取决于 `TenantOnboardingService::getStatus()`），直接放入 JSON 响应，无法在 Controller 层验证其结构。

## Security

**发现一个 P0 级安全问题（在依赖的 Service 中，但由本任务的 Controller 直接暴露）。**

1. **🔴 P0 — 密码明文存储：** `TenantOnboardingService::createAdminUser()` 将 `$basic['password']` 原文写入数据库，未使用 `Hash::make()`。`register` 端点接收用户密码后原样传递给 `startRegistration($validated)`，最终明文入库。**全项目所有其他用户创建路径均使用 `Hash::make()` 或 `bcrypt()`。** 这是严重的安全漏洞，可在登录时被 `password_verify()` 检测到不匹配导致无法登录，或更糟——如果数据库被泄露，所有通过 onboarding 注册的用户密码直接暴露。**此问题虽在 Service 文件中，但 Controller 是入口，必须在本 task 范围内一并修复。**

2. **🟡 中 — `onboardingStatus` 使用 GET + query token：** 注册 token 出现在 URL 中，会被记录在服务器访问日志、浏览器历史、Referer 头中。建议改为 POST + body 传递 token，与 `onboardingStep` 和 `onboardingComplete` 保持一致。这不是阻塞性问题，但违反了安全最佳实践。

3. **🟢 良好 — 限流：** `throttle:10,1`（每分钟 10 次）对注册端点合理，能有效防止暴力枚举和注册滥用。

4. **🟢 良好 — 无 SQL 注入风险：** 全部使用 Laravel 的 `validate()` + Eloquent，无原生 SQL 拼接。

5. **🟢 良好 — 无 XSS 风险：** 纯 JSON API 响应。

6. **密码验证规则过弱：** `'password' => 'required|min:8'` 仅要求 8 字符长度，缺少大小写、数字、特殊字符等复杂度要求。`AuthController::register` 的密码验证规则也如此，但作为公开注册入口应更严格。

## Performance

**无明显性能问题。**

- 每个请求仅调用 Service 的一个方法，无 N+1 查询风险。
- `onboardingStep` 中的 `$request->validate()` 调用两次（token + step 字段），但开销可忽略。
- 限流中间件使用 Laravel 内置的缓存驱动实现，无额外性能负担。

## Potential Bugs

1. **🔴 密码明文存储（同 Security #1）：** 这不仅是安全问题，也是功能 Bug——用户注册后将无法通过 `password_verify()` 正常登录。

2. **🟡 `onboardingStep` 路由顺序潜在冲突：** `POST /v1/tenants/onboarding/{step}` 的路由定义在 `POST /v1/tenants/onboarding/complete` **之后**，这是正确的——Laravel 按定义顺序匹配，`complete` 会先被匹配。但 `register` 的路由路径是 `/tenants/register`，与认证组的 `/v1/auth/register` 不冲突。当前无 Bug，但值得注意。

3. **🟡 `$stepFields` 空数组时静默通过：** 如果路由约束失效（理论上不会），`$stepFields[$step] ?? []` 会返回空数组，`$request->validate([])` 不会报错，空 `$data` 会被传给 Service。缺少显式的 step 范围校验。

4. **🟢 错误处理完整：** 所有方法都有 try-catch，不会因未捕获异常导致 500 泄露堆栈。

## Verdict

**FAIL**

---

### 【必须修复】

1. **密码明文存储（P0 安全漏洞）：** `TenantOnboardingService::createAdminUser()` 中 `'password' => $basic['password']` 必须改为 `'password' => Hash::make($basic['password'])`，并在文件顶部添加 `use Illuminate\Support\Facades\Hash;`。虽然修改点在 Service 文件而非本 task 明确允许的两个文件中，但这是 Controller `register` 端点直接暴露的致命缺陷，不修复则 onboarding 注册功能完全不可用。**至少应在 Controller 的 `register` 方法中对 `$validated['password']` 执行 `Hash::make()` 后再传入 Service，作为防御性修复。**

2. **密码验证规则过弱：** `'password' => 'required|min:8'` 应增加复杂度规则（如 `'confirmed'`、自定义规则要求包含大小写和数字），与公开注册入口的安全要求匹配。
