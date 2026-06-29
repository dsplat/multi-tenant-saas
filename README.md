# Multi-Tenant SaaS Framework

开箱即用的 Laravel 多租户 SaaS 基础框架，为构建企业级多租户应用提供完整的解决方案。

📖 **完整文档**：[docs/README.md](docs/README.md) ｜ 🛡 [安全审计报告](docs/security/安全审计报告.md) ｜ 🚀 [5 分钟快速开始](docs/guides/快速开始.md) ｜ 🤖 [AI 模块](docs/guides/AI模块使用指南.md)

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-777BB4)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-%5E12.0-FF2D20)](https://laravel.com)
[![Version](https://img.shields.io/badge/version-v1.1.0-blue)](CHANGELOG.md)

---

## 核心特性

### 🏢 四重访问架构

系统分为四个独立的访问层级，每个层级有不同的访问权限和用途：

| 层级 | 域名示例 | 路径 | 角色要求 | 说明 |
|------|----------|------|----------|------|
| **系统后台** | `admin.lyt.com` | `/*` | `super_admin` | 独立域名，避免暴力破解 |
| **租户后台** | `ai.lyt.com` | `/console/*` | `tenant_admin` | 租户管理后台 |
| **用户前台** | `ai.tenant1.local` | `/*` | `end_user` | 租户自定义域名 |
| **访客** | 同用户前台 | `/*` | 未登录 | 登录状态区分 |

### 🔒 数据隔离

- **全局作用域**：自动为所有查询添加 `WHERE tenant_id = ?`
- **自动填充**：创建记录时自动填充 `tenant_id`
- **透明操作**：业务代码无需关心租户隔离逻辑

```php
// 自动按租户过滤
Order::all();
// SQL: SELECT * FROM orders WHERE tenant_id = 123456

// 创建时自动填充 tenant_id
Order::create(['name' => '新订单']);
// 自动设置 tenant_id = 当前租户ID
```

### 🌐 多域名支持

- **单域名模式**：通过路径区分功能（`/console/*`、`/api/*`）
- **多域名模式**：租户使用独立域名，增强品牌感
- **域名白名单**：自动管理 Nginx 域名白名单
- **SSL 证书**：支持自定义域名 SSL 证书管理

### 👥 RBAC 权限控制

- **平台级角色**：`super_admin`（超级管理员）、`platform_user`（普通用户）
- **租户内角色**：`tenant_admin`（租户管理员）、`end_user`（终端用户）
- **细粒度权限**：40+ 权限节点，按 `tenant.view`、`member.create` 等命名
- **自定义角色**：租户可创建自定义角色并分配权限
- **中间件保护**：`rbac.permission:permission.name` 中间件实现路由级权限控制

### 🆔 全局唯一 ID

- 16 位随机数字，JavaScript 安全（`<= Number.MAX_SAFE_INTEGER`）
- 全局唯一，所有表共用 ID 空间
- 完全无序，无法推测业务增长

### 💰 积分/配额管理

- 租户级积分账户
- 用户级积分账户
- 积分过期与到期提醒
- 配额检查和限制
- 交易记录追溯

### 📋 订阅管理

- 订阅计划（free/basic/pro/enterprise）
- 月付/年付定价
- 试用期管理
- 订阅历史记录
- 升级/降级/取消

### 💳 多支付网关

| 支付方式 | 驱动 | 说明 |
|----------|------|------|
| 微信支付 | `wechat` | 通过 yansongda/pay |
| 支付宝 | `alipay` | 通过 yansongda/pay |
| PayPal | `paypal` | 独立 Service 实现 |
| Stripe | `stripe` | 独立 Service 实现 |
| 银联 | `unionpay` | 独立 Service 实现 |

- 统一的 `createOrder` / `refund` / `handleWebhook` 接口
- 支付安全日志
- 租户级支付配置

### 🔐 第三方登录

- 微信（企业微信）
- 钉钉
- 飞书
- 支付宝
- 租户独立配置

### 📁 文件存储

- 多磁盘支持（local/s3/oss）
- 文件分享（签名 URL）
- 存储配额管理
- 图片预览

### 🔔 通知中心

- 站内通知（Laravel Notification）
- 通知偏好配置
- 5 种内置通知类型：
  - 积分不足提醒
  - 支付成功通知
  - 订阅即将到期通知
  - 租户暂停通知
  - 通用通知

### 📝 审计日志

- 自动记录关键操作
- 支持自定义审计事件
- 租户隔离的日志查询

### 🌍 国际化

- 支持 `zh_CN` 和 `en` 双语
- 13 个语言文件覆盖所有业务模块
- `SetLocale` 中间件自动设置语言

### 📊 监控与运维

- **健康检查**：spatie/laravel-health 集成
- **结构化日志**：带租户/用户上下文的日志记录
- **告警系统**：阈值监控 + 告警规则
- **性能监控**：PerformanceService 追踪关键指标
- **队列监控**：Horizon 集成（开发环境）

### 🔧 高级特性

- **API 版本管理**：多版本 API 共存 + 废弃通知
- **插件系统**：租户级插件安装与管理
- **速率限制**：可配置的 API 限流规则
- **导出任务**：Excel/PDF 异步导出
- **支付安全**：支付密码 + 支付日志
- **Swagger/OpenAPI**：API 文档自动生成

### 🤖 AI 网关

- **多提供商统一接口**：OpenAI / 智谱 / Anthropic / DeepSeek（文本），DALL-E / Stability（图像），Runway / 可灵（视频）
- **租户级配置**：能力开关、自定义 API Key（加密）、模型白名单、月度预算
- **用量与配额**：按 `monthly` 周期聚合 token/张数/秒数，超额策略 `block`/`warn`/`allow`
- **异步视频生成**：提交 → 队列延迟轮询 → 完成事件回调 → 结果存储
- **流式输出**：`streamChat` 支持 SSE 风格逐 chunk 输出
- **Prompt 模板**：模板管理 + 变量渲染
- **PHP SDK**：`AiResource` 一行调用文本/图像/视频/用量

### 💲 计费体系

- **订阅**：free / basic / pro / enterprise 四档计划，月付/年付，试用期
- **积分/配额**：租户级预付费积分账户，充值/消耗/退款/过期，配额检查
- **AI 用量计费**：按 token/张/秒计费，月度预算与超额策略
- **发票税务**：发票开具、税率配置、优惠券核销
- **成本核算**：基础设施 / AI / 第三方成本分摊 + 损益与趋势预测

### 🛡 安全

- **OWASP Top 10 合规**：0 高危（见 [安全审计报告](docs/security/安全审计报告.md)）
- **租户数据隔离**：全局作用域 + 跨租户 403
- **RBAC + Token abilities**：40+ 权限节点 + 14 种 API 权限
- **敏感数据保护**：密码哈希、敏感字段隐藏、手机号脱敏、API Key/Tokens 加密存储
- **安全响应头**：`X-Content-Type-Options` / `X-Frame-Options` / HSTS
- **限流与 MFA**：认证端点限流 + TOTP/邮箱/短信多因素认证

---

## 快速开始

### 安装

```bash
composer create-project luoyueliang/multi-tenant-saas my-saas-app
cd my-saas-app
```

### 环境配置

```bash
cp .env.example .env
php artisan key:generate
```

编辑 `.env` 文件，配置数据库和域名：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=multi_tenant_saas
DB_USERNAME=your_username
DB_PASSWORD=your_password

ADMIN_DOMAIN=admin.example.com
```

### 数据库迁移

```bash
php artisan migrate
php artisan db:seed
```

> `db:seed` 会创建平台默认租户（ID: 9007199254740991）

### 创建测试数据

```bash
php artisan tinker
```

```php
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\TenantUser;

// 创建系统管理员
$admin = User::create([
    'name' => '系统管理员',
    'email' => 'admin@example.com',
    'password' => bcrypt('password'),
    'role' => 'super_admin',
]);

// 创建租户
$tenant = Tenant::create([
    'name' => '示例企业',
    'slug' => 'example',
    'custom_domain' => 'ai.example.com',
    'status' => 'active',
]);

// 关联用户到租户
TenantUser::create([
    'tenant_id' => $tenant->tenant_id,
    'user_id' => $admin->id,
    'role' => 'tenant_admin',
    'is_active' => true,
]);
```

### 配置 Nginx

```nginx
server {
    listen 80;
    server_name ai.example.com;
    root /path/to/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
}
```

---

## 项目结构

```
multi-tenant-saas/
├── app/
│   ├── Http/
│   │   ├── Controllers/    # 控制器（18 个）
│   │   ├── Middleware/     # 自定义中间件
│   │   └── Resources/      # API Resource（5 个）
│   ├── Models/             # 业务模型
│   └── Notifications/      # 通知类（5 种）
├── config/
│   ├── tenancy.php         # 框架核心配置
│   ├── id.php              # ID生成器配置
│   ├── domain.php          # 域名配置
│   ├── ssl.php             # SSL配置
│   ├── pay.php             # 支付配置
│   ├── socialite.php       # 第三方登录配置
│   ├── queue.php           # 队列配置
│   ├── health.php          # 健康检查配置
│   ├── sanctum.php         # API认证配置
│   ├── cors.php            # CORS配置
│   ├── l5-swagger.php      # Swagger配置
│   └── database.php        # 数据库配置
├── database/
│   ├── factories/          # 模型工厂
│   ├── migrations/         # 数据库迁移（37 张表）
│   └── seeders/            # 数据填充
├── docs/                   # 文档
├── lang/                   # 国际化
│   ├── zh_CN/              # 中文（13 个文件）
│   └── en/                 # 英文（13 个文件）
├── src/                    # 框架核心代码
│   ├── Concerns/           # Traits（BelongsToTenant / HasGlobalId）
│   ├── Context/            # 上下文管理（TenantContext）
│   ├── Contracts/          # 接口定义（IdGeneratorContract / TenantContextContract）
│   ├── Enums/              # 枚举（ErrorCode）
│   ├── Events/             # 领域事件（5 个）
│   ├── Exceptions/         # 业务异常（4 个）
│   ├── Helpers/            # 辅助函数
│   ├── Jobs/               # 队列任务（2 个）
│   ├── Listeners/          # 事件监听器
│   ├── Mail/               # 邮件类（2 个）
│   ├── Middleware/          # 中间件（6 个）
│   ├── Models/             # 框架模型（17 个）
│   ├── Modules/            # 可选模块（4 个）
│   │   ├── ApiToken/       # API Token 模块
│   │   ├── Domain/         # 域名管理模块
│   │   ├── Payment/        # 支付模块
│   │   └── SSL/            # SSL证书模块
│   ├── Scopes/             # 全局作用域（TenantScope）
│   ├── Services/           # 服务层（39 个）
│   └── TenancyServiceProvider.php
├── tests/                  # 测试（25 个文件）
└── composer.json
```

---

## 核心组件

### 中间件

| 中间件 | 别名 | 说明 |
|--------|------|------|
| `IdentifyDomain` | `domain.identify` | 识别域名类型（admin/console/api/app） |
| `IdentifyTenant` | `tenant.identify` | 识别当前租户 |
| `CheckPermission` | `tenant.permission` | 角色级权限控制 |
| `CheckRbacPermission` | `rbac.permission` | RBAC 细粒度权限控制 |
| `EnsureTenantContext` | `tenant.ensure` | 确保租户上下文有效 |
| `SetLocale` | `locale.set` | 自动设置请求语言 |

### 服务

| 分类 | 服务 | 说明 |
|------|------|------|
| **基础** | `IdGenerator` | 16位随机ID生成器 |
| | `TenantService` | 租户CRUD管理 |
| | `TenantSettingService` | 租户配置管理 |
| | `TenantMemberService` | 成员管理 |
| | `TenantProfileService` | 租户档案管理 |
| **权限** | `RbacService` | RBAC权限管理 |
| **积分** | `TenantCreditService` | 积分/配额管理 |
| | `RefundService` | 退款服务 |
| **订阅** | `SubscriptionService` | 订阅管理 |
| **支付** | `PaymentService` | 支付统一入口 |
| | `PayPalService` | PayPal支付 |
| | `StripeService` | Stripe支付 |
| | `UnionPayService` | 银联支付 |
| | `PaymentSecurityService` | 支付安全 |
| **OAuth** | `SocialiteService` | 第三方登录 |
| | `AlipayOAuthService` | 支付宝OAuth |
| **文件** | `FileService` | 文件存储管理 |
| **通知** | `NotificationPreferenceService` | 通知偏好 |
| **审计** | `AuditService` | 审计日志 |
| | `StructuredLogService` | 结构化日志 |
| | `LoginLogService` | 登录日志 |
| **运维** | `CacheService` | 缓存管理 |
| | `QueueService` | 队列管理 |
| | `HorizonService` | Horizon管理 |
| | `PerformanceService` | 性能监控 |
| | `AlertService` | 告警系统 |
| | `SystemSettingService` | 系统配置 |
| | `ExportService` | 导出任务 |
| | `HealthService` | 健康检查 |
| **高级** | `ApiVersionService` | API版本管理 |
| | `PluginService` | 插件系统 |
| | `RateLimitService` | 速率限制 |
| | `UserPreferenceService` | 用户偏好 |
| | `SmsService` | 短信服务 |
| | `DomainService` | 域名管理 |
| | `SslService` | SSL证书管理 |
| | `NginxConfigService` | Nginx配置管理 |

### 模型

| 模型 | 说明 |
|------|------|
| `Tenant` | 租户 |
| `User` | 用户 |
| `TenantUser` | 租户用户关系 |
| `TenantSetting` | 租户配置 |
| `CreditAccount` | 积分账户 |
| `CreditTransaction` | 积分交易记录 |
| `FinancialRecord` | 财务记录 |
| `AuditLog` | 审计日志 |
| `Permission` | 权限节点 |
| `Role` | 角色 |
| `RolePermission` | 角色-权限关系 |
| `SubscriptionPlan` | 订阅计划 |
| `SubscriptionHistory` | 订阅历史 |
| `FileUpload` | 文件上传 |
| `NotificationPreference` | 通知偏好 |
| `UserApiToken` | 用户API Token |
| `SystemSetting` | 系统配置 |

### 模块

| 模块 | 说明 |
|------|------|
| `ApiToken` | API Token 管理（用户级 Token + abilities） |
| `Domain` | 域名管理（白名单/自定义域名） |
| `Payment` | 支付模块（多网关统一接口） |
| `SSL` | SSL 证书管理（申请/续期/部署） |

---

## 使用示例

### 继承基类模型

```php
use MultiTenantSaas\Models\Tenant;

class Customer extends Tenant
{
    protected $primaryKey = 'customer_id';
    
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
    ];
}
```

### 使用辅助函数

```php
// 获取当前租户ID
$tenantId = tenant_id();

// 获取租户配置
$corpId = tenant_config('wecom', 'corp_id');

// 检查配额
check_quota('customers', 1);

// 生成唯一ID
$id = generate_id();
```

### 路由配置

```php
// 系统后台路由
Route::prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard']);
});

