# Changelog

## v2.7.0 (2026-07-17)

### Documentation & Screenshots

- **全面文档更新**: README、系统架构概览、用户手册、SPA构建部署指南、下游架构指南等 8 个文档文件更新
- **浏览器截图**: 8 张真实数据截图（Admin 仪表盘/租户/模块/运营人员/系统设置，Console 工作台/成员管理/租户设置）
- **core_version**: 配置版本升级至 2.7.0
- **模块列表**: 系统架构文档补充完整 26 个模块说明
- **目录结构**: 所有文档更新为 v2.6.0 新的 `ui/{element-plus,bootstrap}/` 隔离目录结构
- **技术栈**: 更新为 PHP ^8.3 / Laravel ^13.0 / Element Plus

### Stats

- 1 commit since v2.6.0
- Files changed: 16 (199 insertions, 115 deletions)
- Screenshots: 8 (all with real data)

---

## v2.6.0 (2026-07-16)

### UI Framework Directory Isolation

- **目录结构迁移**: Admin/Console 前端从 `resources/js/{admin,console}/views/` 迁移至 `resources/pages/{admin,console}/ui/{bootstrap,element-plus}/` 目录
- **模块视图隔离**: 各模块视图从 `src/Modules/*/resources/{admin,console}/views/` 迁移至 `src/Modules/*/resources/{admin,console}/ui/{bootstrap,element-plus}/views/`
- **双UI框架支持**: 同时支持 Bootstrap 和 Element Plus 两套 UI 框架，通过 Vite 配置动态切换
- **Vite 配置更新**: 适配新目录结构，root 指向项目根目录，input 指向 `resources/pages/*/index.html`
- **auto-imports**: 集成 `unplugin-auto-import` 和 `unplugin-vue-components`，Element Plus 组件按需自动导入

### Console 种子数据可见性修复

- **ensureTenantAccess**: 修复平台操作员被阻止访问平台租户数据的问题，允许 `scope=platform` 的操作员访问平台默认租户
- **consoleLogin 自动检测租户**: Console 登录时自动从 `operator_tenants` 表查找操作员的活跃映射，无需手动指定 X-Tenant-ID
- **绕过 TenantScope**: `OperatorTenant` 查询添加 `withoutGlobalScope(TenantScope::class)`，避免跨租户映射被全局作用域过滤
- **TenantUserResource**: `role` 字段从返回对象改为返回字符串 `$this->role?->name`，新增 `role_display_name` 字段
- **Bootstrap API.value 修复**: Members.vue 所有 axios 调用从 `API` 改为 `API.value`（Vue 3 ComputedRef）

### Admin 交互改进

- **Admin tenant store**: 自动选择第一个可用租户，无需手动选择
- **Console 登录页**: 移除租户 ID 输入字段，简化登录流程

### UI Redesign

- **Admin sidebar**: Modern design with SVG icons, section labels, system/tenant split
- **Console sidebar**: Matching design with green accent (#10b981)
- **Global tenant selector**: Top bar dropdown, localStorage persistence, tenant management section disabled when no tenant selected
- **Dark mode**: Full support via CSS variables on `:root` with `html.dark` overrides
- **Color picker**: Accent color flows through sidebar, logo, hover states, badges
- **Theme settings**: Light/Dark/Auto mode, 6 color presets, border radius slider

### Architecture

- **CSS variables on `:root`**: All theme variables globally available, `html.dark` overrides everything
- **Module auto-discovery**: `getModulePageEntries()` in `module-loader.ts` discovers `*.vue` files and renders sidebar entries under "模块" section
- **Vite config**: `axios` alias for module Vue files outside SPA directory
- **Ticket module**: Complete example module (migration → model → service → controller → routes → Vue page)

### Bug Fixes

- Console API paths: `/tenant/*` → `/api/v1/tenants/{tenantId}/*` (Credits, Members, Webhooks)
- Tenant selector: localStorage persistence type mismatch (`Number` vs `String`)
- Dashboard: data-table and panel h3 use explicit CSS variables for dark mode
- Console: Workflows/Webhooks output backgrounds use `--fill-color` variable
- Dark mode: headings, form elements, page-header, panel backgrounds all respond correctly
- All badge/link/table colors replaced with CSS variables (40 files, no hardcoded hex)

### Breaking Changes

- **目录结构变更**: `resources/js/{admin,console}/views/` → `resources/pages/{admin,console}/ui/{bootstrap,element-plus}/`
- **模块视图路径变更**: `src/Modules/*/resources/{admin,console}/views/` → `src/Modules/*/resources/{admin,console}/ui/{bootstrap,element-plus}/views/`
- CSS variables moved from `.admin-layout`/`.console-layout` scoped selectors to `:root` (global)
- Console layout now shares same variable names as Admin (`--sb`, `--tb`, `--pg`, etc.)
- Module Vue files need `axios` import (resolved via Vite alias, no action needed)

### Stats

- 24 commits since v2.5.0
- Tests: 2351 passed, 2 skipped
- Modules: 26 + Ticket example
- Files changed: 133 (+8,262 / -1,307)

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

### Infrastructure

- Feature Flags, IP Whitelist, Branding, System Settings, Tenant Keys, Retention Policies, Consents, SSO Providers, Credits, Sandbox pages added
- All pages use CSS variables for theming

### Stats

- 23 commits since v2.4.0
- Tests: 2351 passed, 0 failures

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

- Tests: 2351 passed, 0 failures
- Modules: 26 (including Contracts)
