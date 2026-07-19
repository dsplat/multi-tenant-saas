# SPA 架构设计文档

> 版本: v2.9.0 | 日期: 2026-07-19

## 0. 三种 SPA 模式总览

框架提供三套 SPA，分别采用两种引入机制：

| SPA | 路径 | 引入模式 | 理由 |
|------|------|---------|------|
| **Public** | `/` | **Scaffold** — `vendor:publish` 拉取后项目自有 | 100% 下游会定制 Landing 页，继承覆盖是多余负担 |
| **Console** | `/console/` | **继承** — alias 引用 vendor | 共享 router/stores/module-loader 基础设施 |
| **Admin** | `/admin/` | **继承** — alias 引用 vendor | 共享框架管理后台基础设施 |

**决策依据**：
- 继承模式适合「多数项目不修改」的基础设施（Console/Admin 的路由、状态管理、布局）
- Scaffold 模式适合「100% 项目会定制」的面向用户页面（Public 的 Landing/Login/Register 等品牌强相关页面）

**引入路径统一性**：
- `composer create-project` 创建的项目直接包含 Public SPA 源码
- `composer require` 安装框架的项目通过 `php artisan vendor:publish --tag=dsplat-public-spa` 拉取
- 两种路径最终效果一致：项目拥有完整的 Public SPA 源码控制权

---

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

---

## 9. Public SPA Scaffold 模式

### 9.1 设计原则

Public SPA（面向终端用户的 `/` 路径下的页面：首页、登录、注册、申请团队、忘记密码等）采用 **Scaffold 模式**，而非 Console/Admin 的继承模式。

| 原则 | 说明 |
|------|------|
| **项目完全自有** | 框架通过 `vendor:publish` 把源码一次性交付给项目，项目拥有完整控制权 |
| **零耦合** | 拉取后与框架解耦，可自由修改任意文件，不受框架版本演进影响 |
| **明确边界** | Public SPA 的演进由项目主导，框架不再通过继承覆盖机制介入 |
| **渐进式定制** | 框架提供可用的默认实现，项目按需替换页面或字段配置 |

**为何不沿用继承模式？**
- Console/Admin 的继承模式假设「多数项目不修改」，通过 alias 引用 vendor 共享基础设施
- Public SPA 的 Landing 页 100% 项目会定制品牌、文案、视觉，继承 + 覆盖机制是多余的复杂度
- Scaffold 一次拉取、长期自主，避免了「框架升级时覆盖了项目定制」的风险

### 9.2 vendor:publish 机制

在 `src/TenancyServiceProvider.php` 的 `boot()` 中注册发布标签：

```php
$this->publishes([
    __DIR__.'/../resources/pages/public' => resource_path('pages/public'),
    __DIR__.'/../resources/js/public' => resource_path('js/public'),
], 'dsplat-public-spa');
```

下游项目执行：
```bash
php artisan vendor:publish --tag=dsplat-public-spa
```

源码即被复制到项目的 `resources/{pages,js}/public/`，之后完全自主。

### 9.3 两条引入路径

| 场景 | Public SPA 源码来源 |
|------|---------------------|
| `composer create-project dsplat/multi-tenant-saas my-app` | 项目模板直接包含源码 |
| `composer require dsplat/multi-tenant-saas` + `vendor:publish --tag=dsplat-public-spa` | 从框架 vendor 拉取 |

两种路径最终效果一致：项目拥有完整的 Public SPA 源码控制权。

### 9.4 项目集成清单

```
项目根/
├── resources/js/public/
│   ├── vite.config.ts     ← Vite 配置（base: '/'，outDir: public/）
│   ├── main.ts            ← Vue 应用初始化 + 路由注册
│   ├── package.json       ← 依赖声明（vue, vue-router, element-plus）
│   └── node_modules/      ← 项目自管（不通过 vendor）
├── resources/pages/public/
│   ├── index.html         ← 入口 HTML（含 §10 防闪烁注入）
│   ├── App.vue            ← 根组件（导航栏 + router-view）
│   └── views/             ← 页面组件
│       ├── index.vue      ← 首页（路由 name: 'home'）
│       ├── Login.vue
│       ├── Register.vue
│       ├── Apply.vue      ← 申请团队（onMounted 校验登录态）
│       └── ...
└── public/                ← 构建产物（vite build 输出）
    ├── index.html
    └── assets/
```

---

## 10. 首屏防闪烁三层注入机制

### 10.1 问题背景

