# 已知问题清单

> 基于 2026-07-20 深度代码审查发现
> 审查覆盖：Auth、Operator、Infrastructure、User 四大模块

---

## 一、严重问题（需立即修复）

### BUG-001: OAuth `role` 字段 Bug — 角色分配失效

**影响范围**: 所有 OAuth 注册流程（Google、GitHub、微信、支付宝、企业微信）

**根因**: `SocialiteService`、`AlipayOAuthService`、`WechatOAuthService`、`WechatWorkOAuthService` 的 `findOrCreateUser()` 中：
- `User::create(['role' => 'platform_user'])` — User 模型无 `role` fillable，字段被静默忽略
- `TenantUser::create(['role' => 'end_user'])` — TenantUser 期望 `role_id`（整数）而非 `role`（字符串）

**文件**:
- `src/Modules/Auth/Services/SocialiteService.php`
- `src/Modules/Auth/Services/AlipayOAuthService.php`
- `src/Modules/Auth/Services/WechatOAuthService.php`
- `src/Modules/Auth/Services/WechatWorkOAuthService.php`

**修复方案**: 使用 `role_id` 替代 `role`，查询对应角色的 ID。

---

### BUG-002: PasswordService 双重 Hash — 用户无法登录

**影响范围**: 所有通过 `PasswordService::doReset()` 重置密码的用户

**根因**: `doReset()` 中 `Hash::make($newPassword)` 手动 hash，但 User 模型 `'password' => 'hashed'` cast 会自动 hash，导致密码被 hash 两次。

**文件**: `src/Modules/Auth/Services/PasswordService.php`

**修复方案**: 移除 `Hash::make()`，直接赋值明文密码，依赖 cast 自动 hash。

---

### BUG-003: OauthAccount 缺少 Tenant import

**影响范围**: OAuth 账户的租户关联查询

**根因**: `OauthAccount::tenant()` 关系引用了 `Tenant::class` 但文件头未导入 `MultiTenantSaas\Modules\Infrastructure\Models\Tenant`。

**文件**: `src/Modules/Auth/Models/OauthAccount.php`

**修复方案**: 添加 `use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;`

---

### BUG-004: Operator 模型 `$incrementing` 未设为 false

**影响范围**: Operator 创建和更新操作

**根因**: Operator 使用雪花 ID (`operator_id`) 作为主键，但未声明 `public $incrementing = false`，Eloquent insert 行为可能异常。

**文件**: `src/Modules/Operator/Models/Operator.php`

**修复方案**: 添加 `public $incrementing = false;`

---

### BUG-005: OperatorAuthController 登录锁定形同虚设

**影响范围**: Operator 登录安全

**根因**: `login()` 方法检查 `locked_until`，但**从未在登录失败时递增 `login_attempts`**，也从未设置 `locked_until`。成功登录时重置 `login_attempts=0`，但失败时无任何操作。

**文件**: `src/Modules/Operator/Http/Controllers/OperatorAuthController.php`

**修复方案**: 在登录失败时递增 `login_attempts`，达到阈值时设置 `locked_until`。

---

### BUG-006: IdentifyOperator 中间件不阻断无效请求

**影响范围**: Admin/Console 域名下的所有请求

**根因**: 如果 token 无效或不存在 Operator，中间件只是 `$next($request)` 继续执行，不返回 401。无效 token 的请求会以匿名身份继续，可能导致权限绕过。

**文件**: `src/Modules/Operator/Http/Middleware/IdentifyOperator.php`

**修复方案**: 无效 token 时返回 401 Unauthorized。

---

### BUG-007: Login.vue redirect 开放重定向

**影响范围**: 登录后的跳转

**根因**: `window.location.href = redirect` 未验证 redirect 是否为内部路径。如果 redirect 参数是外部 URL（如 `https://evil.com`），会导致开放重定向漏洞。

**文件**: `resources/pages/public/views/Login.vue`

**修复方案**: 验证 redirect 以 `/` 开头且不包含 `//`。

---

### BUG-008: MfaVerify.vue user_id 暴露在 URL 中

**影响范围**: MFA 验证流程

**根因**: `const userId = computed(() => Number(route.query.user_id) || 0)`。MFA 验证的 user_id 在 URL 中明文暴露，攻击者可以篡改 user_id 尝试为其他用户完成 MFA 验证。

**文件**: `resources/pages/public/views/MfaVerify.vue`

**修复方案**: 后端应关联 user_id 到待验证的临时 session，而非信任前端传入的 user_id。

---

## 二、中等问题（建议尽快修复）

### BUG-009: 控制器基类不一致

**影响范围**: Auth 模块所有控制器

