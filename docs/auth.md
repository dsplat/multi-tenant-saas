# 认证与权限体系

> **文档性质**: 系统现状权威描述（代码即文档）
> **最后更新**: 2026-07-19
> **关联文档**: `docs/tenant.md`（租户体系）、`docs/auth_plan.md`（改进计划历史）

---

## 一、双账户体系

系统存在两种完全独立的身份：

| 维度 | Operator（运营者） | User（终端用户） |
|---|---|---|
| 数据表 | `operators` | `users` |
| 含义 | 平台管理员 / 租户管理员 / 团队成员 | 租户的终端业务用户 |
| 关联租户 | `operator_tenants`（N:N，含 role_id） | `tenant_users`（N:N，含 role_id） |
| Token | Sanctum `tokenable_type=Operator::class` | Sanctum `tokenable_type=User::class` |
| 登录入口 | `/api/v1/operator-auth/login` 或 `/console/auth/login` | `/api/v1/auth/login` |
| 权限模型 | RBAC（`operator_tenants.role_id` → `roles` → `permissions`） | RBAC（`tenant_users.role_id` → `roles` → `permissions`） |

**核心约束**：同一邮箱可同时存在于 `operators` 和 `users` 表，两个 token 互不通用。

---

## 二、三通道登录

| 通道 | 域名/路径 | API 端点 | 查询表 | 身份 |
|---|---|---|---|---|
| 平台管理后台 | `admin.scrm.com` | `POST /api/v1/admin/auth/login` | `operators` (scope=platform) | 超管 |
| 租户控制台 | 任意域名 `/console/*` | `POST /api/v1/console/auth/login` | `operators` + `operator_tenants` | 租户管理员/团队成员 |
| 用户前端 | 任意域名 `/` | `POST /api/v1/auth/login` | `users` + `tenant_users` | 终端用户 |

另有通用 Operator 登录（不区分域名）：`POST /api/v1/operator-auth/login`

---

## 三、中间件栈

```
HTTP 请求
  → Nginx（catch-all + X-Original-Host + 域名白名单 map）
  → AddSecurityHeaders
  → IdentifyDomain（识别 domain_type: admin/console/api/app）
  → BindSessionDomain（session.domain = 当前 host，生产强制 secure+lax）
  → IdentifyTenant（7 级优先级解析 tenant_id）
  → IdentifyOperator（Operator token 解析）
  → [路由级] EnsureTenantContext（无租户 → 403）
  → [路由级] auth:sanctum
  → [路由级] rbac.permission:xxx
  → Controller
```

### IdentifyTenant 7 级优先级

1. URL 参数 `?tenant_id=` / `?tid=`
2. Header `X-Tenant-ID`（非 Operator）
3. 自定义域名 `tenants.custom_domain` 精确匹配
4. Cookie `tenant_id`
5. Session `tenant_id`
6. 认证用户（Operator → `operator_tenants`；User → `current_tenant_id`）
7. 通配子域名 `*.{wildcard_base}` → 兜底默认租户；其他未识别域名 → null → 403

---

## 四、OAuth 第三方登录

### 4.1 Provider 分类

| 类型 | Provider | 实现方式 | 配置来源 |
|---|---|---|---|
| 标准 Socialite | wechat / dingtalk / feishu / github / google | `SocialiteService` + Socialite 驱动 | `tenant_settings` group=oauth |
| 独立 API | alipay | `AlipayOAuthService`（RSA2 签名） | `tenant_settings` group=oauth |
| 独立 API | wechat_work（企业微信） | `WechatWorkOAuthService`（corp_id/agent_id/secret） | `tenant_settings` group=oauth |
| SSO | saml / oidc | `SsoService`（726 行，含签名校验/JIT/属性映射） | `sso_providers` 表 |

### 4.2 Provider 命名空间化

`oauth_accounts.provider` 字段格式：

```
{base_provider}:tenant:{tenantId}
```

示例：`wechat_work:tenant:1001`、`alipay:tenant:42`、`saml:okta:tenant:1001`

**目的**：防止同一 OAuth 应用在不同租户间串扰。

### 4.3 State 防重放（无 Session）

采用 **Cache 一次性 token** 方式（`ManagesOAuthState` trait）：

1. redirect 阶段：生成 40 位随机 state → `Cache::put("oauth_state:{provider}:{tenantId}:{sha256(state)}", true, 600)`
2. callback 阶段：验证 Cache key 存在 → `Cache::forget()`（一次性使用）
3. 不依赖 Session/Cookie，纯 API 架构

### 4.4 用户创建/绑定策略

