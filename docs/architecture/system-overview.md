# 系统架构概览

**最后更新**: 2026-06-29

---

## 设计原则

1. **租户隔离优先**: 所有数据操作默认按租户隔离
2. **四重访问架构**: admin/console/app/guest 四层访问控制
3. **RBAC 细粒度权限**: 角色 + 权限节点，40+ 权限，中间件级路由保护
4. **模块化设计**: 核心能力 + 可选模块（ApiToken/Domain/Payment/SSL）
5. **配置驱动**: 通过配置文件控制行为，无需修改代码
6. **领域事件驱动**: 关键操作触发领域事件，异步处理审计/通知

---

## 整体架构

```
┌─────────────────────────────────────────────────────────────┐
│                      Nginx 反向代理                          │
│  ┌─────────────┬─────────────┬─────────────┬─────────────┐  │
│  │ admin.lyt   │ ai.lyt.com  │ ai.tenant1  │ ai-admin.   │  │
│  │ .com        │             │ .local      │ tenant1     │  │
│  └──────┬──────┴──────┬──────┴──────┬──────┴──────┬──────┘  │
│         │             │             │             │          │
│         ▼             ▼             ▼             ▼          │
│  ┌─────────────────────────────────────────────────────┐    │
│  │              Laravel 中间件层                         │    │
│  │  IdentifyDomain → IdentifyTenant → CheckPermission   │
│  │  → CheckRbacPermission → SetLocale                    │    │
│  └─────────────────────────────────────────────────────┘    │
│                          │                                  │
│                          ▼                                  │
│  ┌─────────────────────────────────────────────────────┐    │
│  │              业务逻辑层                               │    │
│  │  Controllers → Services → Models                     │    │
│  └─────────────────────────────────────────────────────┘    │
│                          │                                  │
│                          ▼                                  │
│  ┌─────────────────────────────────────────────────────┐    │
│  │              数据访问层                               │    │
│  │  TenantScope (全局作用域) + BelongsToTenant (Trait)   │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

---

## 核心组件

### 1. 中间件层

| 中间件 | 别名 | 职责 | 执行顺序 |
|--------|------|------|----------|
| `IdentifyDomain` | `domain.identify` | 识别域名类型 (admin/console/api/app) | 1 |
| `IdentifyTenant` | `tenant.identify` | 识别当前租户 | 2 |
| `CheckPermission` | `tenant.permission` | 角色级权限控制 | 3 |
| `CheckRbacPermission` | `rbac.permission` | RBAC 细粒度权限控制（按路由配置） | 4 |
| `EnsureTenantContext` | `tenant.ensure` | 确保租户上下文有效 | 5 |
| `SetLocale` | `locale.set` | 自动设置请求语言 | 6 |

### 2. 上下文管理

| 组件 | 说明 |
|------|------|
| `TenantContext` | 租户上下文管理器，存储当前租户信息（Octane 友好，Request attributes） |
| `TenantConfigStore` | 租户配置存储，支持三级缓存 |
| `IdGeneratorContract` | ID 生成器接口契约，支持派生项目替换实现 |
| `TenantContextContract` | 租户上下文接口契约 |

### 3. 数据隔离

| 组件 | 说明 |
|------|------|
| `TenantScope` | 全局作用域，自动添加 `WHERE tenant_id = ?` |
| `BelongsToTenant` | Trait，为模型启用租户隔离 |

### 4. 核心服务

| 分类 | 服务 | 说明 |
|------|------|------|
| **基础** | `IdGenerator` | 16位随机ID生成器 |
| | `TenantService` | 租户CRUD管理 |
| | `TenantSettingService` | 租户配置管理 |
| | `TenantMemberService` | 成员管理 |
| | `TenantProfileService` | 租户档案管理 |
| **权限** | `RbacService` | RBAC权限管理（角色/权限/角色权限） |
| **积分** | `TenantCreditService` | 积分/配额管理 |
| | `RefundService` | 退款服务 |
| **订阅** | `SubscriptionService` | 订阅管理（计划/历史/升降级） |
| **支付** | `PaymentService` | 支付统一入口 |
| | `PayPalService` | PayPal支付 |
| | `StripeService` | Stripe支付 |
| | `UnionPayService` | 银联支付 |
| | `PaymentSecurityService` | 支付安全 |
| **OAuth** | `SocialiteService` | 第三方登录（微信/钉钉/飞书/支付宝） |
| | `AlipayOAuthService` | 支付宝OAuth独立实现 |
| **文件** | `FileService` | 文件存储管理 |
| **通知** | `NotificationPreferenceService` | 通知偏好 |
| **审计** | `AuditService` | 审计日志 |
| | `StructuredLogService` | 结构化日志 |
| | `LoginLogService` | 登录日志 |
| **运维** | `CacheService` | 缓存管理 |
| | `QueueService` | 队列管理 |
| | `HorizonService` | Horizon管理 |
| | `PerformanceService` | 性能监控 |
| | `AlertService` | 告警系统 |
| | `SystemSettingService` | 系统配置 |
| | `ExportService` | 导出任务 |
| **高级** | `ApiVersionService` | API版本管理 |
| | `PluginService` | 插件系统 |
| | `RateLimitService` | 速率限制 |
| | `UserPreferenceService` | 用户偏好 |
| | `SmsService` | 短信服务 |
| | `DomainService` | 域名管理 |
| | `SslService` | SSL证书管理 |
| | `NginxConfigService` | Nginx配置管理 |

---

## 模块系统

```
src/Modules/
├── ApiToken/        # API Token 模块（用户级 Token + abilities）
│   ├── Controllers/
│   └── Models/
├── Domain/          # 域名管理模块
│   ├── Services/
│   ├── Commands/
│   └── Config/
├── Payment/         # 支付模块（多网关统一接口）
│   ├── Controllers/
│   └── Services/
└── SSL/             # SSL证书模块
    ├── Services/
    └── Config/