**根因**: `AuthController`/`MfaController`/`UserProfileController` 继承 `Illuminate\Routing\Controller`；`RbacController`/`TenantOAuthController`/`TenantMailConfigController` 继承 `App\Http\Controllers\Controller`。模块内基类不一致。

**文件**: `src/Modules/Auth/Http/Controllers/` 下所有控制器

**修复方案**: 统一使用模块内基类或框架基类。

---

### BUG-010: FormRequest 未使用

**影响范围**: Auth 模块验证逻辑

**根因**: `LoginRequest`、`RegisterRequest`、`ResetPasswordRequest` 已定义但未被控制器引用。控制器使用内联 `$request->validate()`，导致验证逻辑重复且不可复用。

**文件**:
- `src/Modules/Auth/Http/Requests/LoginRequest.php`（未使用）
- `src/Modules/Auth/Http/Requests/RegisterRequest.php`（未使用）
- `src/Modules/Auth/Http/Requests/ResetPasswordRequest.php`（未使用）

**修复方案**: 控制器中使用对应的 FormRequest 类替代内联验证。

---

### BUG-011: User 模型缺少关系定义

**影响范围**: User 模型的 Eloquent 关联查询

**根因**: User 模型缺少以下 HasMany 关系：
- `mfaDevices()`
- `mfaRecoveryCodes()`
- `sessions()`
- `trustedDevices()`
- `passwordHistories()`

**文件**: `src/Modules/Auth/Models/User.php`

**修复方案**: 添加缺失的关系定义。

---

### BUG-012: RbacService::deleteRole() 未清理 operator_tenants.role_id

**影响范围**: 角色删除操作

**根因**: 删除角色时将 `tenant_users.role_id` 设为 null，但**未同步清理 `operator_tenants.role_id`**。如果 Operator 也被分配了该角色，删除后 Operator 的角色引用会悬空。

**文件**: `src/Modules/Auth/Services/RbacService.php`

**修复方案**: 在 `deleteRole()` 中同时清理 `operator_tenants.role_id`。

---

### BUG-013: PasswordService::cleanupHistory() 使用错误主键

**影响范围**: 密码历史清理

**根因**: 使用 `pluck('id')` 但 PasswordHistory 的主键是 `password_history_id`，`id` 属性可能不存在。

**文件**: `src/Modules/Auth/Services/PasswordService.php`

**修复方案**: 改为 `pluck('password_history_id')`。

---

### BUG-014: MailerService SMTP 密码明文存储

**影响范围**: 租户级 SMTP 配置

**根因**: `TenantSetting::get($tenantId, 'mail', 'smtp_password', '')` 直接读取明文密码。SMTP 密码应该加密存储。

**文件**: `src/Modules/Infrastructure/Services/MailerService.php`

**修复方案**: 确认 TenantSetting 支持加密存取，或添加加密层。

---

### BUG-015: admin.php 和 api.php 路由权限粒度不一致

**影响范围**: Infrastructure 模块权限控制

**根因**: `api.php` 使用统一的 `setting.view`/`setting.update` 权限，而 `admin.php` 使用更细分的权限（如 `webhook.view`、`security.view`、`branding.view`）。两个路由文件的权限策略不一致，可能导致权限绕过。

**文件**:
- `src/Modules/Infrastructure/Routes/api.php`
- `src/Modules/Infrastructure/Routes/admin.php`

**修复方案**: 统一权限策略，或明确区分两个路由文件的用途。

---

### BUG-016: IdentifyTenant URL 参数注入

**影响范围**: 租户识别

**根因**: `?tenant_id=xxx` 允许任意用户通过 URL 参数指定租户 ID。如果中间件链中后续没有严格的权限校验，普通用户可能通过篡改 `tenant_id` 参数访问其他租户的数据。

**文件**: `src/Modules/Infrastructure/Http/Middleware/IdentifyTenant.php`

**修复方案**: 对普通 User 验证其是否属于该租户。

---

### BUG-017: OperatorService::acceptInvite 双重 Hash

**影响范围**: Operator 邀请接受流程

**根因**: `acceptInvite()` 手动使用 `Hash::make($password)`，但 Operator 模型 `'password' => 'hashed'` cast 会自动 hash。注册路径依赖 cast，邀请路径手动 hash，**可能导致邀请的 Operator 无法登录**。

**文件**: `src/Modules/Operator/Services/OperatorService.php`

**修复方案**: 移除 `Hash::make()`，直接赋值明文密码。

---

### BUG-018: OAuthCallback.vue 使用 GET 传递 code

**影响范围**: OAuth 回调