1. 按 `(provider, provider_id)` 查 `oauth_accounts` → 命中返回
2. 按 `email` 查 `users` → 命中绑定 oauth_account
3. 创建新 User + TenantUser（role=end_user）+ OauthAccount（JIT 加入）

### 4.5 公开路由

```
GET /api/v1/auth/{provider}/redirect    → 获取授权 URL（throttle:30,1）
GET /api/v1/auth/{provider}/callback    → 处理回调（throttle:30,1）
GET /api/v1/auth/sso/{provider}/redirect → SSO 重定向
GET /api/v1/auth/sso/{provider}/callback → SSO 回调
```

---

## 五、RBAC 权限模型

### 5.1 数据模型

```
roles (role_id, name, display_name, scope, tenant_id)
  ├── scope=platform → 平台级角色（super_admin, platform_operator）
  └── scope=tenant   → 租户级角色（tenant_admin, team_member, end_user）

permissions (permission_id, name, display_name, group)
  └── 如: setting.update, member.manage, billing.view

role_permissions (role_id, permission_id)

operator_tenants (operator_id, tenant_id, role_id, is_active)
tenant_users (user_id, tenant_id, role_id, is_active)
```

### 5.2 权限检查路径（RbacService::check）

```
当前用户是 Operator?
  → operator_tenants.where(tenant_id).role_id → role_permissions → 匹配权限
当前用户是 User?
  → tenant_users.where(tenant_id).role_id → role_permissions → 匹配权限
```

### 5.3 中间件

- `rbac.permission:setting.update` — 路由级权限检查（`CheckRbacPermission`）
- `tenant.ensure` — 强制租户上下文存在
- `operator.auth` — 强制 Operator 身份

---

## 六、典型业务流程

### 6.1 租户注册（Onboarding）

```
1. 访问平台官网 → 点击"创建团队"
2. POST /api/v1/operator-auth/register（name, email, password）
   → 创建 Operator（scope=tenant）
   → 异步发送验证邮件（SendOperatorVerificationJob）
3. 验证邮箱 → POST /api/v1/operator-auth/verify-email
4. 登录 → POST /api/v1/operator-auth/login → 获取 operator_token
5. 创建租户 → POST /api/v1/operator/tenants（name, slug）
   → 创建 Tenant + OperatorTenant（role=tenant_admin）
   → tenants.onboarding_operator_id = operator_id
6. 配置域名/品牌/OAuth → 进入租户控制台
```

### 6.2 团队成员邀请

```
1. 租户管理员登录控制台
2. POST /api/v1/operator/invites（email, role_id）
   → 创建邀请记录
   → 发送邀请邮件
3. 被邀请人点击邮件链接 → POST /api/v1/operator/accept-invite
   → 创建 Operator（若不存在）+ OperatorTenant（role_id=指定角色）
```

### 6.3 平台管理后台（Admin）

```
域名: admin.scrm.com
登录: POST /api/v1/admin/auth/login → operators(scope=platform)
功能: 租户管理、平台配置、Operator 管理、全局监控
权限: super_admin 角色拥有所有 platform scope 权限
```

### 6.4 租户控制台（Console）

```
域名: 任意租户域名 + /console 路径
登录: POST /api/v1/console/auth/login → operators + operator_tenants 验证
功能: 成员管理、OAuth/SSO 配置、SMTP 配置、品牌设置、RBAC 角色管理
权限: 由 operator_tenants.role_id 决定（tenant_admin / team_member）
```

### 6.5 用户前端（App SPA）

```
域名: 租户自定义域名 / 平台二级域名 / 平台参数隔离 URL
登录: POST /api/v1/auth/login → users + tenant_users
      或 OAuth: GET /api/v1/auth/{provider}/redirect → callback → token
功能: 终端业务功能（SCRM 客户管理、营销、内容等）
权限: 由 tenant_users.role_id 决定（end_user / 自定义角色）
```

前端 SPA 加载时的初始化流程：
```
1. GET /api/v1/tenant/resolve?domain={当前host} → 获取 tenant_id、品牌信息
2. GET /api/v1/tenant/login-config?domain={host} → 获取可用登录方式
3. 动态渲染：邮箱/密码表单 + OAuth 按钮 + SSO 按钮 + 租户品牌（Logo/颜色）
4. 登录成功 → 存储 Bearer token → 后续 API 带 Authorization + X-Tenant-ID
```

---

## 七、邮件体系

### 7.1 租户级 SMTP

`MailerService` 统一发送入口：
- 租户配置了 `tenant_settings(group=mail, key=smtp_host)` → 使用租户 SMTP（EsmtpTransport 运行时构建）
- 未配置 → 使用全局 SMTP
- **无 fallback**：租户 SMTP 发送失败即失败，不回退全局

### 7.2 配置管理 API