```

### 模块注册

在 `TenancyServiceProvider` 中注册模块：

```php
public function register(): void
{
    // 注册域名管理模块
    if (config('tenancy.modules.domain.enabled', true)) {
        $this->app->register(\MultiTenantSaas\Modules\Domain\DomainServiceProvider::class);
    }
    
    // 注册SSL模块
    if (config('tenancy.modules.ssl.enabled', true)) {
        $this->app->register(\MultiTenantSaas\Modules\SSL\SSLServiceProvider::class);
    }
}
```

---

## 请求处理流程

```
HTTP Request
    │
    ▼
Nginx
    ├─ 识别域名 → 路由到 PHP-FPM
    └─ 注入 X-Original-Host header
    │
    ▼
Laravel Middleware Stack
    │
    ├─ 1. IdentifyDomain
    │     读取 X-Original-Host + path
    │     → domain_type: admin | console | api | app
    │
    ├─ 2. IdentifyTenant
    │     IF admin → 跳过
    │     ELSE 按优先级解析租户 ID：
    │       URL参数 → Header → 自定义域名 → Cookie → Session → 默认租户
    │     → tenant_id, tenant 注入 TenantContext
    │
    ├─ 3. CheckPermission
    │     检查用户角色和权限
    │     console: 仅 tenant_admin
    │     app: tenant_admin + end_user
    │
    ├─ 4. CheckRbacPermission (按路由配置)
    │     检查细粒度权限节点（如 tenant.view, member.create）
    │     未配置权限的路由跳过此层
    │
    ├─ 5. SetLocale
    │     自动设置请求语言（zh_CN / en）
    │
    └─ 6. Controller
          业务逻辑处理
    │
    ▼
Eloquent Model
    └─ TenantScope 自动: WHERE tenant_id = {current_tenant_id}
```

---

## 环境配置

### 必需的环境变量

```env
# 应用配置
APP_NAME="Multi-Tenant SaaS"
APP_ENV=local
APP_KEY=base64:xxx
APP_DEBUG=true
APP_URL=http://localhost

# 数据库
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=multi_tenant_saas
DB_USERNAME=saas_user
DB_PASSWORD=your_password

# 域名配置
ADMIN_DOMAIN=admin.lyt.com

