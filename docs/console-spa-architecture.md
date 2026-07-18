# Console SPA 架构设计文档

> 版本: v2.8.0 | 日期: 2025-07-18

## 1. 设计原则

| 原则 | 说明 |
|------|------|
| **框架主导** | 框架提供完整 SPA 基础设施（main.ts / router / module-loader / layouts / vite.config），项目不重复建设 |
| **源码级扩展** | 项目通过引用框架源码（vendor 路径）消费 SPA，而非编译产物；Vite 编译时 glob 需要源码级访问 |
| **模块即开发** | 项目只需按约定目录结构开发模块（`src/Modules/`），框架自动发现并集成 |
| **覆盖可选** | 项目可选择覆盖框架任意层级（布局/视图/Store），未覆盖则使用框架默认实现 |
| **多 UI 框架** | 全链路支持 Element Plus / Bootstrap 等多套 UI 框架，运行时切换 |

## 2. 架构分层

```
┌─────────────────────────────────────────────────────────┐
│                    项目层 (scrm-platform)                │
│                                                         │
│  vite.config.ts ──┐                                     │
│  main.ts (8行)   ─┤── 薄 wrapper，委托到框架            │
│  module-loader.ts ─┘  (5行 re-export)                   │
│                                                         │
│  src/Modules/*/resources/console/                       │
│    ├── routes.ts          模块路由定义                    │
│    └── ui/element-plus/views/  模块视图                  │
│                                                         │
│  resources/pages/console/ui/                            │
│    └── element-plus/layouts/ConsoleLayout.vue  布局覆盖  │
│    └── element-plus/views/Dashboard.vue       视图覆盖  │
├─────────────────────────────────────────────────────────┤
│              框架层 (multi_tenant_saas vendor)           │
│                                                         │
│  resources/js/console/                                  │
│    ├── main.ts          Vue 应用初始化 + UI 框架注册     │
│    ├── router/index.ts  路由注册 + 自动发现模块路由       │
│    ├── module-loader.ts glob 扫描 + view() + 导航构建   │
│    └── stores/user.ts   用户状态管理                     │
│                                                         │
│  resources/pages/console/                               │
│    ├── index.html       入口 HTML                       │
│    ├── App.vue          根组件                          │
│    └── ui/*/            框架默认布局 + 视图              │
│        ├── bootstrap/layouts/ConsoleLayout.vue          │
│        ├── bootstrap/views/{Dashboard,Login}.vue        │
│        ├── element-plus/layouts/ConsoleLayout.vue       │
│        └── element-plus/views/{Dashboard,Login}.vue     │
└─────────────────────────────────────────────────────────┘
```

## 3. 加载链路

### 3.1 编译时

```
项目 vite.config.ts
  ├── root → 项目根目录
  ├── build.rollupOptions.input → vendor/.../index.html (框架入口)
  └── resolve.alias
      ├── @/ → 项目 resources/js/ (项目 stores/views)
      ├── @/modules → 项目 src/Modules (模块目录)
      └── @multi-tenant-saas/* → vendor 框架资源
```

Vite 编译时通过 `import.meta.glob` 扫描以下目录：

| glob 模式 | 扫描位置 | 用途 |
|-----------|----------|------|
| `/src/Modules/*/resources/console/routes.ts` | 项目根 | 加载模块路由定义 |
| `/src/Modules/*/resources/console/ui/*/views/*.vue` | 项目根 | 自动发现模块视图 |
| `/vendor/dsplat/*/resources/console/ui/*/views/*.vue` | 项目根/vendor | 发现独立包模块视图 |
| `/resources/pages/console/ui/*/layouts/*.vue` | 项目根 | 发现本地布局覆盖 |
| `/vendor/.../resources/pages/console/ui/*/layouts/*.vue` | 项目根/vendor | 发现框架默认布局 |

### 3.2 运行时

```
index.html
  → main.ts (项目 wrapper)
    → vendor/.../main.ts (框架)
      → initUICore() + registerAllFrameworks()
      → loadFramework(activeFw)
      → createApp(App) → install UI framework
      → router (vendor/.../router/index.ts)
        → resolveLayout('ConsoleLayout')
          → 优先级: localFw → vendorFw → localBs → vendorBs
        → loadModuleRoutes()
          → 扫描 routes.ts → 注入 meta.module → 注册子路由
        → loadModuleViews()
          → glob 扫描 .vue → 按优先级注册自动路由
      → app.mount('#app')
```

## 4. 模块开发规范

### 4.1 目录结构

```
src/Modules/{moduleName}/resources/console/
├── routes.ts                          # 路由定义（可选）
└── ui/{uiFramework}/views/           # 视图文件
    ├── CustomerList.vue
    ├── CustomerDetail.vue
    └── subfolder/SomeView.vue
```

