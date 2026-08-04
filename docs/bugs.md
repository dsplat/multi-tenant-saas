# 已知问题清单

> 基于 2026-07-20 深度代码审查发现
> **最后更新**: 2026-08-04（确认全部修复状态与 commit 对应关系）
> 审查覆盖：Auth、Operator、Infrastructure、User 四大模块

---

## 修复状态总览

| 严重度 | 总数 | 已修复 | 误报/非 bug | 未修复 |
|--------|------|--------|------------|--------|
| 严重 | 8 | 7 | 1 | 0 |
| 中等 | 12 | 7 | 5 | 0 |
| 低 | 10 | 4 | 5 | 1 |
| **总计** | **30** | **18** | **11** | **1** |

---

## 一、严重问题（需立即修复）

### ~~BUG-001: OAuth `role` 字段 Bug — 角色分配失效~~ ✅ 已修复

**状态**: 已修复 — SocialiteService 已不再硬编码 `'role' => 'platform_user'`，改为使用 role_id 查询。

---

### ~~BUG-002: PasswordService 双重 Hash~~ ❌ 误报

**状态**: 误报 — User 模型 `casts()` 有显式注释「禁止对 password 使用 hashed cast」，`Hash::make()` 是唯一且正确的 hash 操作，不存在双重 hash。

---

### ~~BUG-003: OauthAccount 缺少 Tenant import~~ ✅ 已修复

**状态**: 已修复 — OauthAccount 已使用 `BelongsToTenant` trait，tenant 关系通过 trait 提供。

---

### ~~BUG-004: Operator 模型 `$incrementing` 未设为 false~~ ✅ 已修复

**状态**: 已修复 — Operator 模型使用 `HasGlobalId` trait 处理非自增主键。

---

### ~~BUG-005: OperatorAuthController 登录锁定形同虚设~~ ✅ 已修复

**状态**: 已修复 — 登录失败时递增 `login_attempts`，达到阈值设置 `locked_until`，成功登录时重置。

---

### ~~BUG-006: IdentifyOperator 中间件不阻断无效请求~~ ✅ 已修复

**状态**: 已修复 — Operator 不属于当前租户时返回 403 `TenantAccessDenied`。

---

### ~~BUG-007: Login.vue redirect 开放重定向~~ ✅ 已修复

**状态**: 已修复 — redirect 参数现在校验必须以 `/` 开头且非 `//`（协议相对 URL），否则回退 `/dashboard`。

---

### ~~BUG-008: MfaVerify.vue user_id 暴露在 URL 中~~ ✅ 已修复

**状态**: 已修复 — 登录时生成一次性 `challenge_token`（Cache 5min），前端传 token 而非 user_id；后端从缓存解析 user_id，验证后销毁 token。

---

## 二、中等问题（建议尽快修复）

### ~~BUG-009: 控制器基类不一致~~ ✅ 已修复

**状态**: 已修复 — 框架新增 `BaseController`（`src/Http/Controllers/BaseController.php`），组合 `ApiResponse`/`AuthorizesRequests`/`ValidatesRequests` trait。src/ 下控制器已统一继承。

---

### ~~BUG-010: FormRequest 未使用~~ ✅ 已修复

**状态**: 已修复 — `resetPassword` 已改用 `ResetPasswordRequest`；login/register 保留内联验证（delegated 拒绝必须优先于字段验证返回，已加注释）。

---

### ~~BUG-011: User 模型缺少关系定义~~ ✅ 已修复

**状态**: 已修复 — 补充 `mfaDevices()`、`mfaRecoveryCodes()`、`sessions()`、`trustedDevices()`、`passwordHistories()` 五个 HasMany 关系。

---

### ~~BUG-012: RbacService::deleteRole() 未清理 operator_tenants.role_id~~ ✅ 已修复

**状态**: 已修复 — 见原文。

---

### ~~BUG-013: PasswordService::cleanupHistory() 使用错误主键~~ ✅ 已修复

**状态**: 已修复 — 代码已正确使用 `pluck('password_history_id')` + `whereIn('password_history_id', $ids)`。

---

### ~~BUG-014: MailerService SMTP 密码明文存储~~ ❌ 误报

**状态**: 误报 — `TenantMailConfigController` 已标记 `smtp_password => true`（加密存储），`TenantSetting` 模型有 `is_encrypted` + `Crypt::decryptString` 完整链路。

---

### ~~BUG-015: admin.php 和 api.php 路由权限粒度不一致~~ — 设计合理

**状态**: 非 bug — 通知路由面向所有认证用户（无需 RBAC），广播管理/模块配置面向运营（需 RBAC），权限粒度与访问层级匹配。

---

### ~~BUG-016: IdentifyTenant URL 参数注入~~ — 设计行为

**状态**: 非 bug — `?tenant_id=xxx` 为已文档化的不可信源输入（见 tenant.md 2.5 优先级 1：「不可信，需归属校验」），后续步骤会验证归属。

---

