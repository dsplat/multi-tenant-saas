# Quick Start

## Installation

```bash
# Create project
composer create-project dsplat/multi-tenant-saas my-app
cd my-app

# Install modules as needed
composer require dsplat/multi-tenant-saas-module-billing
composer require dsplat/multi-tenant-saas-module-ai
composer require dsplat/multi-tenant-saas-module-form
```

Or use preset initialization:

```bash
php artisan tenancy:init normal   # mini(6) / normal(14) / full(22)
composer update
```

## Environment Setup

```bash
cp .env.example .env
php artisan key:generate
# Edit .env: DB_*, ADMIN_DOMAIN

php artisan migrate
php artisan db:seed   # Creates platform tenant
```

## Next Steps

- [User Manual](../user-manual.md) — Full feature documentation
- [RBAC Guide](rbac-guide.md) — Permission configuration
- [Billing Config](billing-config.md) — Subscription setup
- [AI Module Guide](ai-module-guide.md) — AI capabilities
