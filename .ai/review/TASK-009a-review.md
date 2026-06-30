Now I have a complete picture. Let me write the review.

---

## Architecture

**模块边界清晰。** `TenantOnboardingService` 作为核心服务封装了完整的 5 步注册流程，Controller 层仅做 HTTP 适配（请求校验、响应格式、审计日志），职责划分合理。

**Cache-based session 设计合理。** 使用 `Cache::put` + TTL 实现断点续填，适合短生命周期的注册会话，无需额外建表。邮箱反向索引 (`onboarding:email:`) 防止重复注册也是合理设计。

**路由组织得当。** Onboarding 路由独立于认证路由组之外，仅挂载 `throttle` 中间件，符合「无需认证、仅限流」的语义。

**问题：Controller 与 Service 存在双重校验。** Controller `register()` 中的密码正则 (`/[a-z]/|/[A-Z]/|/[0-9]/`) 与 Service `stepRules(STEP_BASIC_INFO)` 中的 `'required|min:8'` 不一致。Controller 更严格但 Service 不知道——直接调用 Service 的场景绕过了复杂度校验。建议统一到 Service 层。

**问题：`TenantResource` 每次序列化调用 `TrialService::isInTrial()`。** 这在列表接口（`index()`）中会产生 N+1 问题——每个 Tenant 都会触发一次 `trial_ends_at` 字段访问（虽然在内存中是属性读取，但语义上是 per-item 计算）。

## Code Quality

**命名规范一致。** 方法名 `startRegistration`、`saveStep`、`complete`、`buildStatusResponse` 均为动词短语，符合 PSR-12 和项目既有风格。常量命名 `STEP_BASIC_INFO`、`SESSION_TTL` 语义明确。

**注释质量高。** Service 类的 PHPDoc 完整描述了职责、会话存储机制、字段兼容策略。Controller 的每个方法都有中文注释说明用途、认证要求和限流策略。

**代码可读性良好。** Service 474 行，方法拆分合理（`createTenant`、`createDefaultRole`、`createAdminUser`、`attachAdminMember`、`provisionTenantSettings`），单一职责原则遵循较好。

**小瑕疵：Controller onboarding 方法较冗长。** 四个 onboarding 方法中的 try-catch 模式高度重复（`InvalidArgumentException → 422`、`Throwable → 500`），可考虑抽取一个 trait 或基类方法。

## Type Safety

**Service 类型标注完整。** 所有 public/protected 方法均有参数类型和返回类型声明。`?array` 返回类型正确表达了 `getStatus` 的可空语义。

**Controller 返回类型正确。** 所有新增方法标注 `JsonResponse`。

**问题：`Tenant` 模型 `$fillable` 缺少 `onboarding_step` 和 `onboarding_completed`。** Migration 已添加这两个字段，`TenantResource` 也读取它们，但模型未加入 `$fillable`。当前代码未通过 `Tenant::create()` 写入这两个字段（Service 的 `createTenant` 未设置），所以不会触发 mass-assignment 异常——但如果未来需要更新这些字段，会静默失败。

**问题：`Tenant` 模型 `$casts` 缺少 `onboarding_step` 和 `onboarding_completed`。** `onboarding_step` 应 cast 为 `integer`，`onboarding_completed` 应 cast 为 `boolean`，否则 `TenantResource` 中 `$this->onboarding_step ?? null` 和 `$this->onboarding_completed ?? false` 的类型推断依赖数据库驱动的隐式转换。

## Security

**限流配置合理。** Onboarding 路由 `throttle:10,1`（每分钟 10 次）有效防止注册滥用。

**密码处理正确。** `startRegistration` 在存入 Cache 前使用 `Hash::make()` 加密；`buildStatusResponse` 从返回数据中 `unset` 密码字段。

**审计日志完整。** `onboarding_start`、`onboarding_complete`、`onboarding_failed` 三种事件均有记录，失败时记录 `reason`（内部错误时记录 `'internal_error'` 而非原始异常消息，避免信息泄露）。

**潜在风险：Token 暴露在 URL query string 中。** `onboardingStatus` 使用 `GET /tenants/onboarding/status?token=xxx`，token 会出现在 Web 服务器访问日志、浏览器历史和 Referer 头中。建议改用 POST 或 Header 传递 token。

