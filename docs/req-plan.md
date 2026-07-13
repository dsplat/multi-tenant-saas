# multi-tenant-saas 框架 Admin/Console SPA 完善计划

## 目标

框架作为多租户 SaaS 的基础设施，Admin 后台和 Console 后台应从娘胎里自带完整的管理能力。使用框架的项目只需关注自己的业务模块，无需重复实现登录、权限、租户管理等通用功能。

---

## 一、框架现状

### Admin SPA（14 个页面）

| 页面 | 文件 | 后端 API |
|-----|------|---------|
| Dashboard | `admin/views/Dashboard.vue` | 需补充 |
| Login | `admin/views/Login.vue` | 缺 admin 登录 API |
| Users | `admin/views/Users.vue` | 需确认 |
| Tenants | `admin/views/Tenants.vue` | 需确认 |
| TenantDetail | `admin/views/TenantDetail.vue` | 需确认 |
| ApiTokens | `admin/views/ApiTokens.vue` | module-api-token |
| AuditLogs | `admin/views/AuditLogs.vue` | module-logging |
| Settings | `admin/views/Settings.vue` | `GET/PUT /admin/settings` |
| SmsSettings | `admin/views/SmsSettings.vue` | 需确认 |
| OAuthSettings | `admin/views/OAuthSettings.vue` | `admin/auth/sso/providers` |
| DomainSettings | `admin/views/DomainSettings.vue` | 需确认 |
| PaymentOrders | `admin/views/PaymentOrders.vue` | module-payment |
| Quotas | `admin/views/Quotas.vue` | 需确认 |
| Theme Manager | `theme-manager-*.js` | localStorage |

### Console SPA（12 个页面）

| 页面 | 文件 |
|-----|------|
| Dashboard | `console/views/Dashboard.vue` |
| Login | `console/views/Login.vue` |
| Tenants | `console/views/Tenants.vue` |
| TenantDetail | `console/views/TenantDetail.vue` |
| TenantSettings | `console/views/TenantSettings.vue` |
| Members | `console/views/Members.vue` |
| Users | `console/views/Users.vue` |
| ApiTokens | `console/views/ApiTokens.vue` |
| Credits | `console/views/Credits.vue` |
| Settings | `console/views/Settings.vue` |
| SmsSettings | `console/views/SmsSettings.vue` |
| OAuthSettings | `console/views/OAuthSettings.vue` |
| PaymentSettings | `console/views/PaymentSettings.vue` |

### UI 框架支持（8 种适配器）

Bootstrap 5, Element Plus, Ant Design, Arco Design, Naive UI, TDesign, Varlet, Vali Admin

### 主题系统

- 6 种预设主题（默认蓝/清新绿/活力橙/热情红/优雅紫/科技青）
- 明/暗模式切换（支持跟随系统）
- 自定义 CSS 变量、圆角大小
- 主题持久化到 localStorage

---

## 二、框架需完善的工作

### 2.1 Admin/Console 认证 API [P0]

**问题**：框架 auth 模块只有 RBAC 和 SSO 管理路由，缺少基础的登录/登出/用户信息 API。SPA 无法完成认证闭环。

**需要**：

- [ ] `POST /api/v1/admin/auth/login` — 管理员登录（session/cookie 模式）
- [ ] `POST /api/v1/admin/auth/logout` — 管理员登出
- [ ] `GET /api/v1/admin/auth/user` — 获取当前管理员信息（含 role）
- [ ] `POST /api/v1/console/auth/login` — 租户管理员登录
- [ ] `POST /api/v1/console/auth/logout` — 租户管理员登出
- [ ] `GET /api/v1/console/auth/user` — 获取当前用户信息（含 tenant_id、tenant_name）

**登录响应格式**：
```json
{
  "success": true,
  "data": {
    "user": { "id": 1, "name": "...", "email": "...", "role": "super_admin" },
    "token": "..."
  }
}
```

### 2.2 Auth 中间件 SPA 适配 [P0]

**问题**：框架 `Authenticate` 中间件在未认证时重定向到 `route('login')`（Blade 路由），SPA 模式下应返回 JSON 401。

**需要**：

