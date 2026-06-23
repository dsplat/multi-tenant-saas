# Multi-Tenant SaaS Framework

开箱即用的 Laravel 多租户 SaaS 基础框架，为构建企业级多租户应用提供完整的解决方案。

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-777BB4)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-%5E12.0-FF2D20)](https://laravel.com)

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

### 👥 权限控制

- **平台级角色**：`super_admin`（超级管理员）、`platform_user`（普通用户）
- **租户内角色**：`tenant_admin`（租户管理员）、`end_user`（终端用户）
- **中间件保护**：通过中间件实现细粒度权限控制

### 🆔 全局唯一 ID

- 16 位随机数字，JavaScript 安全（`<= Number.MAX_SAFE_INTEGER`）
- 全局唯一，所有表共用 ID 空间
- 完全无序，无法推测业务增长

### 💰 积分/配额管理

- 租户级积分账户
- 用户级积分账户
- 配额检查和限制
- 交易记录追溯

### 🔐 第三方登录

- 微信（企业微信）
- 钉钉
- 飞书
- 租户独立配置

### 📝 审计日志

- 自动记录关键操作
- 支持自定义审计事件
- 租户隔离的日志查询

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
│   │   ├── Controllers/    # 控制器
│   │   └── Middleware/     # 自定义中间件
│   └── Models/             # 业务模型
├── config/
│   ├── tenancy.php         # 框架核心配置
│   ├── id.php              # ID生成器配置
│   ├── domain.php          # 域名配置
│   └── ssl.php             # SSL配置
├── database/
│   └── migrations/         # 数据库迁移
├── docs/                   # 文档
├── src/                    # 框架核心代码
│   ├── Concerns/           # Traits
│   ├── Context/            # 上下文管理
│   ├── Contracts/          # 接口定义
│   ├── DTOs/               # 数据传输对象
│   ├── Enums/              # 枚举
│   ├── Exceptions/         # 异常
│   ├── Helpers/            # 辅助函数
│   ├── Middleware/          # 中间件
│   ├── Models/             # 框架模型
│   ├── Modules/            # 可选模块
│   │   ├── Domain/         # 域名管理模块
│   │   └── SSL/            # SSL证书模块
│   ├── Scopes/             # 全局作用域
│   ├── Services/           # 服务层
│   └── TenancyServiceProvider.php
├── tests/                  # 测试
└── composer.json
```

---

## 核心组件

### 中间件

| 中间件 | 说明 |
|--------|------|
| `IdentifyDomain` | 识别域名类型（admin/console/api/app） |
| `IdentifyTenant` | 识别当前租户 |
| `CheckPermission` | 权限控制（角色检查） |
| `EnsureTenantContext` | 确保租户上下文有效 |

### 服务

| 服务 | 说明 |
|------|------|
| `IdGenerator` | 16位随机ID生成器 |
| `TenantService` | 租户CRUD管理 |
| `TenantSettingService` | 租户配置管理 |
| `TenantCreditService` | 积分/配额管理 |
| `TenantMemberService` | 成员管理 |
| `SocialiteService` | 第三方登录 |
| `PaymentService` | 支付服务 |
| `AuditService` | 审计日志 |

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
// 自动按租户过滤
$orders = Order::all();

// 跨租户查询（Super Admin）
$allOrders = Order::withoutTenantScope()->get();

// 指定租户查询
$tenantOrders = Order::withTenant('1234567890123456')->get();

// 查询所有租户数据
$allOrders = Order::forAllTenants()->get();
```

---

## 文档

- [文档目录](docs/README.md)
- [系统架构概览](docs/architecture/系统架构概览.md)
- [多域名架构设计](docs/architecture/多域名架构设计.md)
- [租户隔离架构](docs/architecture/租户隔离架构.md)
- [数据模型设计](docs/architecture/数据模型设计.md)
- [快速开始](docs/guides/快速开始.md)
- [四重访问架构](docs/guides/四重访问架构.md)
- [域名配置指南](docs/guides/域名配置指南.md)
- [权限控制指南](docs/guides/权限控制指南.md)
- [部署指南](docs/deployment/部署指南.md)
- [Nginx配置指南](docs/deployment/Nginx配置指南.md)
- [本地开发环境](docs/development/本地开发环境.md)
- [核心API](docs/api/核心API.md)
- [中间件API](docs/api/中间件API.md)
- [服务层API](docs/api/服务层API.md)

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
| `laravel/sanctum` | API 认证 + Token | 内置 |
| `laravel/socialite` | 第三方登录（微信/钉钉/飞书） | `config/socialite.php` |
| `yansongda/pay` | 支付（微信/支付宝） | `config/pay.php` |
| `spatie/laravel-health` | 健康检查 | `config/health.php` |
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