// 租户后台路由
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    Route::get('/', [ConsoleController::class, 'dashboard']);
});

// 需要特定角色的路由
Route::middleware(['tenant.permission:tenant_admin'])->group(function () {
    // 仅 tenant_admin 可访问
});
```

### 查询数据

```php
// 自动按租户过滤（模型需使用 BelongsToTenant Trait）
$orders = Order::all();

// 跨租户查询（仅 admin 域名下可用）
$allOrders = Order::withoutTenantScope()->get();

// 指定租户查询（仅 admin 域名下可用）
$tenantOrders = Order::withTenant('1234567890123456')->get();

// 查询所有租户数据（仅 admin 域名下可用）
$allOrders = Order::forAllTenants()->get();
```

---

## 文档

- [文档目录](docs/README.md)
- [系统架构概览](docs/architecture/系统架构概览.md)
- [多域名架构设计](docs/architecture/多域名架构设计.md)
- [租户隔离架构](docs/architecture/租户隔离架构.md)
- [数据模型设计](docs/architecture/数据模型设计.md)
- [设计决策](docs/architecture/设计决策.md)
- [快速开始（5 分钟上手）](docs/guides/快速开始.md)
- [四重访问架构](docs/guides/四重访问架构.md)
- [域名配置指南](docs/guides/域名配置指南.md)
- [权限控制指南](docs/guides/权限控制指南.md)
- [AI 模块使用指南](docs/guides/AI模块使用指南.md)
- [计费配置指南](docs/guides/计费配置指南.md)
- [OAuth SDK接入指南](docs/guides/OAuth_SDK接入指南.md)
- [支付SDK接入指南](docs/guides/支付SDK接入指南.md)
- [SaaS核心模块扩展指南](docs/guides/SaaS核心模块扩展指南.md)
- [部署指南（Docker / Kubernetes）](docs/deployment/部署指南.md)
- [运维手册](docs/deployment/运维手册.md)
- [发布检查清单](docs/deployment/发布检查清单.md)
- [备份恢复流程](docs/deployment/备份恢复流程.md)
- [故障应急手册](docs/deployment/故障应急手册.md)
- [监控告警配置](docs/deployment/监控告警配置.md)
- [Nginx配置指南](docs/deployment/Nginx配置指南.md)
- [本地开发环境](docs/development/本地开发环境.md)
- [编码规范](docs/development/coding-standards.md)
- [HTTP 端点总览](docs/api/端点总览.md)
- [AI 模块 API](docs/api/AI模块API.md)
- [核心API](docs/api/核心API.md)
- [中间件API](docs/api/中间件API.md)
- [服务层API](docs/api/服务层API.md)
- [OpenAPI规范](docs/api/openapi.yaml)
- [安全审计报告（OWASP Top 10）](docs/security/安全审计报告.md)
- [PHP SDK 使用示例](docs/examples/php-sdk-quickstart.md)
- [REST API 调用示例](docs/examples/rest-api-examples.md)

---

## 技术栈

- **PHP**: ^8.2
- **Laravel**: ^12.0
- **数据库**: MySQL 8.0+
- **缓存**: Redis (推荐) / Database
- **Web服务器**: Nginx + PHP-FPM

## 集成库

| 库 | 用途 | 配置 |
|---|---|---|
| `laravel/sanctum` | API 认证 + Token abilities | `config/sanctum.php` |
| `laravel/socialite` | 第三方登录（微信/钉钉/飞书） | `config/socialite.php` |
| `yansongda/pay` | 支付（微信/支付宝） | `config/pay.php` |
| `spatie/laravel-health` | 健康检查 | `config/health.php` |
| `darkaonline/l5-swagger` | Swagger/OpenAPI 文档 | `config/l5-swagger.php` |
| `maatwebsite/excel` | Excel 导入导出 | 内置 |
| `barryvdh/laravel-dompdf` | PDF 生成 | 内置 |
| `laravel/horizon` | 队列监控 (dev) | `/horizon` |
| `sentry/sentry-laravel` | 错误追踪 (dev) | `.env` |

## 更新框架

```bash
composer update luoyueliang/multi-tenant-saas
```

---

## 许可证

MIT License

---

## 贡献

欢迎提交 Issue 和 Pull Request！

---

## 致谢

感谢 [aistudio_backend](https://github.com/luoyueliang/aistudio_backend) 项目提供的架构参考。
