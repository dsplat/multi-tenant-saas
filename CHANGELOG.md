# Changelog

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
