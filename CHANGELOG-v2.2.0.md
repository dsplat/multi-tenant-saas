# Changelog — v2.2.0

## 概要

`app/` 下所有业务控制器迁移到框架模块，骨架只保留基础层。新增 2 个模块、6 个服务、Auth 模块升级。

## 模块清单（24 个）

| 模块 | 包名 | 类型 | 变更 |
|---|---|---|---|
| Auth | `dsplat/multi-tenant-saas-module-auth` | 必选 | 新增 AuthController + MfaController + RbacController + TenantOAuthController |
| User | `dsplat/multi-tenant-saas-module-user` | 必选 | 新增 TenantController + TenantMemberController + TenantSettingController + TenantOnboardingController |
| Billing | `dsplat/multi-tenant-saas-module-billing` | 必选 | 新增 SubscriptionController + TenantCreditController + TenantQuotaController + Commands |
| Infrastructure | `dsplat/multi-tenant-saas-module-infrastructure` | 必选 | 新增 ModuleController |
| Platform | `dsplat/multi-tenant-saas-module-platform` | 必选 | 新增 AdminSettingsController |
| Logging | `dsplat/multi-tenant-saas-module-logging` | 必选 | 新增 TenantAuditController |
| Domain | `dsplat/multi-tenant-saas-module-domain` | 必选 | 新增 TenantDomainController |
| Payment | `dsplat/multi-tenant-saas-module-payment` | 必选 | 新增 TenantPaymentController |
| Notification | `dsplat/multi-tenant-saas-module-notification` | 必选 | **新建** — NotificationController + GeneralNotification |
| Storage | `dsplat/multi-tenant-saas-module-storage` | 必选 | **新建** — FileController |
| SSL | `dsplat/multi-tenant-saas-module-ssl` | 可选 | 新增 TenantSslController |
| ApiToken | `dsplat/multi-tenant-saas-module-api-token` | 可选 | 新增 TenantTokenController |
| Ai | `dsplat/multi-tenant-saas-module-ai` | 可选 | 新增 McpServerController + ToolController + Agent Requests + Resources |
| Conversation | `dsplat/multi-tenant-saas-module-conversation` | 可选 | 新增 Resources (ConversationResource, MessageResource) |
| Form | `dsplat/multi-tenant-saas-module-form` | 可选 | 新增 StoreFormRequest |
| Lottery | `dsplat/multi-tenant-saas-module-lottery` | 可选 | 新增 StoreLotteryRequest |
| Voting | `dsplat/multi-tenant-saas-module-voting` | 可选 | 新增 StoreVoteRequest |
| Coupon | `dsplat/multi-tenant-saas-module-coupon` | 可选 | 新增 StoreCouponRequest |
| Event | `dsplat/multi-tenant-saas-module-event` | 必选 | 无变更 |
| Monitoring | `dsplat/multi-tenant-saas-module-monitoring` | 必选 | 无变更 |
| DeveloperPortal | `dsplat/multi-tenant-saas-module-developer-portal` | 必选 | 无变更 |
| Plugin | `dsplat/multi-tenant-saas-module-plugin` | 必选 | 无变更 |
| Sms | `dsplat/multi-tenant-saas-module-sms` | 可选 | 无变更 |
| Workflow | `dsplat/multi-tenant-saas-module-workflow` | 可选 | 无变更 |

## 新增服务

| 服务 | 说明 |
|---|---|
| `SchedulerService` | 定时任务集中管理（9 个内置任务） |
| `MailerService` | 统一邮件发送（模板驱动 + 品牌注入） |
| `SearchService` + `Searchable` trait | 全文搜索（LIKE + MySQL FULLTEXT） |
| `BackupService` | 租户数据备份/恢复 |
| `ImageService` | 图片处理（resize/crop/thumbnail） |
| `PasswordService` | 密码修改/重置 + 密码历史 |

## Auth 模块升级

- `AuthController`：登录/注册/登出/邮箱验证/密码重置/SSO
- `MfaController`：TOTP 设置/确认 + 设备管理 + 会话管理 + 恢复码
- 登录返回：`auth_token` + `refresh_token` + `auth_token_expires_in` + `refresh_token_expires_in`

## 迁移指南

### 下游项目需要删除的文件

**Controllers:**
```
app/Http/Controllers/Api/AuthController.php
app/Http/Controllers/Api/MfaController.php
app/Http/Controllers/Api/RbacController.php
app/Http/Controllers/Api/TenantOAuthController.php
app/Http/Controllers/Api/TenantController.php
app/Http/Controllers/Api/TenantMemberController.php
app/Http/Controllers/Api/TenantSettingController.php
app/Http/Controllers/Api/TenantOnboardingController.php
app/Http/Controllers/Api/SubscriptionController.php
app/Http/Controllers/Api/TenantCreditController.php
app/Http/Controllers/Api/TenantQuotaController.php
app/Http/Controllers/Api/TenantDomainController.php
app/Http/Controllers/Api/TenantSslController.php
app/Http/Controllers/Api/TenantPaymentController.php
app/Http/Controllers/Api/TenantTokenController.php
app/Http/Controllers/Api/ModuleController.php
app/Http/Controllers/Api/AdminSettingsController.php
app/Http/Controllers/Api/TenantAuditController.php
app/Http/Controllers/Api/NotificationController.php
app/Http/Controllers/Api/FileController.php
app/Http/Controllers/Api/McpServerController.php
app/Http/Controllers/Api/ToolController.php
```

**Resources:**
```
app/Http/Resources/TenantResource.php
app/Http/Resources/TenantSettingResource.php
app/Http/Resources/TenantUserResource.php
app/Http/Resources/UserResource.php
app/Http/Resources/CreditAccountResource.php
app/Http/Resources/AgentResource.php
app/Http/Resources/ToolResource.php
app/Http/Resources/ToolLogResource.php
app/Http/Resources/ConversationResource.php
app/Http/Resources/MessageResource.php
```

**Requests:**
```
app/Http/Requests/Agent/* (全部)
app/Http/Requests/Form/StoreFormRequest.php
app/Http/Requests/Lottery/StoreLotteryRequest.php
app/Http/Requests/Voting/StoreVoteRequest.php
app/Http/Requests/Coupon/StoreCouponRequest.php
```

**Commands:**
```
app/Console/Commands/ProcessCreditExpiry.php
app/Console/Commands/ProcessSubscriptions.php
```

**Notifications:**
```
app/Notifications/PaymentSuccessNotification.php
app/Notifications/SubscriptionExpiringNotification.php
app/Notifications/CreditLowNotification.php
app/Notifications/TenantSuspendedNotification.php
app/Notifications/GeneralNotification.php
```

### 路由更新

`routes/api.php` 已精简为共享路由（支付回调、OAuth、Webhook、广播）。模块路由由 `ModuleServiceProvider` 自动加载，无需手动配置。

### 保留的骨架文件

```
app/Http/Controllers/Controller.php
app/Http/Controllers/Concerns/ApiResponse.php
app/Http/Controllers/Concerns/AuthorizesTenantAccess.php
app/Http/Controllers/SpaController.php
app/Http/Middleware/AddSecurityHeaders.php
app/Exceptions/Handler.php
app/Models/Customer.php
```

## CLI 新增

- `module:list --available` — 查询 Packagist 可用模块
- `bin/module-publish` — 模块发布辅助脚本

## 版本要求

- PHP ^8.3
- Laravel ^13.0
