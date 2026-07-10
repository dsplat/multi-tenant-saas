# Multi-Tenant SaaS Framework

Laravel 多租户 SaaS 基础框架 — 开箱即用的企业级项目骨架。

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-%5E8.3-777BB4)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-%5E13.0-FF2D20)](https://laravel.com)
[![Tests](https://img.shields.io/badge/tests-2269%20passed-brightgreen)](#)

[Docs](docs/README.md) | [User Manual (中文)](docs/zh/user-manual.md) | [Quickstart](docs/en/guides/quickstart.md) | [安全审计](docs/zh/security/security-audit.md)

---

## Quick Start

### Option A: Create Project + Select Modules

```bash
composer create-project dsplat/multi-tenant-saas my-app
cd my-app

# Install modules as needed
composer require dsplat/multi-tenant-saas-module-billing
composer require dsplat/multi-tenant-saas-module-ai
composer require dsplat/multi-tenant-saas-module-form
```

### Option B: Preset Initialization

```bash
composer create-project dsplat/multi-tenant-saas my-app
cd my-app
php artisan tenancy:init normal   # mini(6) / normal(14) / full(22)
composer update
```

### Environment & Database

```bash
cp .env.example .env
php artisan key:generate
# Edit .env: DB_*, ADMIN_DOMAIN

php artisan migrate
php artisan db:seed   # Creates platform tenant (ID: 9007199254740991)
```

---

## Key Features

- **Four-Layer Access**: System admin → Tenant admin → End user → Guest
- **Tenant Isolation**: Auto `WHERE tenant_id = ?` on all queries
- **RBAC**: 60+ permission nodes, custom roles per tenant
- **Global ID**: 16-digit random JS-safe IDs, no auto-increment
- **Multi-Payment**: WeChat, Alipay, PayPal, Stripe, UnionPay
- **AI Gateway**: Multi-provider (OpenAI/Claude/DeepSeek), Agent framework (8 templates)
- **22 Modules**: Billing, Auth, Form, Lottery, Voting, SMS, Coupon, Workflow, Conversation, etc.

---

## Module Management

```bash
# Install / Uninstall
composer require dsplat/multi-tenant-saas-module-billing
composer remove dsplat/multi-tenant-saas-module-lottery

# CLI
php artisan module:list
php artisan module:enable billing
php artisan module:disable ssl

# Create + publish new module
bin/module-publish demo --priority=500 --toggleable --description="Demo module"
```

Each module is an independent Composer package on Packagist. Push to `main` triggers GitHub Actions to auto-split and update Packagist.

| Package | Type |
|---|---|
| `dsplat/multi-tenant-saas` | Core framework |
| `dsplat/multi-tenant-saas-module-{name}` | 22 independent modules |

---

## Architecture

```
multi-tenant-saas/
├── app/Http/Controllers/Api/  # Core API controllers (22)
├── config/tenancy.php         # Framework config
├── src/
│   ├── Concerns/              # BelongsToTenant, HasGlobalId
│   ├── Context/               # TenantContext
│   ├── Contracts/             # 18 interfaces
│   ├── Modules/               # 22 modules (split to Packagist)
│   │   └── Contracts/         # ModuleServiceProvider base class
│   ├── Services/              # 94 core services + ModuleRegistry/Manager/Bootstrapper
│   └── TenancyServiceProvider.php
├── composer.json              # type: library, path repo for modules
└── bin/module-publish         # Module publish helper
```

**Boot flow:** `TenancyServiceProvider::boot()` → `ModuleBootstrapper` → scan `composer.json extra.saas` → topological sort → register & boot enabled modules.

---

## Docs

| Category | Links |
|---|---|
| **Guides** | [Quickstart (en)](docs/en/guides/quickstart.md) · [快速开始 (zh)](docs/zh/guides/quickstart.md) · [RBAC](docs/zh/guides/rbac-guide.md) · [AI Module](docs/zh/guides/ai-module-guide.md) |
| **Architecture** | [System Overview](docs/zh/architecture/system-overview.md) · [Tenant Isolation](docs/zh/architecture/tenant-isolation.md) · [Data Model](docs/zh/architecture/data-model.md) |
| **Deployment** | [Deployment Guide](docs/zh/deployment/deployment-guide.md) · [Nginx](docs/zh/deployment/nginx-guide.md) · [Monitoring](docs/zh/deployment/monitoring-alerting.md) |
| **API** | [API Overview](docs/zh/api/api-overview.md) · [Core API](docs/zh/api/core-api.md) · [OpenAPI Spec](docs/zh/api/openapi.yaml) |
| **Full Index** | [docs/README.md](docs/README.md) (bilingual) |

---

## Tech Stack

PHP ^8.3 · Laravel ^13.0 · MySQL 8.0+ · Redis · Nginx + PHP-FPM · Vue.js 3 + TypeScript + Vite

## Testing

```bash
composer test              # Parallel (~6s, 2269 tests, 5648 assertions)
composer test:sequential   # Single-thread fallback
```

## License

MIT
