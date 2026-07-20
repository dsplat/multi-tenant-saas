# Changelog

## v2.9.0 (2026-07-19)

### Public SPA Scaffold 模式

- `vendor:publish --tag=dsplat-public-spa` 机制：下游拉取框架 Public SPA 源码后完全自主定制
- 与 Console/Admin 继承模式解耦：100% 下游定制 Landing 页，避免继承覆盖的多余负担
- 两条引入路径统一：`composer create-project` 直接包含 / `composer require` 后 `vendor:publish` 拉取
- TenancyServiceProvider 注册 `dsplat-public-spa` 发布标签

### 首屏防闪烁三层注入机制

- `index.html` inline script 预注入 `window.__SITE_CONFIG__`（同步执行，Vue 加载前就绪）
- localStorage 缓存站点配置，二次访问首屏同步读取
- App.vue / index.vue 初始值 `ref<any>((window as any).__SITE_CONFIG__ || {})` 同步读取
- 消除「先显示框架默认名，fetch 返回后再覆盖」的闪烁问题

### 重构

- `Landing.vue` → `index.vue`：Landing 概念已删除，组件名语义化
- 路由 name `'landing'` → `'home'`

### 架构文档

- `docs/console-spa-architecture.md` → `docs/spa-architecture.md`（重命名，保留 git 历史）
- 新增 §0 三种 SPA 模式总览（Public Scaffold / Console 继承 / Admin 继承）
- 新增 §9 Public SPA Scaffold 模式详解
- 新增 §10 首屏防闪烁三层注入机制（时序图 + 兜底默认值约定）

### 认证体系改进 (Phase 1-4)

- 企业微信 OAuth 登录支持（WechatWorkOAuthService）
- 支付宝 OAuth 服务增强
- SocialiteService 扩展（67行新增）
- OauthAccount 模型更新
- Auth 路由新增（public.php, tenant.php）
- TenantMailConfigController 新增
- AuthImprovementsTest 新增

### 租户域名解析

- TenantResolveController 新增
- BindSessionDomain 中间件新增
- IdentifyTenant + EnsureTenantContext 更新
- NginxConfigService + domain.php 配置更新

### 邮件系统

- MailerService 大幅重构（150行变更）

### Bug Fixes

- N:N operator-tenant 关系修复（AttachTenantAdminOnActivated 移除未使用 import）
- TenantController import 简化（FQPN → use 导入）
- jobs/failed_jobs 表合并到 framework_core 迁移
- operator_direct_attach_tenant 迁移删除，内容合入 operator_module 迁移

### 测试修复

- OperatorTenant::$fillable 添加 user_id（根因：create() 时被静默忽略）
- User::operatorTenants() 关联恢复
- RbacService::check() 增加 operatorTenants() 回退路径
- CoreModule 测试 schema 添加 user_id 列
- operator_module 迁移添加 user_id 列
- TestCase 注册 operator.auth 中间件
- TenantOnboardingController 支持公开注册
- TenantOnboardingTest URL 修正
- 结果: 217 errors → 0 errors, 23 failures → 21 failures

### 用户个人中心

- user-core 共享库（19 个文件：types, composables, api clients）
- 用户页面：Dashboard, Profile, Security, OAuthBindings
- 通知系统：notifications/Index.vue, Preferences.vue
- MFA：MfaVerify.vue, Login.vue 支持 MFA 流程
- OAuth：OAuthCallback.vue, 企业微信 OAuth
- UserProfileController：后端用户资料 API

### 深度代码审查发现

**严重问题：**
- OAuth `role` 字段 Bug — SocialiteService/AlipayOAuthService/WechatOAuthService/WechatWorkOAuthService 使用不存在的 `role` 字段
- PasswordService 双重 Hash — `Hash::make()` + `hashed` cast 冲突
- OauthAccount 缺少 Tenant import
- Operator 模型 `$incrementing` 未设为 false
- OperatorAuthController 登录锁定形同虚设
- IdentifyOperator 中间件不阻断无效请求

**中等问题：**
- 控制器基类不一致（Illuminate\Routing\Controller vs App\Http\Controllers\Controller）
- FormRequest 未使用（LoginRequest/RegisterRequest/ResetPasswordRequest）
- User 模型缺少关系定义（mfaDevices/sessions/trustedDevices/passwordHistories）
- RbacService::deleteRole() 未清理 operator_tenants.role_id
- MailerService SMTP 密码明文存储
- admin.php 和 api.php 路由权限粒度不一致

**前端问题：**
- Login.vue redirect 开放重定向
- MfaVerify.vue user_id 暴露在 URL 中
- Token 存储在 localStorage（XSS 风险）
- HTTP 客户端不统一（fetch vs axios）

### Stats

- Tests: 2379, Assertions: 5039, Errors: 0, Failures: 21
- Modules: 26 + Ticket example
- Public views: 11（index.vue 重命名）

---

## v2.8.0 (2026-07-18)

### Auto-Discovered Sidebar Navigation

