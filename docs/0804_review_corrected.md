# 多租户 SaaS 框架完整性审计（修正版）

> 审计日期：2026-08-04
> 范围：993 个 PHP 文件、32 个模块、618 个 API 端点、239 个测试文件 / 2910 个测试方法
> 对原版 0804_review.md 的勘误标注：~~删除线~~ = 原声明错误

---

## 一、精确统计（与原版对比）

| 指标 | 原版声明 | 实际值 | 判定 |
|------|---------|--------|------|
| PHP 文件数 | 993 | **993** | ✅ |
| 模块数 | 33 | **32** | ❌ 多数 1 |
| API 端点 | 400+ | **618** | 低估 |
| 测试数 | 2379 | 文件 **239** / 方法 **2910** | ❌ 无法溯源 |

---

## 二、模块完整性矩阵（32 个模块）

| 组件 | 有 | 缺 | 覆盖率 |
|------|-----|-----|--------|
| ServiceProvider | 32/32 | — | 100% |
| Routes | 31/32 | Contracts | 97% |
| Controllers | 25/32 | Contracts, Conversation, DeveloperPortal, Event, Monitoring, Plugin, Workflow | 78% |
| Models | 24/32 | AiStreaming, Contracts, DeveloperPortal, Domain, Payment, Plugin, SSL, User | 75% |
| Migrations | 23/32 | AiStreaming, Contracts, DeveloperPortal, Domain, Form, Payment, Plugin, SSL, User | 72% |
| Views/Resources | 31/32 | Commerce | 97% |

---

## 三、原版错误勘误

### 3.1 P0 声明勘误

| 原版声明 | 实际情况 | 判定 |
|---------|---------|------|
| ~~SPA 认证中间件适配未实现~~ | `CheckPermission` 已处理：`expectsJson()` + admin/console/api 域名类型均返回 JSON 401 | **已实现** |
| ~~System Secretary 零实现~~ | `system_secretary` 角色已在 AssistantController、Agent 模型、ResolveController、ProcessIbotInboundMessage 等多处实现 | **部分实现** |

### 3.2 P1 声明勘误

| 原版声明 | 实际情况 | 判定 |
|---------|---------|------|
| ~~Admin 动态菜单 API 缺失~~ | `AdminMenuController` + `ConsoleMenuController` 已存在，支持 config 覆盖 + 权限过滤，**但未注册路由** | **代码有，路由未挂载** |
| ~~Dashboard 数据 API 缺失~~ | `AdminDashboardController` + `ConsoleDashboardController` 已存在，**但未注册路由** | **代码有，路由未挂载** |
| ~~7 个模块缺少 Controllers~~ | 实际 6 个模块（不含 Contracts）：Conversation, DeveloperPortal, Event, Monitoring, Plugin, Workflow | 数字偏差 |

### 3.3 遗漏模块

| 模块 | PHP 文件数 | Models | Controllers | 说明 |
|------|-----------|--------|-------------|------|
| **Infrastructure** | 88 | 14 | 10 | 原版完全遗漏。含 Tenant/TenantUser/Webhook/FeatureFlag/BrandingConfig 等核心模型 |
| **Contracts** | 1 | 0 | 0 | 原版遗漏。纯接口模块 |

---

## 四、确认存在的问题清单

### P0 — 阻塞功能可用

| # | 问题 | 影响 | 状态 |
|---|------|------|------|
| 1 | ~~4 个 Controller 未注册路由~~ | AdminMenuController, ConsoleMenuController, AdminDashboardController, ConsoleDashboardController | ✅ **已修复** — 路由已注册到 `routes/api.php` |
| 2 | ~~McpClientController 未注册路由~~ | 死代码，无引用 | ✅ **已修复** — 5 个 CRUD 路由已注册 |
| 3 | ~~Form 模块缺 Migrations~~ | 有 3 个 Models 但无数据库 Schema | ✅ **已修复** — 创建 `2026_08_04_000001_form_module.php` |

### P1 — 影响管理后台体验

| # | 问题 | 影响 |
|---|------|------|
| 4 | **通用 CRUD 组件缺失** | CrudTable, CrudForm, DetailPanel, StatsCard 均不存在，管理页面重复造轮子 |
| 5 | **Commerce 模块无 Views** | 唯一无前端资源的业务模块 |

### P2 — 模块完整性

| # | 问题 | 说明 |
|---|------|------|
| 6 | 6 个模块无 Controllers | Conversation, DeveloperPortal, Event, Monitoring, Plugin, Workflow（路由用闭包替代） |
| 7 | Domain/Payment/SSL/User 无 Models | 直接用 DB::table() 查询，缺 Eloquent 封装 |
| 8 | AiModelEnum TODO | 模型废弃追踪未实现 |
| 9 | ApiTokenService TODO | API Token 用量查询未实现 |

### P3 — 安全/质量

| # | 问题 | 说明 |
|---|------|------|
| 10 | localStorage 存储 admin_token | `resources/js/admin/stores/user.ts` L15/L34，XSS 风险 |

---

## 五、端点分布统计

| 模块 | 端点数 | 模块 | 端点数 |
|------|--------|------|--------|
| Ai | 54 | Operator | 36 |
| Infrastructure | 70 | Auth | 65 |
| Billing | 31 | Commerce | 33 |
| User | 48 | Coupon | 20 |
| Lottery | 18 | Sms | 16 |
| Notification | 16 | Campaign | 14 |
| Ticket | 9 | Voting | 9 |
| Form | 9 | Ibot | 12 |
| Domain | 25 | SSL | 10 |
| Storage | 22 | Knowledge | 5 |
| Logging | 4 | ApiToken | 10 |
| Platform | 13 | Payment | 15 |
| Plugin | 5 | Monitoring | 4 |
| Workflow | 7 | Event | 1 |
| Conversation | 2 | DeveloperPortal | 4 |

---

## 六、TODO 清单（仅 2 处）

| 文件 | 行号 | 内容 |
|------|------|------|
| `src/Enums/AiModelEnum.php` | 103 | 模型废弃追踪 |
| `src/Modules/ApiToken/Services/ApiTokenService.php` | 383 | API Token 用量查询 |
