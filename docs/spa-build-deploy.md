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

# Console 后台（默认 Element Plus）
cd resources/js/console
npm install
npm run dev
```

开发服务器会代理 `/api` 请求到 `http://localhost:8000`（Laravel 后端）。
Admin 开发服务器默认运行在 `:5174`，Console 在 `:5173`。

## 生产构建

### 构建命令

```bash
# Admin 后台
cd resources/js/admin
npx vite build

# Console 后台
cd resources/js/console
npx vite build
```

### 构建产物

- Admin → `public/admin/` (含 `index.html` + 静态资源)
- Console → `public/console/` (含 `index.html` + 静态资源)
- Vite 配置中 `flatten-index-html` 插件自动扁平化输出路径

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
resources/
├── pages/                              # SPA 页面与 UI 框架
│   ├── admin/
│   │   ├── App.vue                     # Admin 根组件
│   │   ├── index.html                  # Vite 入口 HTML
│   │   └── ui/
│   │       ├── element-plus/           # Element Plus UI 框架
│   │       │   ├── layouts/            # 布局组件
│   │       │   └── views/             # 页面组件
│   │       └── bootstrap/             # Bootstrap UI 框架
│   │           ├── layouts/            # 布局组件
│   │           └── views/             # 页面组件
│   ├── console/
│   │   ├── App.vue                     # Console 根组件
│   │   ├── index.html                  # Vite 入口 HTML
│   │   └── ui/
│   │       ├── element-plus/           # Element Plus UI 框架
│   │       │   ├── layouts/            # 布局组件
│   │       │   └── views/             # 页面组件
│   │       └── bootstrap/             # Bootstrap UI 框架
│   │           ├── layouts/            # 布局组件
│   │           └── views/             # 页面组件
│   └── ui-core/                        # 共享 UI 核心（组件、主题）
└── js/                                 # SPA 构建配置与运行时
    ├── admin/
    │   ├── main.ts                     # Admin 入口
    │   ├── module-loader.ts            # 模块自动发现
    │   ├── router/                     # 路由
    │   ├── stores/                     # Pinia 状态
    │   └── vite.config.ts              # Vite 配置
    ├── console/
    │   ├── main.ts                     # Console 入口
    │   ├── module-loader.ts            # 模块自动发现
    │   ├── router/                     # 路由
    │   ├── stores/                     # Pinia 状态
    │   └── vite.config.ts              # Vite 配置
    └── ui-core/                        # 共享 UI 核心（类型、工具）

src/Modules/*/resources/
├── admin/ui/element-plus/views/*.vue  # 模块 Admin 页面（自动发现）
└── console/ui/element-plus/views/*.vue # 模块 Console 页面（自动发现）
```

**目录隔离原则**：Element Plus 和 Bootstrap 页面完全隔离在各自的 `ui/{framework}/` 目录下，通过 `module-loader.ts` 的路径解析自动发现当前框架的页面组件。
