# Multi-Tenant SaaS 框架

Laravel 多租户 SaaS 基础框架 — 开箱即用的企业级项目骨架。

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-%5E8.3-777BB4)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-%5E13.0-FF2D20)](https://laravel.com)
[![Tests](https://img.shields.io/badge/tests-2379%20tests-brightgreen)](#)

[文档](docs/zh/README.md) | [快速开始](docs/zh/guides/quickstart.md) | [SPA 架构](docs/spa-architecture.md) | [更新日志](CHANGELOG.md) | [English](docs/en/README.md)

---

## 核心特性

- **四层权限体系**：系统管理员 → 租户管理员 → 终端用户 → 访客
- **租户隔离**：所有查询自动 `WHERE tenant_id = ?`（fail-closed：无上下文时 WHERE 1=0）
- **RBAC 权限**：60+ 权限节点，每租户自定义角色
- **SPA 后台**：27 个 Admin 页面 + 12 个 Console 页面，支持暗色模式 + 主题切换
- **模块自动发现**：Vue 页面放在 `src/Modules/*/resources/{admin,console}/views/` 自动注册到侧边栏
- **多 UI 框架**：每个页面支持 Bootstrap 和 Element Plus 两套变体
- **30 个模块**：计费、认证、表单、抽奖、投票、短信、优惠券、工作流、对话、AI Agent 等
- **20 个接口**：面向接口架构，下游项目可自由扩展
- **领域异常体系**：DomainException 基类 + 11 个具体异常，携带 HTTP 状态码
- **领域事件**：14 个事件 + 6 个监听器，覆盖租户/Agent/Tool 生命周期
- **架构守卫**：pre-commit 钩子自动检查大小写、命名空间、RuntimeException 禁用
- **认证增强**：支持企业微信 OAuth、支付宝 OAuth、SSO 等多种登录方式
- **租户域名解析**：支持多域名自动识别租户

---

## 快速开始

```bash
composer create-project dsplat/multi-tenant-saas my-app
cd my-app

cp .env.example .env
php artisan key:generate
# 编辑 .env：DB_*、ADMIN_DOMAIN

php artisan migrate
php artisan platform:init --email=admin@example.com --password=your-password

# 构建前端
cd resources/js/admin && npm install && npx vite build && cd ../../..
cd resources/js/console && npm install && npx vite build && cd ../../..

php artisan serve
```

**默认账号：**
- Admin 后台：`admin@platform.local` / `admin123456`
- Console 后台：`admin@test.com` / `password`

---

## SPA 后台

### Admin 系统后台 — 27 个页面

| 分组 | 页面 |
|------|------|
| 概览 | 仪表盘、租户管理、运营人员、角色权限、订阅计划 |
| 平台配置 | 模块管理、插件管理、功能开关、品牌配置、SSO、系统设置、数据保留、沙箱、配置中心 |
| 租户管理 | 用户、域名、OAuth、审计、短信、支付、Token、配额、积分、SSL、Webhooks、IP白名单、租户密钥、合规 |

### Console 租户后台 — 12 个页面

| 分组 | 页面 |
|------|------|
| 概览 | 工作台 |
| 团队与财务 | 成员管理、积分管理 |
| 集成与配置 | 第三方登录、支付配置、短信配置、API Token |
| 自动化与安全 | 工作流、SSL 证书、Webhooks |
| 设置 | 邮件/认证/注册 |

### 主题系统

- 浅色/暗色模式切换
- 颜色选择器（强调色贯穿所有 UI）
- CSS 变量定义在 `:root`，`html.dark` 全局覆盖
- 所有 badge/链接/表格颜色使用 CSS 变量

---

## 模块架构

```
src/Modules/{Name}/
├── {Name}ServiceProvider.php    ← 继承 ModuleServiceProvider
├── composer.json                ← extra.saas 配置
├── Http/Controllers/
├── Services/
├── Models/
├── Routes/
│   ├── api.php                  → /api/v1/...  (需认证 + 租户)
│   ├── admin.php                → /v1/admin/... (需认证)
│   └── tenant.php               → /tenant/... (需认证)
└── resources/
    ├── admin/views/*.vue        → 自动发现，侧边栏显示
    └── console/views/*.vue      → 自动发现，侧边栏显示
```

**完整示例**：参考 `src/Modules/Ticket/` — 从数据库迁移、模型、服务、控制器、路由到前端页面的完整工作流。

---

## 文档

| 分类 | 链接 |
|------|------|
| **指南** | [快速开始](docs/zh/guides/quickstart.md) · [RBAC](docs/zh/guides/rbac-guide.md) · [AI 模块](docs/zh/guides/ai-module-guide.md) |
| **架构** | [系统概览](docs/zh/architecture/system-overview.md) · [SPA 架构](docs/spa-architecture.md) · [租户隔离](docs/zh/architecture/tenant-isolation.md) |
| **部署** | [部署指南](docs/zh/deployment/deployment-guide.md) · [Nginx](docs/zh/deployment/nginx-guide.md) |
| **API** | [API 概览](docs/zh/api/api-overview.md) · [核心 API](docs/zh/api/core-api.md) |
| **完整索引** | [docs/README.md](docs/README.md) |

---

## AI KB 索引维护（强制）

AI 小助手的导航与工具调用能力依赖自动生成的 KB 索引（路由地图 / 工具目录 / API 功能图），由框架内置命令从 routes.ts 与 ToolRegistry 实时提取，**禁止手写**：

```bash
php artisan secretary:kb:index          # 生成/刷新索引
php artisan secretary:kb:index --check  # 检查是否过期（可接入 CI）
```

**下游项目接入（与部署工具无关的默认路径）**：在项目 `composer.json` 添加钩子，任何部署方式只要跑 `composer install/update` 即自动刷新：

```json
"scripts": {
    "post-update-cmd": ["@php artisan secretary:kb:index --ansi || true"],
    "post-install-cmd": ["@php artisan secretary:kb:index --ansi || true"]
}
```

开发纪律：改动 `resources/console/routes.ts`、工具注册或 `module-loader.ts` 后必须重新生成；pre-commit 钩子（`scripts/install-hooks.sh` 安装）会在触发文件变更时自动提醒。解析器正确性由守恒测试 `tests/ConsoleRouteMapGeneratorTest.php` 保障。

---

## 技术栈

PHP ^8.3 · Laravel ^13.0 · MySQL 8.0+ · Redis · Nginx + PHP-FPM · Vue.js 3 + TypeScript + Vite

## 测试

```bash
composer test              # 并行测试（~50s，2379 tests，5039 assertions）
composer test:sequential   # 单线程回退
vendor/bin pint --test     # 代码风格检查
```

## 许可证

MIT