**根因**: 使用 GET 请求传递 OAuth code，code 在 URL 中会被浏览器历史和服务器日志记录。

**文件**: `resources/pages/public/views/OAuthCallback.vue`

**修复方案**: 改为 POST 请求传递 code。

---

### BUG-019: Token 存储在 localStorage

**影响范围**: 前端认证安全

**根因**: Login.vue 将 token 和用户信息直接存入 `localStorage`。localStorage 容易受到 XSS 攻击。

**文件**: `resources/pages/public/views/Login.vue`

**修复方案**: 使用 httpOnly cookie 或至少 sessionStorage。

---

### BUG-020: TenantController::suspend/activate 缺少租户归属检查

**影响范围**: 租户管理

**根因**: `suspend` 和 `activate` 方法没有调用 `ensureTenantAccessOrSuperAdmin`，任何有 `tenant.suspend` 权限的用户可以暂停任意租户。

**文件**: `src/Modules/User/Http/Controllers/TenantController.php`

**修复方案**: 添加 `ensureTenantAccessOrSuperAdmin` 调用。

---

## 三、低优先级问题（代码质量改进）

### BUG-021: HTTP 客户端不统一

**影响范围**: 前端代码风格

**根因**: Login.vue 使用原生 fetch，其他页面使用 axios。应统一 HTTP 客户端。

---

### BUG-022: Dashboard.vue email_verified 字段名不一致

**影响范围**: 用户仪表盘

**根因**: 后端返回 `email_verified_at`，前端检查 `email_verified`。字段名不一致可能导致逻辑错误。

---

### BUG-023: Profile.vue 缺少头像上传

**影响范围**: 用户资料管理

**根因**: 后端 `updateProfile` 支持 avatar 字段，但前端 Profile.vue 没有头像上传/修改功能。

---

### BUG-024: Security.vue 缺少"添加 MFA 设备"入口

**影响范围**: 安全设置

**根因**: 页面只显示已有的 MFA 设备，没有添加新设备的入口。

---

### BUG-025: notifications/Index.vue filterType 未实现

**影响范围**: 通知列表

**根因**: "按类型筛选"功能的 `filterType` ref 始终为空，功能未实现。

---

### BUG-026: FormRequest 类未被使用（User 模块）

**影响范围**: User 模块验证逻辑

**根因**: `StoreMemberRequest`、`StoreTenantRequest`、`UpdateTenantRequest` 已定义但未被控制器引用。

---

### BUG-027: UserSearchService SQL LIKE 通配符未转义

**影响范围**: 用户搜索

**根因**: `->where('name', 'like', "%{$query}%")` 中 LIKE 通配符 `%` 和 `_` 没有被转义。搜索 `_%` 会匹配所有记录。

---

### BUG-028: OAuth 服务静态方法设计

**影响范围**: OAuth 服务可测试性

**根因**: `SocialiteService`、`AlipayOAuthService` 等所有方法都是 `static`，无法通过依赖注入 mock 测试。`configureDriver()` 修改全局 config 存在并发风险（Octane 环境）。

---

### BUG-029: SAML Metadata 返回类型错误

**影响范围**: SSO 功能

**根因**: `samlMetadata()` 声明返回 `JsonResponse` 但实际返回 XML (`Content-Type: application/xml`)。

---

### BUG-030: BindSessionDomain Octane 风险

**影响范围**: 多域名场景

**根因**: 通过 `config()` 修改 `session.domain`。在 Laravel Octane 环境下，config 变更可能跨请求持久化。

---

## 四、统计

| 严重度 | 数量 |
|--------|------|
| 严重 | 8 |
| 中等 | 12 |
| 低 | 10 |
| **总计** | **30** |

---

## 五、修复优先级建议

### 第一批（影响核心功能）
1. BUG-001: OAuth role 字段 Bug
2. BUG-002: PasswordService 双重 Hash
3. BUG-004: Operator $incrementing
4. BUG-017: OperatorService 双重 Hash

### 第二批（安全相关）
5. BUG-005: 登录锁定形同虚设
6. BUG-006: IdentifyOperator 不阻断无效请求
7. BUG-007: Login.vue 开放重定向
8. BUG-008: MfaVerify.vue user_id 暴露
9. BUG-020: TenantController suspend/activate 缺少归属检查

### 第三批（代码质量）
10. BUG-003: OauthAccount 缺少 import
11. BUG-009: 控制器基类不一致
12. BUG-010: FormRequest 未使用
13. BUG-011: User 模型缺少关系定义
14. BUG-012: RbacService deleteRole 未清理
15. BUG-013: PasswordService cleanupHistory 错误主键