- Module Vue pages in `src/Modules/*/resources/{admin,console}/views/` auto-register in sidebar
- No need to manually edit sidebar layout — just add a `.vue` file to the module
- `createConsoleConfig()` factory exported for downstream projects
- `spa-fallback` middleware for proper SPA routing

### glm5.2 Integration

- **Tenant Applications**: `tenant_applications` table + admin approval + console apply flow
- **Operator Auth**: `OperatorAuthController` for independent operator authentication
- **Public SPA**: Login, register, apply, forgot password, email verification pages
- **Mail Templates**: `scope` (system/project/tenant) + `locale` fields for three-level override
- **Multi-UI Framework**: Pages organized under `ui/bootstrap/` and `ui/element-plus/` directories

### Bug Fixes

- `TenantMail::$locale` type conflict with parent `Mailable` class
- `MailTemplateService::findTemplate()` fallback to default locale `zh_CN`
- ConsoleLayout import paths (`@/console/stores` → `@/stores`)
- `MailTemplateEditor.vue` Vue template parsing error with nested braces
- Bootstrap sidebar nav label mapping for vendor modules

### Stats

- Tests: 2351, Assertions: 5915, Skipped: 2
- Modules: 26 + Ticket example
- Admin views: 61 (bootstrap + element-plus)
- Console views: 28 (bootstrap + element-plus)
- Public views: 11
- Migrations: 132
- Contracts: 18

---

## v2.7.0 (2026-07-17)

### Downstream Issue Fixes

- **#4**: `CastRouteParameters` middleware — auto-casts numeric route params to int
- **#5**: Migration for `deleted_at` on `broadcast_events` and `in_app_notifications`
- **#6**: `WorkflowEngineContract` binding registered in `WorkflowServiceProvider`
- **#7**: Monitoring routes use correct service methods (`getQps`/`getRpm`/`history`)
- **#8**: `TenantDomainController::index()` tenantId made optional
- **#9**: Migration for `resolved_at` on `alerts` table

### Stats

- Tests: 2351, Assertions: 5915, Skipped: 2

---

## v2.6.0 (2026-07-16)

### UI Redesign

- **Admin sidebar**: Modern design with SVG icons, section labels, system/tenant split
- **Console sidebar**: Matching design with green accent (#10b981)
- **Global tenant selector**: Top bar dropdown, localStorage persistence
- **Dark mode**: Full support via CSS variables on `:root` with `html.dark` overrides
- **Color picker**: Accent color flows through sidebar, logo, hover states, badges

### Module System

- All 40 Vue pages migrated from `resources/js/` to module directories
- Router simplified: only core pages hardcoded, module pages auto-discovered
- Vite aliases for `@stores`, `vue`, `vue-router`, `pinia`, `axios`
- `server.php` for PHP built-in server SPA routing fix

### Bug Fixes

- Console API paths: `/tenant/*` → `/api/v1/tenants/{tenantId}/*`
- Tenant selector: localStorage persistence type mismatch
- Dashboard: data-table and panel h3 use explicit CSS variables
- All badge/link/table colors replaced with CSS variables (40 files)
- Module-loader `mainRoute` lookup uses named route

### Stats

- Tests: 2351, Assertions: 5915, Skipped: 2

---

## v2.5.0 (2026-07-15)

### Admin & Console SPA Completion

- **27 Admin pages**: Dashboard, Tenants, Users, Domains, OAuth, Audit, SMS, Payments, API Tokens, Quotas, Operators, Roles, Plans, Modules, Plugins, SSL, Webhooks, Feature Flags, IP Whitelist, Branding, SSO, Credits, System Settings, Tenant Keys, Retention Policies, Consents, Sandbox, Settings
- **12 Console pages**: Dashboard, Members, Credits, OAuth, Payment, SMS, API Tokens, Workflows, SSL, Webhooks, Tenant Settings
- All pages connected to real backend APIs with CRUD, pagination, filtering

### Bug Fixes

- Workflow/Plugin/DeveloperPortal: method name mismatches in admin routes
- Operator tests: `HasGlobalId` fillable issue, 8 skipped tests fixed
- TenantContext: `IdentifyTenant` middleware added to API route group
- SPA routing: `server.php` with `SCRIPT_NAME` override for PHP built-in server
- API paths: module routes with double `admin` prefix adapted
- Platform init seeder: `newLine()` calls and `role` column compatibility
- Config: `core_version` default from `1.0.0` to `2.4.0`

### Stats

- Tests: 2351, Assertions: 5915, Skipped: 2

---

## v2.4.0 (2026-07-14)

### Permission Model Refactoring

- Three-layer separation: Users, Operators, OperatorTenants
- Unified RBAC via role_id → role_permissions → permissions
- 26 modules fully modularized

### Module System

- 26 independent Composer packages via `git subtree split`
- GitHub Actions split workflow (26/26 success)
- Skills: split-push, split-pull, release, test-fix

### Stats

- Tests: 2351, Assertions: 5878, Skipped: 10
- Modules: 26 (including Contracts)