### 4.2 routes.ts 编写规范

```typescript
import type { RouteRecordRaw } from 'vue-router'
import { view } from '@/console/module-loader'

const routes: RouteRecordRaw[] = [
  {
    path: 'customers',
    name: 'CustomerList',
    component: view('customer', 'customers/CustomerList'),
    meta: { title: '客户管理' },
  },
  {
    path: 'customers/:id',
    name: 'CustomerDetail',
    component: view('customer', 'customers/CustomerDetail'),
    meta: { title: '客户详情' },
  },
]

export default routes
```

**关键 API:**

- `view(moduleName, viewPath)` — 框架感知视图解析器
  - 按当前 UI 框架查找视图，找不到则 fallback 到 `element-plus`
  - `moduleName`: 模块目录名（如 `customer`）
  - `viewPath`: 相对于 `ui/{fw}/views/` 的路径（如 `customers/CustomerList`）

### 4.3 meta 字段

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `title` | string | 是 | 侧边栏显示名称 + 面包屑 |
| `module` | string | 否 | 模块分组标识（自动从目录提取） |
| `requiresAuth` | boolean | 否 | 是否需要认证（默认 true） |

## 5. 覆盖机制

### 5.1 布局覆盖

项目在 `resources/pages/console/ui/{fw}/layouts/` 放置同名文件即可覆盖框架布局：

```
resources/pages/console/ui/element-plus/layouts/ConsoleLayout.vue  ← 覆盖框架默认
```

路由解析优先级链：`localFw → vendorFw → localBs → vendorBs`

### 5.2 视图覆盖

项目在 `resources/pages/console/ui/{fw}/views/` 放置同名文件：

```
resources/pages/console/ui/element-plus/views/Dashboard.vue  ← SCRM 定制仪表盘
```

### 5.3 Store 覆盖

项目提供自己的 store 文件，通过 `@/` alias 优先于框架：

```
resources/js/console/stores/user.ts  ← 项目自定义（如添加 platform_admin 权限）
```

## 6. 多 UI 框架支持

### 6.1 视图文件组织

```
src/Modules/customer/resources/console/ui/
├── element-plus/views/CustomerList.vue
└── bootstrap/views/CustomerList.vue    # 可选，不写则 fallback element-plus
```

### 6.2 运行时切换

用户通过 UI 框架选择器切换，存储在 `localStorage.multi-tenant-saas-ui-framework`：
- `element-plus` — Element Plus 组件库
- `bootstrap` — Bootstrap + 自定义主题

切换后侧边栏、布局、视图全部按新框架重新解析。

### 6.3 侧边栏自动发现

`getConsoleNavSections()` 从已注册路由动态构建侧边栏分组：
- 来源1: `routes.ts` 中有 `meta.title` 的路由（优先，中文标签）
- 来源2: glob 扫描的 `.vue` 视图（自动推导标签）
- 按 `meta.module` 分组，`MODULE_LABELS` 提供中文映射
- 无硬编码菜单项，新增模块自动出现在侧边栏

## 7. 项目集成清单

项目接入框架 SPA 最少需要以下文件：

```
项目根/
├── resources/js/console/
│   ├── vite.config.ts     ← Vite 配置（必须，~100行）
│   ├── main.ts            ← 薄 wrapper（8行，import 框架 main）
│   ├── module-loader.ts   ← 薄 re-export（5行）
│   └── stores/user.ts     ← 可选覆盖
├── resources/pages/console/ui/
│   └── {fw}/              ← 可选布局/视图覆盖
└── src/Modules/            ← 模块开发目录
```

## 8. 关键文件索引

| 文件 | 归属 | 行数 | 职责 |
|------|------|------|------|
| `vite.config.ts` | 项目 | ~100 | Vite 编译配置，路径 alias 解析 |
| `main.ts` | 项目→框架 | 8 | 入口 wrapper |
| `module-loader.ts` | 项目→框架 | 5 | re-export wrapper |
| `main.ts` | 框架 | ~104 | Vue 初始化 + UI 框架注册 |
| `router/index.ts` | 框架 | ~108 | 路由注册 + 自动发现 + 守卫 |
| `module-loader.ts` | 框架 | ~380 | glob 扫描 + view() + 导航构建 |
| `stores/user.ts` | 框架 | ~100 | 用户认证状态管理 |
| `ConsoleLayout.vue` | 框架×2 | ~163 | Bootstrap 布局（自发现侧边栏） |
| `ConsoleLayout.vue` | 框架×2 | ~140 | Element Plus 布局（自发现侧边栏） |
