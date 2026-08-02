# 框架内部机制详解

> 从源码中提炼的实际运行逻辑、启动流程、数据流。
> **最后更新**: 2026-08-01
> **关联文档**: `docs/zh/architecture/design-principles.md`、`docs/zh/architecture/system-overview.md`

---

## 一、应用启动流程

```
bootstrap/app.php
  ├─ withProviders: TenancyServiceProvider
  ├─ withMiddleware: 中间件栈配置
  └─ withExceptions: 异常渲染链
      │
      ▼
TenancyServiceProvider::register()
  ├─ mergeConfigFrom: tenancy/channel/ai/pay/id/socialite/octane
  ├─ singleton: ModuleRegistry, ModuleManager, ModuleBootstrapper
  ├─ singleton: IdGeneratorContract → IdGenerator
  ├─ singleton: TenantContextContract → TenantContext
  ├─ singleton: TenantConfigStore
  └─ singleton: ChannelManager, ConversationRouter, EventBusBridge, MessageRouter
      │
      ▼
TenancyServiceProvider::boot()
  ├─ loadMigrationsFrom: database/migrations
  ├─ registerCommands: 22 个 Artisan 命令
  ├─ RateLimiter: api(60/min), mcp(120/min)
  ├─ Event::listen: 10 个事件监听器
  └─ ModuleBootstrapper::bootstrap()  ← 模块启动入口
      │
      ▼
ModuleBootstrapper::bootstrap()
  ├─ ModuleManager::getLoadOrder()  ← DB 状态 + 拓扑排序
  ├─ Phase 1: register — 逐模块调 ModuleServiceProvider::register()
  └─ Phase 2: boot — 逐模块调 ModuleServiceProvider::boot()
```

---

## 二、请求生命周期

```
HTTP Request
  │
  ▼
AddSecurityHeaders (prepend)
  ├─ X-Content-Type-Options: nosniff
  ├─ X-Frame-Options: DENY
  ├─ Content-Security-Policy (仅 HTML)
  └─ HSTS (仅 production)
  │
  ▼
IdentifyDomain (prepend)
  └─ 识别域名类型 → TenantContext::setDomainType('admin'|'console'|'api'|'app')
  │
  ▼
BindSessionDomain (prepend)
  └─ 设置 session.domain
  │
  ▼
CastRouteParameters (web/api prepend)
  └─ 路由参数类型转换
  │
  ▼
IdentifyTenant (web/api prepend)
  └─ 从域名/参数/Header 解析租户 → TenantContext::setTenantId()
  │
  ▼
IdentifyOperator (web/api prepend)
  └─ 从 Bearer token 解析 Operator → $request->setUserResolver()
  │
  ▼
[路由匹配 → 中间件栈]
  ├─ auth:sanctum → 认证
  ├─ throttle:api → 限流
  ├─ tenant.identify → 租户识别（模块路由）
  ├─ VerifyOperatorTenant → Operator 租户归属校验
  ├─ rbac.permission → RBAC 权限检查
  └─ tenant.ensure → 强制租户上下文
  │
  ▼
Controller → Service → Model (TenantScope 自动过滤)
  │
  ▼
Response (JSON / StreamedResponse)
```

---

## 三、异常渲染链

```
Throwable
  │
  ├─ ValidationException → 422 { success: false, message, errors }
  │
  ├─ DomainException (implements HttpExceptionInterface)
  │   ├─ NotFoundException → 404
  │   ├─ ConflictException → 409
  │   ├─ ServiceUnavailableException → 503
  │   ├─ QuotaExceededException → 429
  │   ├─ PermissionDeniedException → 403
  │   ├─ InsufficientCreditsException → 402
  │   ├─ StorageException → 500
  │   └─ SummaryGenerationException → 502
  │   → 按 getStatusCode() 返回，500+ 生产脱敏为"服务器内部错误"
  │
  ├─ HttpException (abort())
  │   → 保留原状态码和消息
  │
  └─ 其他 Throwable
      → 生产环境: 500 "服务器内部错误"（不暴露细节）
      → 开发环境: 500 + exception + file + line
```

