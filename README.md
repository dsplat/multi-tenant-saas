# Multi-Tenant SaaS Framework

Laravel 多租户 SaaS 基础框架

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## 特性

- ✅ **全局唯一随机ID** - 16位随机数字，JS安全，无法推测业务量
- ✅ **自动租户隔离** - 全局作用域，开发者无需思考租户问题
- ✅ **三级配置缓存** - 内存 → Redis → 数据库，零延迟读取
- ✅ **权限控制** - 域名类型 × 用户角色，灵活的权限体系
- ✅ **配额管理** - 套餐配额检查，资源使用限制
- ✅ **审计日志** - 自动记录关键操作，可追溯
- ✅ **前端无关** - 只提供API，不绑定任何前端框架

## 安装

```bash
composer require luoyueliang/multi-tenant-saas
```

发布配置和迁移：

```bash
php artisan vendor:publish --tag=tenancy-config
php artisan vendor:publish --tag=tenancy-migrations
php artisan migrate
```

## 快速开始

### 1. 配置中间件

```php
// bootstrap/app.php (Laravel 11+)
return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend([
            \MultiTenantSaas\Middleware\IdentifyDomain::class,
        ]);

        $middleware->api(prepend: [
            \MultiTenantSaas\Middleware\IdentifyTenant::class,
        ]);

        $middleware->alias([
            'tenant.ensure' => \MultiTenantSaas\Middleware\EnsureTenantContext::class,
            'tenant.permission' => \MultiTenantSaas\Middleware\CheckPermission::class,
        ]);
    })
    ->create();
```

### 2. 继承基类模型

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

### 3. 使用辅助函数

```php
// 获取当前租户ID
$tenantId = tenant_id();

// 获取租户配置
$corpId = tenant_config('wecom', 'corp_id');

// 检查配额
check_quota('customers');

// 生成唯一ID
$id = generate_id();
```

## 核心组件

| 组件 | 说明 |
|-----|------|
| `IdGenerator` | 全局唯一随机ID生成 |
| `TenantContext` | 租户上下文管理 |
| `TenantScope` | 全局作用域隔离 |
| `IdentifyTenant` | 租户识别中间件 |
| `CheckPermission` | 权限控制中间件 |
| `TenantSettingService` | 配置管理服务 |
| `QuotaService` | 资源配额服务 |
| `AuditService` | 操作审计服务 |

## 文档

- [系统架构规划](docs/系统架构规划.md)
- [多租户架构决策](docs/多租户架构决策.md)
- [ID生成器设计](docs/ID生成器设计.md)
- [AI能力详细设计](docs/AI能力详细设计.md)
- [海报分销设计](docs/海报分销设计.md)
- [部署指南](docs/部署指南.md)

## 许可证

MIT License