- [ ] `Authenticate` 中间件根据 `domain_type` 判断：
  - `admin` / `console` 域名 → 返回 JSON `401`（SPA 前端处理跳转）
  - 其他域名 → 保持现有行为
- [ ] 或提供可配置的认证失败处理策略

### 2.3 SPA 路由守卫 [P0]

**问题**：Admin/Console SPA 的 `router/index.ts` 缺少全局路由守卫，未登录用户可直接访问任意页面。

**需要**：

- [ ] `admin/router/index.ts` 添加全局 `beforeEach` 守卫
- [ ] `console/router/index.ts` 添加全局 `beforeEach` 守卫
- [ ] 检查认证状态，未认证跳转 Login
- [ ] 支持 `meta.requiresAuth` 标记
- [ ] 登录后正确跳转回原始请求页面

### 2.4 Admin 侧边栏菜单动态化 [P1]

**问题**：Admin SPA 侧边栏菜单硬编码在 `AdminLayout.vue` 中，项目无法注册自定义菜单项。

**需要**：

- [ ] 新增 API `GET /api/v1/admin/menu` — 返回当前用户可见的菜单列表
- [ ] 菜单按权限过滤（基于 RBAC）
- [ ] 菜单数据结构：`{ name, path, icon, children, order }`
- [ ] 支持项目侧通过配置文件或数据库注册菜单项
- [ ] `AdminLayout.vue` 改为从 API 动态加载菜单

### 2.5 Dashboard 数据 API [P1]

**问题**：Dashboard 页面缺少数据源 API。

**需要**：

- [ ] `GET /api/v1/admin/dashboard` — 管理后台概览数据
  - 用户数、租户数、订单数等统计
  - 框架提供骨架，项目侧可注册自定义统计卡片
- [ ] `GET /api/v1/console/dashboard` — 租户后台概览数据
  - 租户内的用户数、资源使用量等

### 2.6 通用 CRUD 组件模板 [P2]

**目的**：降低项目侧扩展新业务页面的成本。

**需要**：

- [ ] `CrudTable.vue` — 通用数据表格（分页/搜索/排序/批量操作）
- [ ] `CrudForm.vue` — 通用表单（创建/编辑/验证）
- [ ] `DetailPanel.vue` — 通用详情面板
- [ ] `StatsCard.vue` — 统计卡片组件
- [ ] 放置于 `ui-core/components/`，所有页面可复用

### 2.7 SPA 构建与部署规范 [P2]

**需要**：

- [ ] 文档说明 SPA 构建流程
- [ ] `npm run build:admin` 和 `npm run build:console` 命令
- [ ] 构建产物输出到 `public/admin/` 和 `public/console/`
- [ ] 项目侧如何通过 composer 获取最新构建产物

---

## 三、执行顺序

```
Phase 1 [P0] — 认证闭环
├── 2.1 Admin/Console 认证 API
├── 2.2 Auth 中间件 SPA 适配
└── 2.3 SPA 路由守卫

Phase 2 [P1] — 管理能力补全
├── 2.4 Admin 侧边栏菜单动态化
└── 2.5 Dashboard 数据 API

Phase 3 [P2] — 扩展性增强
├── 2.6 通用 CRUD 组件模板
└── 2.7 SPA 构建与部署规范
```

---

## 四、验收标准

1. Admin SPA：访问 `/admin` → 未登录自动跳转 Login 页面 → 登录成功进入 Dashboard
2. Console SPA：访问 `/console` → 未登录自动跳转 Login 页面 → 登录成功进入 Dashboard
3. 侧边栏菜单根据用户权限动态显示
4. 所有管理操作通过 JSON API 完成，无 Blade 视图依赖
5. 项目侧可通过框架提供的机制注册自定义菜单和业务页面

---

## 五、注意事项

1. **vendor 目录不可修改** — 所有工作在框架源码仓库完成，发布版本后项目侧 `composer update`
2. **保持向后兼容** — 新增 API 不破坏现有 API 契约
3. **权限中间件** — `CheckPermission` 对 admin 域名只允许 `super_admin`，需确认是否支持多角色 admin 配置
4. **Session vs Token** — Admin/Console 后台建议使用 session（cookie）认证，API 端点使用 token 认证