**关键**: `DomainException` 实现了 `HttpExceptionInterface`，即使不被 render 回调拦截，Laravel 默认也会按 `getStatusCode()` 渲染。

---

## 四、模块系统内部

### 4.1 模块发现

```
ModuleRegistry::all()
  ├─ 扫描 src/Modules/*/composer.json → extra.saas
  └─ 扫描 vendor/dsplat/multi-tenant-saas-module-*/composer.json → extra.saas
      │
      ▼
  返回 name => meta 数组（name/version/provider/priority/dependencies/conflicts/tenant_toggleable/default_enabled/requires_core）
```

### 4.2 模块状态管理

```
ModuleManager::getLoadOrder()
  ├─ ModuleRegistry::all() → 所有已安装模块
  ├─ DB::table('modules') → 系统级启停状态
  ├─ resolveStatus: DB 记录 > composer.json default_enabled
  ├─ validateDependencies: 缺失依赖 → 警告（不阻断）
  ├─ validateConflicts: 互斥模块 → 警告（不阻断）
  ├─ validateCoreVersion: 版本不满足 → 警告（不阻断）
  └─ topologicalSort: 按 priority + 依赖拓扑排序
```

### 4.3 租户级模块开关

```
ModuleManager::isEnabledForTenant(name, tenantId)
  ├─ 系统级未启用 → false
  ├─ tenant_toggleable=false → true（系统级启用即可）
  └─ 查询 tenant_modules 表 → status
```

---

## 五、数据流：租户创建全流程

```
1. Operator 注册 → TenantOnboardingService::register()
2. 创建 Tenant (status=pending)
3. ModuleManager::provisionTenantModules(tenantId, plan)
   └─ 按套餐配置 + tenant_module_defaults 写入 tenant_modules 表
4. 发送 TenantCreated 事件
   └─ LogEventListener → 审计日志
5. 域名审核通过 → TenantActivated 事件
   ├─ LogEventListener → 审计日志
   └─ AttachTenantAdminOnActivated → 写入 operator_tenants
```

---

## 六、数据流：Agent 对话全流程

```
1. POST /api/v1/agents/{id}/chat → AgentChatController
2. 创建 AgentConversation (status=active)
3. ConversationStarted::dispatch()
4. AgentRuntime::run(tenantId, agentId, message)
   │
   ├─ AgentContextBuilder::buildContext()
   │   └─ system_prompt + 历史消息 + 记忆注入
   │
   ├─ AgentChatClient::chat() → AI 推理
   │   └─ 失败时降级到 fallback_provider
   │
   ├─ 如果有 tool_calls:
   │   ├─ AgentToolExecutor::partitionByRisk()
   │   │   ├─ L1 → 直接执行
   │   │   └─ L2 → 签发 confirm_token → pending_confirmation
   │   ├─ ToolCalled::dispatch()
   │   ├─ ToolRegistry::execute()
   │   ├─ ToolCallCompleted/Failed::dispatch()
   │   └─ 结果入上下文 → 继续循环
   │
   └─ 循环至 max_tool_calls 或 AI 返回文本
5. 持久化消息 → AgentResponse
6. SSE 流式返回前端
```

---

## 七、配置体系

### 7.1 配置文件

| 配置文件 | 作用 | 关键配置 |
|---------|------|---------|
| `config/tenancy.php` | 租户核心 | deployment_mode, admin_domain, plans, tenant_module_defaults, retention, webhooks, event_bus |
| `config/ai.php` | AI 模块 | providers, text/image/video 默认模型, quota, tenant 默认开关 |
| `config/pay.php` | 支付 | wechat/alipay/stripe/paypal 配置 |
| `config/channel.php` | 渠道 | 渠道类型注册 |
| `config/id.php` | ID 生成 | min_value, max_value |
| `config/socialite.php` | OAuth | 全局 OAuth 提供商配置 |
| `config/octane.php` | Octane | listeners, tables, watch |

### 7.2 租户级配置覆盖

