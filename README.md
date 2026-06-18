# Multi-Tenant SaaS Framework

Laravel 多租户 SaaS 基础框架 — 开箱即用的项目骨架

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## 特性

- ✅ **全局唯一随机ID** — 16位随机数字，JS安全，无法推测业务量
- ✅ **自动租户隔离** — 全局作用域，开发者无需思考租户问题
- ✅ **三级配置缓存** — 内存 → Redis → 数据库，零延迟读取
- ✅ **四重访问架构** — admin/console/app/guest 四层访问控制
- ✅ **权限控制** — 域名类型 × 用户角色，灵活的权限体系
- ✅ **配额管理** — 套餐配额检查，资源使用限制
- ✅ **审计日志** — 自动记录关键操作，可追溯
- ✅ **前端无关** — 只提供API，不绑定任何前端框架
- ✅ **模块化设计** — 核心能力 + 可选模块（域名管理、SSL证书）

## 快速开始

```bash
composer create-project luoyueliang/multi-tenant-saas my-saas-app
cd my-saas-app
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

## 更新框架

```bash
composer update luoyueliang/multi-tenant-saas
```

## 项目结构

```
my-saas-app/
├── app/                        # 业务代码
│   ├── Http/Controllers/
│   ├── Http/Middleware/
│   ├── Models/
│   └── Providers/
├── src/                        # 框架核心（MultiTenantSaas 命名空间）
│   ├── Concerns/               # Traits
│   ├── Context/                # 上下文管理
│   ├── Contracts/              # 接口定义
│   ├── DTOs/                   # 数据传输对象
│   ├── Enums/                  # 枚举
│   ├── Exceptions/             # 异常
│   ├── Helpers/                # 辅助函数
│   ├── Middleware/              # 中间件
│   ├── Models/                 # 框架模型
│   ├── Modules/                # 可选模块
│   │   ├── Domain/             # 域名管理模块
│   │   └── SSL/                # SSL证书模块
│   ├── Scopes/                 # 全局作用域
│   ├── Services/               # 服务层
│   └── TenancyServiceProvider.php
├── config/
│   ├── tenancy.php             # 框架配置
│   ├── id.php                  # ID生成器配置
│   ├── domain.php              # 域名配置
│   └── ssl.php                 # SSL配置
├── database/
│   └── migrations/             # 租户相关表迁移
├── bootstrap/app.php           # 中间件已预配置
├── routes/
└── composer.json
```

## 四重访问架构

| 层级 | 域名示例 | 路径 | 角色要求 |
|------|----------|------|----------|
| 系统后台 | `admin.lyt.com` | `/*` | `super_admin` |
| 租户后台 | `ai.lyt.com` | `/console/*` | `tenant_admin` |
| 用户前台 | `ai.tenant1.local` | `/*` | `end_user` |
| 访客 | 同用户前台 | `/*` | 未登录 |

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

### 辅助函数

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
| `IdentifyDomain` | 域名识别中间件 |
| `IdentifyTenant` | 租户识别中间件 |
| `CheckPermission` | 权限控制中间件 |
| `TenantSettingService` | 配置管理服务 |
| `TenantCreditService` | 积分/配额服务 |
| `TenantMemberService` | 成员管理服务 |
| `OAuthService` | 第三方登录服务 |
| `PaymentService` | 支付服务 |
| `AuditService` | 操作审计服务 |

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

## 许可证

MIT License
