# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/lang/zh-CN/).

## [Unreleased]

## [0.2.2] - 2026-06-24

### Fixed
- SendEmailVerificationJob/SendPasswordResetJob 引用 App\Mail 命名空间（移至 src/Mail/MultiTenantSaas\Mail）
- RbacService JOIN 使用 permissions.id 但主键已改为 permission_id（自定义角色权限查询返回空）
- TenantController activate 权限检查 tenant.activate 不存在（改为 tenant.suspend）
- TestCase schema 与真实迁移不匹配（credit_accounts/credit_transactions/6个表主键+列名全量对齐）
- TenantContext config key current_tenant_id → default_tenant_id
- TenantContext::getTenant() Octane cache 泄漏（移除 cache()->remember）
- LogEventListener 用户注册日志泄露 email PII
- SmsService 成功发送用了 Log::error 级别
- SubscriptionController exists:subscription_plans,id → subscription_plan_id + $plan->id → subscription_plan_id
- SubscriptionController updatePlan 缺少 name 字段验证
- TenantCreditController 引用 total_earned/total_spent 但实际列名是 total_recharged/total_consumed
- FileController show/preview/download/share/destroy 缺少显式租户所有权校验
- CHANGELOG.md 0.1.0 整段重复
- TestController 仍存在未重命名（改为 SpaController）

### Changed
- config/tenancy.php 新增 id 配置节（min_value/max_value）
- TenancyServiceProvider 移除 tenancy-queue-config 发布标签（避免覆盖应用 queue.php）
- Mailable 从 app/Mail/ 移至 src/Mail/（框架包自包含）
- 邮件主题改用 trans() i18n

## [0.2.1] - 2026-06-24

### Fixed
- ProcessCreditExpiry 引用 credit_transactions 不存在的 expires_at/expired 列（新增迁移补列）
- RefundService trans() 被包在引号里不执行翻译
- UserApiToken API Key 明文存储（改用 Crypt 加密/解密）
- SocialiteService Octane config 跨请求污染（改用 app 容器请求级隔离）
- TestCase schema 与真实迁移多处不匹配（列名/字段/类型全量对齐）
- 6 个模型缺少 HasGlobalId（FileUpload/NotificationPreference/Permission/Role/SubscriptionHistory/SubscriptionPlan）
- SubscriptionHistory / UserApiToken 缺少 BelongsToTenant（跨租户数据泄露）
- /credits /api-tokens /quotas 路由缺少 RBAC 中间件
- ProcessCreditExpiry / RefundService 硬编码中文改用 trans()
- RbacController exists 验证规则引用旧主键列名

### Changed
- 6 个迁移主键从 auto-increment id 改为全局 ID（unsignedBigInteger）
- RBAC 迁移外键引用 + seed 代码适配新主键名
- subscription_histories 迁移 plan_id 外键引用适配新主键名

## [0.2.0] - 2026-06-24

### Added
- 核心服务接口契约（IdGeneratorContract + TenantContextContract），支持派生项目替换实现
- 事件系统：5 个领域事件类（TenantCreated/Suspended/Activated, UserRegistered/LoggedIn）+ LogEventListener
- Jobs/Queue 系统：SendEmailVerificationJob + SendPasswordResetJob + queue 配置
- 认证后 API 全局限流（RateLimiter + throttle:api，按用户 ID 60/min）
- Sanctum Token abilities 支持（14 种细粒度权限 + 查询端点）
- 3 个模型工厂（TenantFactory + UserFactory + TenantUserFactory）
- 订阅管理模块（SubscriptionService + SubscriptionPlan + SubscriptionHistory）
- 文件存储模块（FileService + FileController + FileUpload 模型）
- RBAC 权限管理模块（RbacService + RbacController + 角色/权限/角色权限表）
- 通知中心模块（NotificationController + 通知偏好 + 5 种通知类）
- 积分系统模块（CreditAccount + CreditTransaction + CreditService）
- 审计日志全覆盖（Auth + Tenant + TenantMember + Payment + RBAC 全链路）
- 密码重置邮件通知（PasswordResetMail Mailable）
- 邮箱验证流程（EmailVerificationMail + verifyEmail + resendVerification）
- 租户暂停/恢复（suspend + activate，暂停时清除 Token）
- 租户开通流程（store + provisionTenant 初始化配置/积分）
- 成员删除路由（最后管理员保护）
- ProcessSubscriptions + ProcessCreditExpiry 定时任务
- 订阅计划种子数据（free/basic/pro/enterprise）
- i18n 全量改造（所有控制器响应使用 trans()）

### Changed
- AuthController 邮件发送改为异步 Job 分发
- AuthController 登录/注册分发领域事件
- TenantController 分发租户创建/暂停/激活事件
- HasGlobalId 使用 IdGeneratorContract 替代直接引用 IdGenerator
- TenancyServiceProvider 绑定接口契约 + 注册事件监听 + 注册限流策略
- ControllerTest 使用模型工厂 + 动态 ID 替代硬编码 demo 数据
- TenantTokenController 支持 abilities 参数 + 验证

### Fixed
- AuditService::log() 类型不匹配（参数从 ?array 改为联合类型）
- FileController 跨租户数据泄露（添加 AuthorizesTenantAccess）
- SubscriptionController 缺少租户访问检查
- notification_preferences 迁移外键引用错误
- subscription_histories 迁移 tenant_id 类型不匹配
- TenantController 全部 CRUD 权限检查错误
- config/id.php 包含 mtedu 业务配置
- SmsService 包含 mtedu 业务代码
- AdminSettingsController 允许 dify 配置组
- API 响应格式不一致（缺少 success 字段）
- TenantController update() 缺少验证规则
- SubscriptionController 权限检查方式不一致
- TenantController 缺少租户归属检查
- 硬编码中文消息（3 处改用 trans()）
- TestController 存在于生产代码中
- login token 名 'admin-token' 改为 'auth-token'
- TenantQuotaController 硬编码配额限制
- enterprise 计划 limits 为 0 改为 null

### Security
- 认证后 API 全局限流（防止暴力调用）
- Sanctum Token abilities（细粒度 API 权限控制）
- 跨租户数据泄露修复（FileController + SubscriptionController）

## [0.1.0] - 2026-06-24

### Added
- 多租户 SaaS 框架基座
- 租户隔离（TenantScope + BelongsToTenant）
- 权限控制（四重访问架构）
- 配额管理
- 审计日志模型 + 服务集成
- 8 种 UI 框架支持
- Domain 模块（域名管理 + Nginx 配置生成）
- SSL 模块（证书管理）
- 32 个 API 路由
- 46 个测试用例
- API Resource 层（数据脱敏）
- 安全 HTTP 头中间件
- 编码规范文档
- CHANGELOG 和 CONTRIBUTING

### Security
- Sanctum 认证
- 租户数据隔离
- OAuth Token 加密存储
- 批量赋值防护
- 速率限制（认证端点）
- 密码策略增强（min(8)+mixedCase+numbers）
- 支付日志脱敏
- CORS 环境变量配置