**潜在风险：`onboardingStatus` 无需认证即可查询注册进度。** 虽然有 token 作为前置条件，但 token 是随机 64 字符，安全性依赖其不可猜测性。配合限流是可接受的，但应确保 token 有足够熵（当前 `Str::random(64)` 满足）。

## Performance

**无 N+1 查询风险（Service 内部）。** `complete()` 中的 DB 操作在单个事务内完成，各 create 操作不涉及循环查询。

**潜在 N+1：`TenantResource::toArray()` 中的 `TrialService::isInTrial()`。** 每次序列化 Tenant 时都会调用此方法。在 `index()` 分页列表（15 条/页）中，这会触发 15 次 `trial_ends_at` 字段访问。虽然 Eloquent 属性读取本身不触发额外查询（字段已在主查询中加载），但语义上是 per-item 计算，且未来如果 `isInTrial` 内部逻辑变复杂（如检查关联表），会变成真正的 N+1。

**Cache 操作轻量。** `Cache::put`/`Cache::get`/`Cache::has`/`Cache::forget` 均为 O(1) 操作，TTL 1 小时合理。

## Potential Bugs

**1. `onboardingStep` 路由允许 step=1，但 Controller 不校验 step 1 字段。** 路由 `->where('step', '[1-5]')` 包含 step 1。当 step=1 时，`$stepFields[1]` 不存在，`$stepFields[$step] ?? []` 返回空数组，`$request->validate([])` 通过，`$data` 为空数组。随后 Service 的 `saveStep` 会因 step 1 已在 `startRegistration` 中完成而抛出 `invalid_step` 异常（因为 `current_step` 已推进到 step 2）。**结果不会导致数据错误**，但语义不干净——step 1 不应出现在此端点的允许范围内。

**2. `onboardingStep` 路由允许 step=5，但 step 5 是 completion trigger 而非数据步骤。** 同上，Service 会拒绝（`in_array($step, self::DATA_STEPS)` 为 false），但应由路由层面排除。

**3. `TenantOnboardingService::complete()` 未写入 `onboarding_step` 和 `onboarding_completed` 字段。** `createTenant()` 调用 `Tenant::create()` 时未包含这两个字段，它们将保持默认值 (`0` 和 `false`)。`TenantResource` 会返回 `onboarding_step: 0, onboarding_completed: false`，与实际已完成状态矛盾。

**4. `TenantCreated` 事件在事务提交前 dispatch。** `complete()` 中 `Event::dispatch(new TenantCreated($tenant))` 在 `DB::transaction()` 之外调用，这是正确的——事件在事务提交后才触发。但如果 `saveSession` 或 `Cache::forget` 失败，事件仍会触发而会话状态不一致。这是边缘情况，优先级低。

**5. `generateUniqueSlug` 存在理论上的竞态条件。** 两个并发请求可能生成相同的 slug 并同时通过 `exists()` 检查。依赖数据库 `unique` 约束兜底，但异常未被捕获——会直接抛出 500 而非友好错误。

## Verdict

**PASS**

核心服务实现完整，5 步注册流程、断点续填、自动初始化均正确实现。安全防护（限流、密码哈希、审计日志）到位。无阻塞性问题。

【建议改进】（非阻塞）

1. **`Tenant` 模型 `$fillable` / `$casts` 缺少 `onboarding_step` 和 `onboarding_completed`** — 当前不影响运行（Service 未通过 mass-assignment 写入），但后续如需更新这些字段会静默失败。建议补充。
2. **`TenantResource` 中 `TrialService::isInTrial()` 的 N+1 风险** — 当前仅读取属性，但语义上是 per-item 计算。建议在 Controller 层预加载或使用 `whenLoaded` 模式。
3. **Controller `register()` 与 Service `stepRules()` 的密码校验规则不一致** — Controller 要求大小写+数字，Service 仅要求 `min:8`。建议统一到 Service 层。
4. **`onboardingStatus` 的 token 应避免放在 query string 中** — 建议改用 POST body 或 Authorization header。
5. **路由 `where('step', '[1-5]')` 应收紧为 `[2-4]`** — step 1 由 `register` 处理，step 5 由 `complete` 处理，`onboardingStep` 仅需覆盖 2-4。
6. **`generateUniqueSlug` 的竞态条件** — 建议捕获 `QueryException` 并重试，而非依赖 500 异常兜底。