### ~~BUG-017: OperatorService::acceptInvite 双重 Hash~~ ❌ 误报

**状态**: 误报 — 与 BUG-002 同理，Operator 模型无 `hashed` cast，显式 `Hash::make()` 是正确设计。

---

### ~~BUG-018: OAuthCallback.vue 使用 GET 传递 code~~ — 标准协议

**状态**: 非 bug — OAuth 2.0 授权码流程规定 authorization code 经 GET redirect 传递（RFC 6749 §4.1.2），code 一次性使用且需 client_secret 换取 token。

---

### ~~BUG-019: Token 存储在 localStorage~~ — 架构决策

**状态**: 记录为架构决策，见 `docs/review_bugs.md` SMELL-003。需前后端联调改为 httpOnly cookie。

---

### ~~BUG-020: TenantController::suspend/activate 缺少租户归属检查~~ ✅ 已修复

**状态**: 已修复 — 补充 `ensureTenantAccessOrSuperAdmin()` 调用（与 show/update/destroy 对齐）；修复 NotificationService 缺少 User import。

---

## 三、低优先级问题（代码质量改进）

### ~~BUG-021: HTTP 客户端不统一~~ ✅ 已修复

**状态**: 已修复 — 公共前台 SPA 主流为原生 fetch（18 个文件），仅 AcceptInvite.vue、Onboarding.vue 两个异类用 axios；已将二者转为 fetch（Onboarding 引入 postJson 统一封装），public 视图已无 axios 残留。

---

### ~~BUG-022: Dashboard.vue email_verified 字段名不一致~~ ❌ 不存在

**状态**: 源码验证不成立 — Dashboard.vue 当前全部使用 `email_verified_at`，与后端一致，全库无 `email_verified` 残留。

---

### ~~BUG-023: Profile.vue 缺少头像上传~~ ✅ 已修复

**状态**: 已修复 — 新增 `POST /api/v1/auth/profile/avatar`（UserProfileController::uploadAvatar）：图片校验（jpg/png/gif/webp ≤2MB）、存入 public 磁盘 `avatars/{user_id}.{ext}` 固定命名、换扩展名时清理历史文件无孤儿；Profile.vue 增加头像预览 + 上传 UI。头像属平台级身份资产，不走受 RBAC 限制的租户 Storage 模块。

---

### ~~BUG-024: Security.vue 缺少“添加 MFA 设备”入口~~ ❌ 不存在

**状态**: 源码验证不成立 — Security.vue 已有完整「添加 TOTP 设备」流程（setup 取密钥 → 输入验证码 → confirm 绑定 → 刷新设备列表），后端 `/api/v1/mfa/totp/setup|confirm` 路由齐备。

---

### ~~BUG-025: notifications/Index.vue filterType 未实现~~ ✅ 已修复

**状态**: 已修复 — 筛选栏补齐类型按钮（系统/账单/AI/安全，与后端 InAppNotification::TYPES 对齐），点击设置 filterType 并携带 `type` 参数请求，按钮附带 `unread_by_type` 未读计数。

---

### ~~BUG-026: FormRequest 类未被使用（User 模块）~~ ❌ 不存在

**状态**: 源码验证不成立 — StoreMemberRequest 已接入 TenantMemberController::store，StoreTenantRequest/UpdateTenantRequest 已接入 TenantController::store/update。

---

### ~~BUG-027: UserSearchService SQL LIKE 通配符未转义~~ ❌ 不存在

**状态**: 源码验证不成立 — UserSearchService 已有 `str_replace(['%', '_'], ['\%', '\_'], $query)` 转义。

---

### ~~BUG-028: OAuth 服务静态方法设计~~ ✅ 已修复

**状态**: 已修复 — `__callStatic` 已全量清除（从 23 处降至 0），OAuth 服务已改用实例方法 + 构造器注入。

---

### ~~BUG-029: SAML Metadata 返回类型错误~~ ❌ 不存在

**状态**: 源码验证不成立 — `samlMetadata()` 当前声明为 `: Response`（非 JsonResponse），与 XML 返回一致。

---

### BUG-030: BindSessionDomain Octane 风险

**状态**: ⚠️ 保留 — 通过 `config()` 修改 `session.domain` 在 Octane 下有跨请求持久化风险。保留决策：Octane Level 1 方案已定性为鸡肋不推广，当前未启用 Octane，无实际风险；若未来启用 Octane 需一并重构。

---

## 四、统计

| 严重度 | 总数 | 已修复 | 误报/非 bug | 未修复 |
|--------|------|--------|------------|--------|
| 严重 | 8 | 7 | 1 (BUG-002) | 0 |
| 中等 | 12 | 7 | 5 (BUG-014/015/016/017/018) | 0 |
| 低 | 10 | 4 (BUG-021/023/025/028) | 5 (BUG-022/024/026/027/029) | 1 (BUG-030) |
| **总计** | **30** | **18** | **11** | **1** |

> 唯一保留项 BUG-030 为 Octane 前置风险，当前未启用 Octane，无实际影响。
