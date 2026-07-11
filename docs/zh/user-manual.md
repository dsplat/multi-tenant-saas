# Multi-Tenant SaaS Framework — User Manual

> 详细使用手册。快速开始请参考 [README.md](../README.md) 或 [Quickstart Guide](guides/quickstart.md)。

---

## Table of Contents

- [Core Features](#core-features)
- [Module System](#module-system)
- [Service Reference (174 services)](#service-reference)
- [Model Reference (120+ models)](#model-reference)
- [Middleware Reference](#middleware-reference)
- [Code Examples](#code-examples)
- [Testing](#testing)
- [Tech Stack & Integrations](#tech-stack)

---

## Core Features

### Four-Layer Access Architecture

| Layer | Domain Example | Path | Role | Description |
|---|---|---|---|---|
| System Admin | `admin.lyt.com` | `/*` | `super_admin` | Separate domain, brute-force safe |
| Tenant Admin | `ai.lyt.com` | `/console/*` | `tenant_admin` | Tenant management panel |
| End User | `ai.tenant1.local` | `/*` | `end_user` | Tenant custom domain |
| Guest | Same as end user | `/*` | Not logged in | Login state differentiation |

### Tenant Isolation

- **Global Scope**: Auto-adds `WHERE tenant_id = ?` to all queries
- **Auto-fill**: Auto-fills `tenant_id` on record creation
- **Transparent**: Business code doesn't need to handle tenant isolation

```php
Order::all();
// SQL: SELECT * FROM orders WHERE tenant_id = 123456

Order::create(['name' => 'New Order']);
// Auto-sets tenant_id = current tenant
```

### Multi-Domain Support

- Single domain mode: path-based (`/console/*`, `/api/*`)
- Multi-domain mode: tenant custom domains
- Domain whitelist: auto-managed Nginx config
- SSL certificates: custom domain SSL management

### RBAC Permissions

- Platform roles: `super_admin`, `platform_user`
- Tenant roles: `tenant_admin`, `end_user`
- Fine-grained: 60+ permission nodes (`tenant.view`, `member.create`, etc.)
- Custom roles: tenants can create roles with specific permissions
- Middleware: `rbac.permission:permission.name`

### Global Unique ID

- 16-digit random numbers, JS-safe (`<= Number.MAX_SAFE_INTEGER`)
- Globally unique, shared ID space across all tables
- Completely unordered, no business growth inference

### Credit/Quota Management

- Tenant-level credit accounts
- User-level credit accounts
- Expiration & reminders
- Quota checks and limits
- Transaction history

### Subscription Management

- Plans: free / basic / pro / enterprise
- Monthly/yearly pricing
- Trial period management
- Subscription history
- Upgrade/downgrade/cancel

### Multi-Payment Gateway

| Method | Driver | Notes |
|---|---|---|
| WeChat Pay | `wechat` | via yansongda/pay |
| Alipay | `alipay` | via yansongda/pay |
| PayPal | `paypal` | Independent service |
| Stripe | `stripe` | Independent service |
| UnionPay | `unionpay` | Independent service |

- Unified `createOrder` / `refund` / `handleWebhook` interface
- Payment security logs
- Tenant-level payment config

### Third-Party Login

WeChat (WeCom), DingTalk, Feishu, Alipay — each tenant has independent OAuth config.

### File Storage

Multi-disk (local/s3/oss), signed URL sharing, storage quota, image preview.

### Notification Center

- In-app notifications (Laravel Notification)
- Notification preferences
- 5 built-in types: low balance, payment success, subscription expiry, tenant suspended, generic

### Audit Logging

Auto-records key operations, custom audit events, tenant-isolated queries.

### Internationalization

- `zh_CN` and `en` bilingual
- 14 language files covering all business modules
- `SetLocale` middleware auto-sets locale

### Monitoring & Operations

- Health checks: spatie/laravel-health integration
- Structured logging: tenant/user context
- Alert system: threshold monitoring + rules
- Performance monitoring: PerformanceService
- Queue monitoring: Horizon integration

### Advanced Features

- API version management with deprecation notices
- Plugin system: tenant-level plugin install/management
- Rate limiting: configurable API throttle rules
- Export tasks: Excel/PDF async export
- Payment security: payment password + logs

### Mailer Service

Centralized email sending via `MailerService`. All emails go through `TenantMail` for template rendering + branding injection.

```php
use MultiTenantSaas\Services\MailerService;

$mailer = app(MailerService::class);

// Template-driven (recommended)
$mailer->sendTemplate('user@example.com', 'welcome_registration', [
    'user_name' => 'John',
    'platform_name' => 'MyApp',
]);

// Direct HTML
$mailer->sendRaw('user@example.com', 'Subject', '<p>Content</p>');

// MFA code
$mailer->sendMfaCode('user@example.com', '123456');

// Test email
$mailer->sendTest('admin@example.com');
```

**Template system:** 6 preset templates (welcome, reset, billing, notification) stored in `mail_templates` table. Tenant-specific overrides with system default fallback. Variable substitution via `{{variable_name}}`.

**Branding:** `TenantMail` auto-injects tenant logo, brand colors, platform name.

**Scheduled check:** `mailer:health-check` runs daily at 05:00.

**Config:** `config/tenancy.php` → `mail_templates.default_from_address`, `mail_templates.default_from_name`.
- Swagger/OpenAPI: auto-generated API docs

### Full-Text Search

Centralized search via `SearchService` + `Searchable` trait. LIKE backend with proper wildcard escaping.

```php
use MultiTenantSaas\Services\SearchService;

$search = app(SearchService::class);

// Direct search
$results = $search->search(User::query(), 'keyword', ['name', 'email'])->get();

// With pagination
$results = $search->searchModels(User::class, 'keyword', ['name', 'email'], 20);

// FULLTEXT (MySQL only, falls back to LIKE)
$results = $search->fulltext(User::query(), 'keyword', ['name', 'email'])->get();
```

**Searchable trait (recommended):**

```php
use MultiTenantSaas\Concerns\Searchable;

class User extends Model
{
    use Searchable;
    protected array $searchable = ['name', 'email', 'phone'];
}

// Usage
$users = User::search('keyword')->paginate();
```

**Config:** `config/tenancy.php` → `search.backend` (like/fulltext), `search.per_page`.

### Backup & Restore

Tenant-level backup via `BackupService`. Exports all tenant data as compressed JSON.

```bash
# Backup single tenant
php artisan backup:run --tenant=1001

# Backup all active tenants
php artisan backup:run

# List backups
php artisan backup:list

# Restore from backup
php artisan backup:restore backups/tenant_1001/backup_tenant_1001_20260711_020000.json.gz --confirm

# Restore to different tenant
php artisan backup:restore path/to/backup.json.gz --tenant=2002 --confirm
```

**Config:** `config/tenancy.php` → `backup.disk`, `backup.keep_days`, `backup.tables`.

**Scheduled:** Auto-backup runs daily at 02:00 via SchedulerService.

### Image Processing

Image manipulation via `ImageService` (PHP GD, no external dependencies).

```php
use MultiTenantSaas\Services\ImageService;

$image = app(ImageService::class);

// Resize (aspect ratio preserved)
$path = $image->resize('/path/to/image.jpg', width: 800);
$path = $image->resize('/path/to/image.jpg', height: 600);
$path = $image->resize('/path/to/image.jpg', 800, 600);

// Crop
$path = $image->crop('/path/to/image.jpg', 200, 200, x: 50, y: 50);

// Thumbnail (center crop + resize)
$path = $image->thumbnail('/path/to/image.jpg', 150, 150);

// Get dimensions
$dims = $image->getDimensions('/path/to/image.jpg');
// ['width' => 1920, 'height' => 1080, 'type' => 'jpeg']
```

### Authentication

Complete auth system via Auth module. Controllers, routes, and services.

**Public endpoints (no auth required):**

| Method | URI | Description |
|--------|-----|-------------|
| POST | `/api/v1/auth/login` | Email + password login (throttle: 5/min) |
| POST | `/api/v1/auth/register` | User registration (throttle: 3/min) |
| POST | `/api/v1/auth/forgot-password` | Send reset email (throttle: 3/min) |
| POST | `/api/v1/auth/reset-password` | Reset password with token |
| POST | `/api/v1/auth/verify-email` | Email verification |
| POST | `/api/v1/auth/resend-verification` | Resend verification email |
| GET | `/api/v1/auth/sso/{provider}/redirect` | SSO redirect URL |
| GET | `/api/v1/auth/sso/{provider}/callback` | SSO callback |

**Authenticated endpoints:**

| Method | URI | Description |
|--------|-----|-------------|
| GET | `/api/v1/auth/me` | Current user info |
| POST | `/api/v1/auth/logout` | Revoke token |
| POST | `/api/v1/auth/mfa/verify` | MFA challenge verification |

**MFA management (authenticated):**

| Method | URI | Description |
|--------|-----|-------------|
| POST | `/api/v1/mfa/totp/setup` | TOTP setup |
| POST | `/api/v1/mfa/totp/confirm` | Confirm TOTP binding |
| POST | `/api/v1/mfa/email/send` | Send email verification code |
| POST | `/api/v1/mfa/sms/send` | Send SMS verification code |
| GET | `/api/v1/mfa/devices` | List MFA devices |
| DELETE | `/api/v1/mfa/devices/{id}` | Remove MFA device |
| PUT | `/api/v1/mfa/devices/{id}` | Rename MFA device |
| POST | `/api/v1/mfa/devices/{id}/primary` | Set primary device |
| POST | `/api/v1/mfa/recovery-codes/generate` | Generate recovery codes |
| GET | `/api/v1/mfa/recovery-codes/status` | Recovery code status |
| GET | `/api/v1/mfa/sessions` | List active sessions |
| DELETE | `/api/v1/mfa/sessions/{id}` | Revoke session |
| POST | `/api/v1/mfa/sessions/revoke-all` | Revoke all sessions |

**Services:** `PasswordService` (change/reset password), `MfaService` (TOTP/email/SMS), `SessionService` (sessions).

**Supported formats:** JPEG, PNG, GIF, WebP.

### Scheduler

Centralized task scheduling via `SchedulerService`. All scheduled tasks are registered in `routes/console.php`.

```bash
# View all scheduled tasks
php artisan schedule:list

# Run scheduler (production)
php artisan schedule:run
```

| Task | Command | Schedule | Description |
|------|---------|----------|-------------|
| subscriptions | `subscriptions:process` | Daily 08:00 | Subscription expiry, auto-renew, dunning |
| credits | `credits:process-expiry` | Daily 00:30 | Credit expiry, low balance alerts |
| retention | `data:retention` | Daily 03:00 | GDPR data cleanup |
| sms-batch | `sms:process-batch` | Every 15min | Scheduled SMS batch tasks |
| reports | `reports:send-scheduled` | Hourly | Scheduled report delivery |
| memory-cleanup | `memory:cleanup` | Daily 04:00 | Memory data cleanup |
| memory-decay | `memory:decay` | Daily 04:30 | Memory decay processing |
| mailer-health | `mailer:health-check` | Daily 05:00 | Mail service health check |

**Disable a task:** In `config/tenancy.php` → `scheduler` array, set task name to `false`.

---

## Module System

### Available Modules

**System modules (always enabled):**

| Module | Description |
|---|---|
| `infrastructure` | Cache, queue, rate limiting, resource management, feature flags |
| `plugin` | Plugin install/uninstall, lifecycle hooks |
| `event` | Event bus, async dispatch, webhook delivery |
| `billing` | Subscription management, plan changes, renewals |
| `logging` | Structured logging, security logs, audit |
| `auth` | Social login, Alipay OAuth |
| `user` | User profiles, preferences, login logs |
| `monitoring` | Metrics, SLA monitoring, alerts, performance |
| `platform` | Data export, API versioning, tenant profiles, cost management |
| `developer-portal` | API docs, sandbox, SDK |
| `ai` | Agent, capability engine, MCP, tool registry, memory, AI gateway |
| `conversation` | Multi-channel conversation, message routing, channel management |
| `workflow` | Flow orchestration, node execution, conditional branching |

**Feature modules (tenant-toggleable):**

| Module | Description | Default |
|---|---|---|
| `domain` | Custom domain binding, ICP, Nginx whitelist | Enabled |
| `ssl` | SSL cert upload, renewal, Nginx config (depends on domain) | Disabled |
| `api-token` | API Token management, Quota sync | Disabled |
| `payment` | Third-party payment gateway | Disabled |
| `form` | Drag-and-drop form builder, data collection, export | Enabled |
| `lottery` | Lottery activities, prize pools, anti-fraud | Enabled |
| `voting` | Voting system, leaderboards, anti-cheat | Enabled |
| `sms` | SMS templates, batch send, delivery stats | Enabled |
| `coupon` | Coupons, batch distribution, referral sharing | Enabled |

### Module Management CLI

```bash
php artisan module:list                    # List all modules
php artisan module:enable billing          # Enable module
php artisan module:disable ssl             # Disable module
php artisan tenancy:init mini              # Init with 6 core modules
php artisan tenancy:init normal            # Init with 14 modules
php artisan tenancy:init full              # Init with all 22 modules
```

### Module Management API

| Method | Route | Description |
|---|---|---|
| `GET` | `/api/v1/admin/modules` | List all modules |
| `POST` | `/api/v1/admin/modules/{name}/enable` | System-level enable |
| `POST` | `/api/v1/admin/modules/{name}/disable` | System-level disable |
| `GET` | `/api/v1/tenants/{id}/modules` | Tenant available modules |
| `POST` | `/api/v1/tenants/{id}/modules/{name}/enable` | Tenant-level enable |
| `POST` | `/api/v1/tenants/{id}/modules/{name}/disable` | Tenant-level disable |

### Creating New Modules

```bash
# Local only
php artisan module:create demo --priority=500 --toggleable --description="Demo module"

# Create + publish to GitHub + Packagist
php artisan module:create demo --priority=500 --toggleable --description="Demo module" \
  --publish --packagist-user=YOUR_USER --packagist-token=YOUR_TOKEN

# Helper script
bin/module-publish demo --priority=500 --toggleable --description="Demo module"
bin/module-publish --list      # List published modules
bin/module-publish --verify    # Verify all module status
```

### Module composer.json (extra.saas)

```json
{
    "name": "dsplat/multi-tenant-saas-module-lottery",
    "version": "1.0.0",
    "description": "Lottery module",
    "type": "library",
    "require": {
        "php": "^8.3",
        "dsplat/multi-tenant-saas": "^2.0"
    },
    "autoload": {
        "psr-4": { "MultiTenantSaas\\Modules\\Lottery\\": "" }
    },
    "extra": {
        "saas": {
            "name": "lottery",
            "priority": 50,
            "dependencies": ["billing"],
            "conflicts": [],
            "requires_core": ">=2.0.0",
            "provider": "MultiTenantSaas\\Modules\\Lottery\\LotteryServiceProvider",
            "tenant_toggleable": true,
            "default_enabled": true
        }
    }
}
```

### Publish Flow

```
monorepo development → git push main
  → GitHub Actions splits to 22 module repos
  → Auto-triggers Packagist update
  → Users: composer require dsplat/multi-tenant-saas-module-xxx
```

### Plugin System (Independent from Modules)

`plugins/{name}/manifest.json` — runtime install/uninstall, tenant isolation.

---

## Service Reference

### Middleware

| Middleware | Alias | Description |
|---|---|---|
| `IdentifyDomain` | `domain.identify` | Identify domain type (admin/console/api/app) |
| `IdentifyTenant` | `tenant.identify` | Identify current tenant |
| `CheckPermission` | `tenant.permission` | Role-based access control |
| `CheckRbacPermission` | `rbac.permission` | Fine-grained RBAC |
| `EnsureTenantContext` | `tenant.ensure` | Ensure valid tenant context |
| `SetLocale` | `locale.set` | Auto-set request locale |
| `CheckFeatureFlag` | `feature.flag` | Feature flag check |
| `CheckIpWhitelist` | `ip.whitelist` | IP whitelist check |
| `McpMiddleware` | `mcp.auth` | MCP request auth |

### Core Services (94)

| Category | Services | Description |
|---|---|---|
| **Basic** | `IdGenerator`, `TenantService`, `TenantSettingService`, `TenantMemberService`, `UserService` | Core CRUD |
| **Auth** | `RbacService`, `MfaService`, `SsoService`, `IpWhitelistService`, `ConsentService`, `SessionService` | Auth & security |
| **Billing** | `TenantCreditService`, `QuotaService`, `SubscriptionService`, `InvoiceService`, `TaxService`, `CostService` | Credits, subscriptions, invoices |
| **Payment** | `PayService`, `PayPalService`, `StripeService`, `UnionPayService`, `PaymentSecurityService` | Payment gateways |
| **OAuth** | `SocialiteService`, `AlipayOAuthService` | Third-party login |
| **File** | `FileService`, `ExcelService`, `PdfService` | File storage & export |
| **Notification** | `NotificationService`, `InAppNotificationService`, `MailTemplateService`, `BroadcastingService` | Notifications |
| **Audit** | `AuditService`, `StructuredLogService`, `LoginLogService` | Audit logging |
| **Ops** | `CacheService`, `QueueService`, `HealthService`, `AlertService`, `MetricsService`, `SlaService` | Operations |
| **AI** | `AiGatewayService`, `AiTextService`, `AiImageService`, `AiVideoService`, `AiUsageService` | AI gateway |
| **Agent** | `AgentService`, `AgentRuntime`, `AgentMonitor`, `ToolRegistry`, `MemoryCompressor` | Agent framework |
| **Conversation** | `ConversationService`, `MessageService`, `ReadStateService`, `TagService` | Messaging |
| **Workflow** | `WorkflowEngine`, `WorkflowService`, `WorkflowRegistry`, `RetryService`, `RollbackService` | Workflow engine |
| **Channel** | `ChannelManager`, `MessageRouter` | Message channels |
| **Memory** | `MemoryService`, `MemoryPipeline`, `TenantMemory`, `EntityMemory` | AI memory |
| **Enterprise** | `WebhookService`, `EventBusService`, `FeatureFlagService`, `DataResidencyService`, `GdprService`, `TenantCloneService`, `SandboxService`, `BrandingService` | Enterprise features |

### Module Services (80)

| Module | Services |
|---|---|
| **Lottery** | `LotteryService` — activities, prizes, draw, blacklist, stats |
| **SMS** | `SmsService` — templates, batch send, delivery stats, unsubscribe |
| **Coupon** | `CouponService` — templates, batch distribution, referral sharing |
| **MCP** | `McpToolRegistry`, `McpClientRegistry`, `McpSkillGenerator` |
| **Voting** | `VotingService` — voting activities, leaderboards |
| **Form** | `FormBuilderService` — dynamic form builder |
| **Domain** | `DomainService` — custom domain management |
| **SSL** | `SslService` — SSL certificate management |
| **Others** | Infrastructure, Event, Plugin, Logging, Billing, Auth, User, Platform, Monitoring, DeveloperPortal, Payment, ApiToken |

---

## Model Reference

### Core Models

| Category | Models | Description |
|---|---|---|
| **Core** | `Tenant`, `User`, `TenantUser` | Tenants, users, relationships |
| **Config** | `TenantSetting`, `SystemSetting` | Tenant/system config |
| **RBAC** | `Role`, `Permission`, `RolePermission` | Permissions |
| **Billing** | `CreditAccount`, `CreditTransaction`, `FinancialRecord`, `PaymentOrder`, `Invoice`, `Coupon`, `CouponShare`, `TaxRule`, `SubscriptionPlan`, `SubscriptionHistory`, `UsageRecord`, `CostAllocation` | Billing |
| **Security** | `MfaDevice`, `MfaRecoveryCode`, `UserSession`, `TrustedDevice`, `PasswordHistory`, `SsoProvider`, `IpWhitelist`, `Consent` | Security |
| **AI** | `AiProvider`, `AiPrompt`, `AiModelAlias`, `AiUsageQuota`, `AiRequest` | AI config |
| **Agent** | `Agent`, `AgentTool`, `AgentConversation`, `AgentConversationMessage`, `AgentToolLog` | Agent framework |
| **Conversation** | `Conversation`, `Message`, `Participant`, `Attachment`, `Reaction`, `Mention`, `ReadState`, `ConversationSession`, `ConversationTag` | Messaging |
| **Workflow** | `Workflow`, `WorkflowNode`, `WorkflowExecution` | Workflow |
| **Enterprise** | `Webhook`, `WebhookDelivery`, `EventSubscription`, `FeatureFlag`, `MetricsSnapshot`, `SlaEvent`, `BrandingConfig`, `TenantHierarchy`, `SandboxEnvironment`, `DataRetentionPolicy`, `CustomReport`, `InAppNotification` | Enterprise |
| **File** | `FileUpload` | File storage |
| **Other** | `AuditLog`, `NotificationPreference`, `UserApiToken` | Misc |

### Module Models

| Module | Models |
|---|---|
| **Lottery** | `LotteryActivity`, `LotteryActivityPrize`, `LotteryDrawLog`, `LotteryBlacklist`, `LotteryPool`, `LotteryPrize` |
| **SMS** | `SmsTemplate`, `SmsBatchTask`, `SmsDeliveryStat`, `SmsUnsubscribe` |
| **Coupon** | `CouponShare` |
| **MCP** | `McpClient`, `McpTool`, `McpToolAccessLog` |
| **Voting** | `Vote`, `VoteOption`, `VoteRecord` |
| **Form** | `Form`, `FormField`, `FormSubmission` |

---

## Code Examples

### Base Model

```php
use MultiTenantSaas\Models\Tenant;

class Customer extends Tenant
{
    protected $primaryKey = 'customer_id';
    protected $fillable = ['tenant_id', 'name', 'email'];
}
```

### Helper Functions

```php
$tenantId = tenant_id();                          // Current tenant ID
$corpId = tenant_config('wecom', 'corp_id');      // Tenant config
check_quota('customers', 1);                      // Quota check
$id = generate_id();                              // Unique ID
```

### Routing

```php
// System admin routes
Route::prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard']);
});

// Tenant admin routes
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    Route::get('/', [ConsoleController::class, 'dashboard']);
});

// Role-protected routes
Route::middleware(['tenant.permission:tenant_admin'])->group(function () {
    // Only tenant_admin can access
});
```

### Querying Data

```php
// Auto-filtered by tenant
$orders = Order::all();

// Cross-tenant (admin only)
$allOrders = Order::withoutTenantScope()->get();

// Specific tenant (admin only)
$tenantOrders = Order::withTenant('1234567890123456')->get();
```

---

## Testing

```bash
composer test              # Parallel (~6s, 2269 tests, 5648 assertions)
composer test:sequential   # Single-thread fallback
composer test:filter -- SomeTest  # Filtered
```

**Optimization:**
- SQLite `:memory:` + PRAGMA (journal_mode=OFF, synchronous=OFF, foreign_keys=OFF)
- DELETE reset (not DROP/CREATE)
- bcrypt rounds=4
- `brianium/paratest` parallel execution

---

## Tech Stack

- **PHP**: ^8.3
- **Laravel**: ^13.0
- **Database**: MySQL 8.0+
- **Cache**: Redis (recommended) / Database
- **Web Server**: Nginx + PHP-FPM
- **Frontend**: Vue.js 3 + TypeScript + Vite
- **CSS**: Bootstrap

### Integrations

| Library | Purpose | Config |
|---|---|---|
| `laravel/sanctum` | API auth + Token abilities | `config/sanctum.php` |
| `laravel/socialite` | Third-party login | `config/socialite.php` |
| `yansongda/pay` | WeChat/Alipay payment | `config/pay.php` |
| `spatie/laravel-health` | Health checks | `config/health.php` |
| `darkaonline/l5-swagger` | OpenAPI docs | `config/l5-swagger.php` |
| `maatwebsite/excel` | Excel import/export | Built-in |
| `barryvdh/laravel-dompdf` | PDF generation | Built-in |
| `laravel/horizon` | Queue monitoring (dev) | `/horizon` |
| `sentry/sentry-laravel` | Error tracking (dev) | `.env` |

---

## Updating

```bash
composer update dsplat/multi-tenant-saas
```
