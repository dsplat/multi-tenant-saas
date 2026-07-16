# Changelog

## v2.6.0 (2026-07-16)

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

- CSS variables moved from `.admin-layout`/`.console-layout` scoped selectors to `:root` (global)
- Console layout now shares same variable names as Admin (`--sb`, `--tb`, `--pg`, etc.)
- Module Vue files need `axios` import (resolved via Vite alias, no action needed)

### Stats

- 23 commits since v2.5.0
- Tests: 2351 passed, 2 skipped
- Modules: 26 + Ticket example

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
