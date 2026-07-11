# Changelog

## v2.2.0 — 2026-07-11

### Breaking Changes

**所有业务控制器从 `app/` 迁移到 `src/Modules/`。** 下游项目需要删除 `app/` 中已迁移的文件。

### 新增模块

| 模块 | 包名 | 说明 |
|---|---|---|
| Notification | `dsplat/multi-tenant-saas-module-notification` | 通知中心（站内通知、已读状态、偏好设置） |
| Storage | `dsplat/multi-tenant-saas-module-storage` | 文件存储（上传、下载、分享、配额管理） |

### 新增服务

| 服务 | 说明 |
|---|---|
| `SchedulerService` | 定时任务集中管理（8 个内置任务） |
| `MailerService` | 统一邮件发送（模板驱动 + 品牌注入） |
| `SearchService` + `Searchable` trait | 全文搜索（LIKE + MySQL FULLTEXT） |
| `BackupService` | 租户数据备份/恢复 |
| `ImageService` | 图片处理（resize/crop/thumbnail） |
| `PasswordService` | 密码修改/重置 + 密码历史 |

### Auth 模块升级

- `AuthController`：登录/注册/登出/邮箱验证/密码重置/SSO
- `MfaController`：TOTP 设置/确认 + 设备管理 + 会话管理 + 恢复码
- 登录返回格式：`auth_token` + `refresh_token` + `auth_token_expires_in` + `refresh_token_expires_in`

### 迁移指南

**下游项目需要删除以下文件（已迁移到框架模块）：**

#### Controllers（已迁移到 `src/Modules/`）

```
app/Http/Controllers/Api/AuthController.php           → src/Modules/Auth/
app/Http/Controllers/Api/MfaController.php            → src/Modules/Auth/
app/Http/Controllers/Api/RbacController.php           → src/Modules/Auth/
app/Http/Controllers/Api/TenantOAuthController.php    → src/Modules/Auth/
app/Http/Controllers/Api/TenantController.php         → src/Modules/User/
app/Http/Controllers/Api/TenantMemberController.php   → src/Modules/User/
app/Http/Controllers/Api/TenantSettingController.php  → src/Modules/User/
app/Http/Controllers/Api/TenantOnboardingController.php → src/Modules/User/
app/Http/Controllers/Api/SubscriptionController.php   → src/Modules/Billing/
app/Http/Controllers/Api/TenantCreditController.php   → src/Modules/Billing/
app/Http/Controllers/Api/TenantQuotaController.php    → src/Modules/Billing/
app/Http/Controllers/Api/TenantDomainController.php   → src/Modules/Domain/
app/Http/Controllers/Api/TenantSslController.php      → src/Modules/SSL/
app/Http/Controllers/Api/TenantPaymentController.php  → src/Modules/Payment/
app/Http/Controllers/Api/TenantTokenController.php    → src/Modules/ApiToken/
app/Http/Controllers/Api/ModuleController.php         → src/Modules/Infrastructure/
app/Http/Controllers/Api/AdminSettingsController.php  → src/Modules/Platform/
app/Http/Controllers/Api/TenantAuditController.php    → src/Modules/Logging/
app/Http/Controllers/Api/NotificationController.php   → src/Modules/Notification/
app/Http/Controllers/Api/FileController.php           → src/Modules/Storage/
app/Http/Controllers/Api/McpServerController.php      → src/Modules/Ai/
app/Http/Controllers/Api/ToolController.php           → src/Modules/Ai/
```

#### Resources（已迁移到模块）

```
app/Http/Resources/TenantResource.php          → src/Modules/User/
app/Http/Resources/TenantSettingResource.php   → src/Modules/User/
app/Http/Resources/TenantUserResource.php      → src/Modules/User/
app/Http/Resources/UserResource.php            → src/Modules/User/
app/Http/Resources/CreditAccountResource.php   → src/Modules/Billing/
app/Http/Resources/AgentResource.php           → src/Modules/Ai/
app/Http/Resources/ToolResource.php            → src/Modules/Ai/
app/Http/Resources/ToolLogResource.php         → src/Modules/Ai/
app/Http/Resources/ConversationResource.php    → src/Modules/Conversation/
app/Http/Resources/MessageResource.php         → src/Modules/Conversation/
```

#### Requests（已迁移到模块）

```
app/Http/Requests/Agent/*                      → src/Modules/Ai/Http/Requests/
app/Http/Requests/Form/StoreFormRequest.php    → src/Modules/Form/
app/Http/Requests/Lottery/StoreLotteryRequest.php → src/Modules/Lottery/
app/Http/Requests/Voting/StoreVoteRequest.php  → src/Modules/Voting/
app/Http/Requests/Coupon/StoreCouponRequest.php → src/Modules/Coupon/
```

#### Commands（已迁移到模块）

```
app/Console/Commands/ProcessCreditExpiry.php   → src/Modules/Billing/
app/Console/Commands/ProcessSubscriptions.php  → src/Modules/Billing/
```

#### Notifications（已迁移到模块）

```
app/Notifications/PaymentSuccessNotification.php    → src/Modules/Billing/
app/Notifications/SubscriptionExpiringNotification.php → src/Modules/Billing/
app/Notifications/CreditLowNotification.php         → src/Modules/Billing/
app/Notifications/TenantSuspendedNotification.php    → src/Modules/User/
app/Notifications/GeneralNotification.php           → src/Modules/Notification/
```

#### 路由更新

`routes/api.php` 已精简为共享路由（支付回调、OAuth、Webhook、广播）。模块路由由 `ModuleServiceProvider` 自动加载。

**保留的文件（骨架层）：**
- `app/Http/Controllers/Controller.php` — 基础控制器
- `app/Http/Controllers/Concerns/ApiResponse.php` — API 响应 trait
- `app/Http/Controllers/Concerns/AuthorizesTenantAccess.php` — 租户授权 trait
- `app/Http/Controllers/SpaController.php` — SPA 控制器
- `app/Http/Middleware/AddSecurityHeaders.php` — 安全头中间件
- `app/Exceptions/Handler.php` — 异常处理器
- `app/Models/Customer.php` — 示例模型

### 其他变更

- `module:list --available`：查询 Packagist 可用模块
- `bin/module-publish`：模块发布辅助脚本
- `pint.json`：代码格式化配置
- `.github/workflows/code-quality.yml`：CI Pint 检查
- 双语文档结构：`docs/zh/` + `docs/en/`
- `README.md` 精简为快速启动 + 架构概览
- `docs/zh/user-manual.md` 详细使用手册

### 版本要求

- PHP ^8.3
- Laravel ^13.0