# 会话
SESSION_DRIVER=database
SESSION_LIFETIME=120
```

---

## 目录结构

```
multi-tenant-saas/
├── app/
│   ├── Http/
│   │   ├── Controllers/    # 控制器（18 个）
│   │   ├── Middleware/     # 自定义中间件
│   │   └── Resources/      # API Resource（5 个）
│   ├── Models/             # 业务模型
│   └── Notifications/      # 通知类（5 种）
├── config/                 # 配置文件（12 个）
├── database/
│   ├── factories/          # 模型工厂
│   ├── migrations/         # 数据库迁移（37 张表）
│   └── seeders/            # 数据填充
├── lang/                   # 国际化（zh_CN + en，13 个文件）
├── src/                    # 框架核心代码
│   ├── Concerns/           # Traits（BelongsToTenant / HasGlobalId）
│   ├── Context/            # 上下文管理（TenantContext）
│   ├── Contracts/          # 接口定义
│   ├── Enums/              # 枚举（ErrorCode）
│   ├── Events/             # 领域事件（5 个）
│   ├── Exceptions/         # 业务异常（4 个）
│   ├── Helpers/            # 辅助函数
│   ├── Jobs/               # 队列任务（2 个）
│   ├── Listeners/          # 事件监听器
│   ├── Mail/               # 邮件类（2 个）
│   ├── Middleware/          # 中间件（6 个）
│   ├── Models/             # 框架模型（17 个）
│   ├── Modules/            # 可选模块（4 个）
│   ├── Scopes/             # 全局作用域
│   ├── Services/           # 服务层（39 个）
│   └── TenancyServiceProvider.php
└── tests/                  # 测试（25 个文件）
```

### 领域事件系统

| 事件 | 触发时机 |
|------|----------|
| `UserRegistered` | 用户注册成功 |
| `TenantCreated` | 租户创建成功 |
| `TenantSuspended` | 租户被暂停 |
| `CreditLow` | 积分余额不足 |
| `SubscriptionExpiring` | 订阅即将到期 |

事件由 `LogEventListener` 自动监听并记录审计日志。通知类通过 Laravel Notification 系统异步发送。

### 数据库表总览（37 张表）

| 业务域 | 表名 |
|--------|------|
| 租户域 | tenants, tenant_users, tenant_settings |
| 用户域 | users, password_reset_tokens, email_verification_tokens |
| RBAC | permissions, roles, role_permissions |
| 积分财务 | credit_accounts, credit_transactions, financial_records |
| 订阅 | subscription_plans, subscription_histories |
| 支付 | payment_orders, user_payment_passwords, payment_logs |
| 发票税务 | invoices, invoice_items, tax_rates |
| 优惠券 | coupons, coupon_usages |
| 成本核算 | cost_allocations |
| AI | ai_tenant_configs, ai_usage_quotas, ai_requests, ai_prompts, ai_model_aliases, ai_providers, event_subscriptions |
| 文件 | file_uploads |
| 通知 | notifications, notification_preferences |
| 审计 | audit_logs, structured_logs |
| OAuth | oauth_accounts |
| 系统 | system_settings, user_api_tokens, user_preferences, api_versions, plugins, plugin_dependencies, rate_limit_rules, export_tasks, alert_rules, alerts |
| 框架基础 | cache, sessions, personal_access_tokens |

---

## AI 与计费模块

框架在 v1.0.0 引入 AI 网关与计费核算能力，作为可选业务模块挂载在服务层。

```
src/Services/
├── Ai/                     # AI 提供商适配层（统一接口）
│   ├── OpenAiProvider.php       # OpenAI / 兼容协议（chat/embed/stream）
│   ├── ZhipuProvider.php        # 智谱 GLM
│   ├── DalleProvider.php        # DALL-E 图像
│   ├── StableDiffusionProvider.php  # Stability 图像
│   ├── RunwayProvider.php       # Runway 视频
│   └── KlingProvider.php        # 可灵 视频
├── AiGatewayService.php    # 网关入口：chat/complete/embed/streamChat
├── AiTextService.php       # 文本 AI + Prompt 模板管理
├── AiImageService.php      # 图像 AI（文生图/图生图/编辑/风格迁移）
├── AiVideoService.php      # 视频 AI（异步任务 + 轮询 + 回调）
├── AiConfigService.php     # 租户级 AI 配置（开关/Key/模型白名单/预算）
├── AiUsageService.php      # 用量配额追踪与超额策略
├── CostService.php         # 成本核算（基础设施/AI/第三方分摊 + 损益预测）
├── InvoiceService.php      # 发票管理
└── TaxService.php / CouponService.php  # 税务 + 优惠券
```

**设计要点**:

- **多提供商抽象**: `AiProviderContract` 统一 chat/completion/embeddings/stream 接口，提供商以 `config/ai.php` 注册，运行时按 `default_provider` 或显式参数路由。
- **租户隔离与配额**: `AiConfigService` 为每个租户维护能力开关、自定义 API Key（加密）、模型白名单、月度预算与超额策略（`block`/`warn`/`allow`）；`AiUsageService` 按 `monthly` 周期聚合用量，达到 `warn_threshold` 触发告警。
- **异步视频生成**: 提交 → 队列延迟轮询（`poll_interval_seconds` / `max_poll_attempts`）→ 完成事件回调（`ai.video.task.updated`）→ 结果存储。
- **成本核算**: `CostService` 分摊基础设施成本、AI 成本（按租户聚合 `ai_usage_quotas`）与第三方成本，输出损益（`getProfitLoss`）与趋势预测（`forecastCostTrend`）。
- **SDK 暴露**: `MultiTenantSaas\SDK\AiResource` 暴露 `/ai/text`、`/ai/image`、`/ai/video`、`/ai/usage` 端点供派生项目接入。

详见 [AI 模块架构](AI模块架构.md)、[AI 模块使用指南](../guides/AI模块使用指南.md)、[计费配置指南](../guides/计费配置指南.md)。

---

## 技术栈

- **PHP**: ^8.2
- **Laravel**: ^12.0
- **数据库**: MySQL 8.0+
- **缓存**: Redis (推荐) / Database
- **Web服务器**: Nginx + PHP-FPM

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-29