SPA 首屏渲染流程：
1. 浏览器解析 HTML（含 `<title>` 静态值）
2. 加载 Vue 主 bundle（异步）
3. Vue 挂载、组件 `onMounted` 触发 `fetch('/api/v1/public/site-config')`
4. fetch 返回后更新 `siteConfig.value`，品牌名从 fallback 变成 API 值

**闪烁现象**：步骤 1-3 之间，页面先显示 fallback 默认值（如 'Multi-Tenant SaaS'），fetch 返回后才被覆盖成实际值（如 'SCRM Platform'）。在网络慢时尤为明显。

### 10.2 三层注入方案

| 层 | 时机 | 作用 |
|----|------|------|
| **① index.html inline script** | HTML 解析阶段，同步执行 | 预注入 `window.__SITE_CONFIG__`，Vue 加载前就绪 |
| **② localStorage 缓存** | fetch 成功后写入，下次访问读取 | 二次访问首屏同步可用正确值 |
| **③ 组件初始值** | Vue 组件 setup 阶段 | `ref<any>((window as any).__SITE_CONFIG__ \|\| {})` 同步读取 |

### 10.3 index.html inline script

```html
<body>
  <script>
    (function () {
      try {
        var cached = localStorage.getItem('__site_config__');
        if (cached) {
          window.__SITE_CONFIG__ = JSON.parse(cached);
        }
      } catch (e) {}
      if (!window.__SITE_CONFIG__) {
        // 兜底默认值：框架用 'Multi-Tenant SaaS'，项目改为自己的品牌名
        window.__SITE_CONFIG__ = { platform_name: 'SCRM Platform', registration_enabled: true, apply_enabled: true };
      }
    })();
  </script>
  <div id="app"></div>
  <script type="module" src="/resources/js/public/main.ts"></script>
</body>
```

关键点：
- inline `<script>` 是同步执行的普通脚本，不阻塞 HTML 解析但先于主 bundle 完成
- 优先读 localStorage（上次 fetch 缓存的正确值），兜底才是硬编码默认值
- 首次访问：读不到 localStorage，用兜底默认值（已是正确品牌名，无闪烁）
- 二次访问：读到 localStorage 缓存，直接用 API 返回的正确值

### 10.4 组件初始值同步读取

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'

// 初始值优先读 index.html 预注入的 window.__SITE_CONFIG__，避免首屏闪烁
const siteConfig = ref<any>((window as any).__SITE_CONFIG__ || {})

onMounted(async () => {
  try {
    const res = await fetch('/api/v1/public/site-config')
    const data = await res.json()
    if (data.success) {
      siteConfig.value = data.data
      // 缓存到 localStorage，下次首屏即可同步读取
      try { localStorage.setItem('__site_config__', JSON.stringify(data.data)) } catch {}
    }
  } catch {}
})
</script>
```

关键点：
- `ref` 初始值直接读 `window.__SITE_CONFIG__`，setup 阶段同步完成
- `onMounted` 仍然 fetch 更新配置（用于动态调整 + 写 localStorage 缓存）
- 即使 fetch 失败，首屏已显示正确值（来自 inline 注入或 localStorage）

### 10.5 时序图

```
浏览器加载 HTML
  ├─ 解析 <title>SCRM Platform</title>  ← 静态标题已正确
  ├─ 执行 inline <script>  ← 同步：window.__SITE_CONFIG__ 就绪
  │    ├─ 优先读 localStorage 缓存
  │    └─ 兜底默认值 { platform_name: 'SCRM Platform' }
  ├─ 加载主 bundle JS（异步）
  └─ Vue 挂载
       ├─ App.vue setup: siteConfig = ref(window.__SITE_CONFIG__)  ← 同步读取，已是正确值
       ├─ 首屏渲染：品牌名直接显示 'SCRM Platform'  ← 无闪烁
       └─ onMounted: fetch /api/v1/public/site-config
            ├─ 成功：更新 siteConfig + 写 localStorage（下次访问用）
            └─ 失败：保持 inline 注入值，首屏仍正确
```

### 10.6 兜底默认值约定

| 项目阶段 | `index.html` 兜底 `platform_name` |
|---------|----------------------------------|
| 框架（multi_tenant_saas） | `'Multi-Tenant SaaS'` |
| 下游项目（如 scrm-platform） | `'SCRM Platform'` |

项目通过 `vendor:publish` 拉取 Public SPA 源码后，需把 `index.html` 中的兜底默认值改为自己的品牌名。这是 Scaffold 模式的明确约定：项目拥有 `index.html` 完全控制权。