```
GET  /api/v1/tenant/auth/mail/config   → 获取 SMTP 配置（密码遮罩）
PUT  /api/v1/tenant/auth/mail/config   → 更新配置（smtp_password 加密存储）
POST /api/v1/tenant/auth/mail/test     → 发送测试邮件
```

### 7.3 邮件模板

`TenantMail` Mailable + `MailTemplateService` 模板驱动：
- 支持类型：welcome / reset / verification / invitation / billing / notification
- 三级覆盖：框架默认 → 租户自定义模板 → 内联数据
- 品牌注入：Logo、主题色、租户名称

---

## 八、安全机制

| 机制 | 实现 |
|---|---|
| MFA（TOTP/Email/SMS） | `MfaService` + `MfaController`，可信设备 + 恢复码 |
| 密码策略 | `PasswordPolicyService`（最小长度/复杂度/过期） |
| 密码历史 | `PasswordHistory`（禁止重复使用最近 N 个） |
| 登录日志 | `LoginLogService`（IP/UA/时间/结果） |
| 会话管理 | `SessionService` + `UserSession`（可吊销） |
| 账户锁定 | 连续失败 N 次锁定 M 分钟 |
| OAuth state | Cache 一次性 token（10 分钟 TTL，防重放） |
| 域名白名单 | Nginx map 精确匹配 + 通配正则 |
| 未识别域名 | 403 拒绝（不兜底） |
| Session Cookie | 绑定完整 host + 生产环境 secure + same_site=lax |

---

## 九、代码索引

### 中间件

| 文件 | 职责 |
|---|---|
| `src/Modules/Infrastructure/Http/Middleware/IdentifyDomain.php` | 域名类型识别 |
| `src/Modules/Infrastructure/Http/Middleware/BindSessionDomain.php` | Session Cookie 域名绑定 |
| `src/Modules/Infrastructure/Http/Middleware/IdentifyTenant.php` | 租户识别（7 级优先级） |
| `src/Modules/Infrastructure/Http/Middleware/EnsureTenantContext.php` | 强制租户上下文（403） |
| `src/Modules/Auth/Http/Middleware/CheckRbacPermission.php` | RBAC 权限检查 |

### 控制器

| 文件 | 职责 |
|---|---|
| `src/Modules/Auth/Http/Controllers/AuthController.php` | User 登录/注册/SSO |
| `src/Modules/Operator/Http/Controllers/OperatorAuthController.php` | Operator 注册/登录/验证 |
| `src/Modules/Auth/Http/Controllers/TenantOAuthController.php` | 租户 OAuth 配置 + redirect/callback |
| `src/Modules/Auth/Http/Controllers/TenantMailConfigController.php` | 租户 SMTP 配置 |
| `src/Modules/Domain/Http/Controllers/TenantResolveController.php` | 公开租户发现 API |

### 服务

| 文件 | 职责 |
|---|---|
| `src/Modules/Auth/Services/SocialiteService.php` | 标准 OAuth（7 个 provider） |
| `src/Modules/Auth/Services/WechatWorkOAuthService.php` | 企业微信独立 OAuth |
| `src/Modules/Auth/Services/AlipayOAuthService.php` | 支付宝独立 OAuth（RSA2） |
| `src/Modules/Auth/Services/SsoService.php` | SAML 2.0 + OIDC SSO |
| `src/Modules/Auth/Services/RbacService.php` | RBAC 权限检查 |
| `src/Modules/Auth/Services/MfaService.php` | MFA 多因素认证 |
| `src/Modules/Infrastructure/Services/MailerService.php` | 邮件发送（租户级 SMTP） |
| `src/Modules/Infrastructure/Services/TenantSettingService.php` | 租户配置存储 |

### 关键模型

| 文件 | 说明 |
|---|---|
| `src/Modules/Operator/Models/Operator.php` | 运营者身份 |
| `src/Modules/Auth/Models/User.php` | 终端用户 |
| `src/Modules/Auth/Models/OauthAccount.php` | OAuth 账号（provider 命名空间化） |
| `src/Modules/Infrastructure/Models/Tenant.php` | 租户 |
| `src/Modules/Infrastructure/Models/TenantSetting.php` | 租户配置（group/key/value） |

---

## 十、配置索引

| 配置文件 | 关键项 |
|---|---|
| `config/tenancy.php` | `platform_domains`、`default_tenant_id`、`cache.ttl` |
| `src/Modules/Domain/Config/domain.php` | `platform_domains.admin/app`、`wildcard_base`、`nginx_map_file` |
| `config/sanctum.php` | token 过期、stateful domains |
| `config/session.php` | domain=null（由 BindSessionDomain 运行时覆盖） |
