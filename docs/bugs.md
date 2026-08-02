# 已知问题清单

> 基于 2026-07-20 深度代码审查发现
> **最后更新**: 2026-08-01（第四轮审查后状态同步）
> 审查覆盖：Auth、Operator、Infrastructure、User 四大模块

---

## 修复状态总览

| 严重度 | 总数 | 已修复 | 未修复 |
|--------|------|--------|--------|
| 严重 | 8 | 6 | 2 |
| 中等 | 12 | 4 | 8 |
| 低 | 10 | 1 | 9 |
| **总计** | **30** | **11** | **19** |

---

## 一、严重问题（需立即修复）

### ~~BUG-001: OAuth `role` 字段 Bug — 角色分配失效~~ ✅ 已修复

**状态**: 已修复 — SocialiteService 已不再硬编码 `'role' => 'platform_user'`，改为使用 role_id 查询。

---

### BUG-002: PasswordService 双重 Hash — 用户无法登录

**状态**: ⚠️ 仍存在

**影响范围**: 所有通过 `PasswordService::doReset()` 重置密码的用户

**根因**: `doReset()` 中 `Hash::make($newPassword)` 手动 hash，但 User 模型 `'password' => 'hashed'` cast 会自动 hash，导致密码被 hash 两次。

**文件**: `src/Modules/Auth/Services/PasswordService.php:49`

**修复方案**: 移除 `Hash::make()`，直接赋值明文密码，依赖 cast 自动 hash。

> **注意**: OperatorService 中的 `Hash::make()` 有注释说明是"业务层显式 hash，不依赖模型 cast"，属不同情况。

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

### BUG-007: Login.vue redirect 开放重定向

**状态**: ⚠️ 仍存在

**影响范围**: 登录后的跳转

**文件**: `resources/pages/public/views/Login.vue`

**修复方案**: 验证 redirect 以 `/` 开头且不包含 `//`。

---

### BUG-008: MfaVerify.vue user_id 暴露在 URL 中

**状态**: ⚠️ 仍存在

**影响范围**: MFA 验证流程

**文件**: `resources/pages/public/views/MfaVerify.vue`

**修复方案**: 后端应关联 user_id 到待验证的临时 session，而非信任前端传入的 user_id。

---

## 二、中等问题（建议尽快修复）

### ~~BUG-009: 控制器基类不一致~~ ✅ 已修复

**状态**: 已修复 — 框架新增 `BaseController`（`src/Http/Controllers/BaseController.php`），组合 `ApiResponse`/`AuthorizesRequests`/`ValidatesRequests` trait。src/ 下控制器已统一继承。

---

### BUG-010: FormRequest 未使用

**状态**: ⚠️ 仍存在

**影响范围**: Auth 模块验证逻辑 — `LoginRequest`、`RegisterRequest`、`ResetPasswordRequest` 已定义但未被控制器引用。

---

### BUG-011: User 模型缺少关系定义

**状态**: ⚠️ 仍存在

**影响范围**: User 模型缺少 `mfaDevices()`、`mfaRecoveryCodes()`、`sessions()`、`trustedDevices()`、`passwordHistories()` 关系。

---

### ~~BUG-012: RbacService::deleteRole() 未清理 operator_tenants.role_id~~ ✅ 已修复

**状态**: 已修复 — 见原文。

---

### BUG-013: PasswordService::cleanupHistory() 使用错误主键

**状态**: ⚠️ 仍存在 — 使用 `pluck('id')` 但主键是 `password_history_id`。

---

### BUG-014: MailerService SMTP 密码明文存储

**状态**: ⚠️ 仍存在

---

### BUG-015: admin.php 和 api.php 路由权限粒度不一致

**状态**: ⚠️ 仍存在

---

### BUG-016: IdentifyTenant URL 参数注入

**状态**: ⚠️ 仍存在 — `?tenant_id=xxx` 允许任意用户指定租户 ID。

---

### BUG-017: OperatorService::acceptInvite 双重 Hash

**状态**: ⚠️ 仍存在 — 但有注释说明是显式 hash，需确认是否与 PasswordService 的 hashed cast 冲突。

---

### BUG-018: OAuthCallback.vue 使用 GET 传递 code

**状态**: ⚠️ 仍存在

---

### ~~BUG-019: Token 存储在 localStorage~~ — 架构决策

**状态**: 记录为架构决策，见 `docs/review_bugs.md` SMELL-003。需前后端联调改为 httpOnly cookie。

---

### BUG-020: TenantController::suspend/activate 缺少租户归属检查

**状态**: ⚠️ 仍存在

---

## 三、低优先级问题（代码质量改进）

### ~~BUG-028: OAuth 服务静态方法设计~~ ✅ 已修复

**状态**: 已修复 — `__callStatic` 已全量清除（从 23 处降至 0），OAuth 服务已改用实例方法 + 构造器注入。

---

### 其余 BUG-021~027, BUG-029~030

**状态**: ⚠️ 仍存在 — 详见原文。

---

## 四、统计

| 严重度 | 总数 | 已修复 | 未修复 |
|--------|------|--------|--------|
| 严重 | 8 | 6 | 2 (BUG-002, BUG-007/008) |
| 中等 | 12 | 4 | 8 |
| 低 | 10 | 1 | 9 |
| **总计** | **30** | **11** | **19** |
