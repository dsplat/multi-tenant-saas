# Multi-Tenant SaaS Framework

Laravel 多租户 SaaS 基础框架 — 开箱即用的企业级项目骨架。

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-%5E8.3-777BB4)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-%5E13.0-FF2D20)](https://laravel.com)
[![Tests](https://img.shields.io/badge/tests-2351%20passed-brightgreen)](#)

[Docs](docs/README.md) | [Quickstart](docs/zh/guides/quickstart.md) | [SPA Architecture](docs/console-spa-architecture.md) | [CHANGELOG](CHANGELOG.md)

---

## Key Features

- **Four-Layer Access**: System admin → Tenant admin → End user → Guest
- **Tenant Isolation**: Auto `WHERE tenant_id = ?` on all queries
- **RBAC**: 60+ permission nodes, custom roles per tenant
- **SPA Backends**: 27 Admin pages + 12 Console pages with dark mode + theme system
- **Module Auto-Discovery**: Vue pages in `src/Modules/*/resources/{admin,console}/views/` auto-register in sidebar
- **Multi-UI Framework**: Bootstrap + Element Plus variants for every page
- **26 Modules**: Billing, Auth, Form, Lottery, Voting, SMS, Coupon, Workflow, Conversation, etc.
- **18 Contracts**: Interface-driven architecture for downstream customization

---

## Quick Start

```bash
composer create-project dsplat/multi-tenant-saas my-app
cd my-app

cp .env.example .env
php artisan key:generate
# Edit .env: DB_*, ADMIN_DOMAIN

php artisan migrate
php artisan platform:init --email=admin@example.com --password=your-password

# Build SPAs
cd resources/js/admin && npm install && npx vite build && cd ../../..
cd resources/js/console && npm install && npx vite build && cd ../../..

php artisan serve
```

---

## SPA Backends

### Admin (系统后台) — 27 pages

| Group | Pages |
|-------|-------|
| 概览 | 仪表盘, 租户管理, 运营人员, 角色权限, 订阅计划 |
| 平台配置 | 模块管理, 插件管理, 功能开关, 品牌配置, SSO, 系统设置, 数据保留, 沙箱, 配置中心 |
| 租户管理 | 用户, 域名, OAuth, 审计, 短信, 支付, Token, 配额, 积分, SSL, Webhooks, IP白名单, 租户密钥, 合规 |

### Console (租户后台) — 12 pages

| Group | Pages |
|-------|-------|
| 概览 | 工作台 |
| 团队与财务 | 成员管理, 积分管理 |
| 集成与配置 | 第三方登录, 支付配置, 短信配置, API Token |
| 自动化与安全 | 工作流, SSL 证书, Webhooks |
| 设置 | 邮件/认证/注册 |

### Theme System

- Light/Dark mode toggle
- Color picker (accent color flows through all UI)
- CSS variables on `:root` with `html.dark` overrides
- All badge/link/table colors use CSS variables

---

## Module Architecture

```
src/Modules/{Name}/
├── {Name}ServiceProvider.php    ← extends ModuleServiceProvider
├── composer.json                ← extra.saas config
├── Http/Controllers/
├── Services/
├── Models/
├── Routes/
│   ├── api.php                  → /api/v1/...  (auth + tenant)
│   ├── admin.php                → /v1/admin/... (auth)
│   └── tenant.php               → /tenant/... (auth)
└── resources/
    ├── admin/views/*.vue        → auto-discovered by sidebar
    └── console/views/*.vue      → auto-discovered by sidebar
```

**See `src/Modules/Ticket/` for a complete working example.**

---

## Docs

| Category | Links |
|----------|-------|
| **Guides** | [Quickstart](docs/zh/guides/quickstart.md) · [RBAC](docs/zh/guides/rbac-guide.md) · [AI Module](docs/zh/guides/ai-module-guide.md) |
| **Architecture** | [System Overview](docs/zh/architecture/system-overview.md) · [SPA Architecture](docs/console-spa-architecture.md) · [Tenant Isolation](docs/zh/architecture/tenant-isolation.md) |
| **Deployment** | [Deployment Guide](docs/zh/deployment/deployment-guide.md) · [Nginx](docs/zh/deployment/nginx-guide.md) |
| **API** | [API Overview](docs/zh/api/api-overview.md) · [Core API](docs/zh/api/core-api.md) |
| **Full Index** | [docs/README.md](docs/README.md) |

---

## Tech Stack

PHP ^8.3 · Laravel ^13.0 · MySQL 8.0+ · Redis · Nginx + PHP-FPM · Vue.js 3 + TypeScript + Vite

## Testing

```bash
composer test              # Parallel (~50s, 2351 tests, 5915 assertions)
composer test:sequential   # Single-thread fallback
vendor/bin pint --test     # Code style check
```

## License

MIT
