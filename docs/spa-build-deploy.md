# SPA 构建与部署指南

## 概述

框架提供 Admin 和 Console 两个 SPA 后台，支持 Element Plus 和 Bootstrap 5 两种 UI 风格。

## 开发环境

### 启动开发服务器

```bash
# Admin 后台（默认 Element Plus）
cd resources/js/admin
npm install
npm run dev

# Console 后台（默认 Bootstrap）
cd resources/js/console
npm install
npm run dev
```

### 指定 UI 框架启动

```bash
# Admin 使用 Bootstrap
npm run dev:bootstrap

# Admin 使用 Element Plus
npm run dev:element-plus

# Console 使用 Element Plus
npm run dev:element-plus
```

开发服务器会代理 `/api` 请求到 `http://localhost:8000`（Laravel 后端）。

## 生产构建

### 构建命令

```bash
# Admin 后台
cd resources/js/admin
npm run build              # 使用默认框架
npm run build:element-plus # 使用 Element Plus
npm run build:bootstrap    # 使用 Bootstrap

# Console 后台
cd resources/js/console
npm run build              # 使用默认框架
npm run build:element-plus # 使用 Element Plus
npm run build:bootstrap    # 使用 Bootstrap
```

### 构建产物

- Admin → `public/admin/`
- Console → `public/console/`

## 运行时切换 UI 框架

用户可以在 **设置 → UI 框架** 页面切换框架风格，切换后页面自动刷新。

切换原理：选择的框架保存在 `localStorage.multi-tenant-saas-ui-framework`，页面加载时读取并动态导入对应框架。

## 项目侧集成

### 通过 Composer 获取框架

```bash
composer require vendor/multi-tenant-saas
```

框架发布后，项目侧 `composer update` 即可获取最新的 SPA 构建产物。

### 发布资源

```bash
php artisan vendor:publish --tag=tenancy-config
php artisan vendor:publish --tag=tenancy-migrations
```

### 自定义菜单

在 `config/tenancy.php` 中配置：

```php
return [
    // Admin 后台额外菜单项
    'admin_menu' => [
        [
            'name' => '业务管理',
            'path' => '/admin/business',
            'icon' => 'business',
            'order' => 15,
            'permission' => null,
        ],
    ],

    // Console 后台额外菜单项
    'console_menu' => [
        [
            'name' => '我的业务',
            'path' => '/console/business',
            'icon' => 'business',
            'order' => 25,
            'permission' => null,
        ],
    ],

    // Admin Dashboard 统计卡片
    'admin_dashboard_cards' => [
        ['label' => '今日订单', 'value' => 0, 'key' => 'today_orders'],
    ],

    // Console Dashboard 统计卡片
    'console_dashboard_cards' => [
        ['label' => '本月调用', 'value' => 0, 'key' => 'monthly_calls'],
    ],
];
```

## 环境变量

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `VITE_UI_FRAMEWORK` | 默认 UI 框架 | `element-plus` (admin) / `bootstrap` (console) |

## 目录结构

```
src/Modules/
├── Admin/                      # Admin 模块
│   ├── resources/admin/        # Admin SPA 源码
│   │   ├── layouts/            # 布局组件
│   │   ├── views/              # 页面组件
│   │   ├── router/             # 路由
│   │   ├── stores/             # Pinia 状态
│   │   ├── main.ts             # 入口
│   │   └── vite.config.ts      # Vite 配置
│   ├── Routes/admin.php        # Admin API 路由
│   └── composer.json           # 模块配置
├── Console/                    # Console 模块
│   ├── resources/console/      # Console SPA 源码
│   │   └── (同 Admin 结构)
│   ├── Routes/api.php          # Console API 路由
│   └── composer.json           # 模块配置
└── ...

resources/js/
└── ui-core/                    # 共享 UI 核心（框架级）
    ├── adapters/               # UI 框架适配器
    ├── components/             # 通用组件
    ├── themes/                 # 主题配置
    └── registry.ts             # 框架注册表
```

**模块化原则**：Admin/Console 模块未安装时，其 SPA 资源不存在，不会被构建。