租户级配置存储在 `tenant_settings` 表（group/key/value），通过 `TenantSettingService` 读取。优先级：`tenant_settings` > `config()` 默认值。

---

## 八、中间件详解

| 中间件 | 别名 | 执行时机 | 职责 |
|--------|------|---------|------|
| `AddSecurityHeaders` | — | 全局 prepend | CSP, X-Frame-Options, HSTS |
| `IdentifyDomain` | — | 全局 prepend | 识别域名类型 (admin/console/api/app) |
| `BindSessionDomain` | — | 全局 prepend | 设置 session.domain |
| `CastRouteParameters` | — | web/api prepend | 路由参数类型转换 |
| `IdentifyTenant` | `tenant.identify` | web/api prepend | 解析租户 → TenantContext |
| `IdentifyOperator` | — | web/api prepend | 解析 Operator → $request->setUserResolver() |
| `EnsureTenantContext` | `tenant.ensure` | 路由级 | 强制租户上下文存在 |
| `CheckPermission` | `tenant.permission` | 路由级 | 基础权限检查 |
| `CheckRbacPermission` | `rbac.permission` | 路由级 | RBAC 权限检查 |
| `CheckFeatureFlag` | `feature.flag` | 路由级 | 功能开关检查 |
| `VerifyOperatorTenant` | — | 模块路由 | Operator 租户归属校验 |
| `McpMiddleware` | `mcp.auth` | MCP 路由 | MCP 客户端认证 |

---

## 九、事件系统

### 9.1 注册的事件监听器

| 事件 | 监听器 | 处理 |
|------|--------|------|
| `TenantCreated` | `LogEventListener` | 审计日志 |
| `TenantSuspended` | `LogEventListener` | 审计日志 |
| `TenantActivated` | `LogEventListener` + `AttachTenantAdminOnActivated` | 审计日志 + 写入 operator_tenants |
| `UserRegistered` | `LogEventListener` | 审计日志 |
| `UserLoggedIn` | `LogEventListener` | 审计日志 |
| `AgentCreated` | `LogEventListener` | 审计日志 |
| `AgentEnabled` | `LogEventListener` | 审计日志 |
| `AgentDisabled` | `LogEventListener` | 审计日志 |
| `ToolCallFailed` | `LogEventListener` | 审计日志 (warning 级别) |

### 9.2 事件派发点

| 事件 | 派发位置 |
|------|---------|
| `ToolCalled` | AgentToolExecutor 执行工具前 |
| `ToolCallCompleted` | AgentToolExecutor 执行成功后 |
| `ToolCallFailed` | AgentToolExecutor 执行失败后 |
| `ConversationStarted` | AgentChatController/AssistantController 创建会话后 |
| `ConversationEnded` | AgentChatController/AssistantController 删除会话后 |

### 9.3 防御式审计

`LogEventListener` 使用 try-catch 包装审计写入，防止审计失败中断主流程：

```php
private function audit(...): void {
    try {
        $this->audit->log(...);
    } catch (\Throwable $e) {
        Log::error('LogEventListener: 审计写入失败', [...]);
    }
}
```

---

## 十、Artisan 命令

| 命令 | 说明 |
|------|------|
| `tenancy:init` | 初始化平台（创建 admin 账号、默认租户） |
| `module:list` | 列出所有模块状态 |
| `module:enable/disable` | 系统级启停模块 |
| `module:create` | 创建新模块骨架 |
| `module:require` | 安装 Composer 模块包 |
| `check:tenant-isolation` | 检查租户数据隔离 |
| `backup:run/list/restore` | 备份管理 |
| `schedule:list` | 列出定时任务 |
| `mailer:health-check` | 邮件健康检查 |
| `memory:cleanup/decay` | AI 记忆清理/衰减 |
| `secretary:install` | 安装系统小秘书 |
| `secretary:kb:build/generate/harvest/index` | 知识库构建工具链 |
| `ai:models:sync` | 同步 AI 模型配置 |
| `agents:enable` | 启用 Agent 角色模板 |
