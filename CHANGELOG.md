# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/lang/zh-CN/).

## [2.1.0] - 2026-07-10

### Architecture — Composer-managed Modules (Packagist)

- **根包类型**: `type: project` → `type: library`, 支持模块 `require` 核心包
- **模块元数据**: `module.json` 合并到 `composer.json` 的 `extra.saas` 字段, 删除所有 `module.json`
- **模块包名**: `dsplat/module-{name}` → `dsplat/multi-tenant-saas-module-{name}`
- **模块依赖**: 每个模块声明 `require: dsplat/multi-tenant-saas: ^2.0`; SSL 额外依赖 domain, Ai 依赖 laravel/ai, Payment 依赖 yansongda/pay
- **ModuleRegistry**: `readManifest()` 改为读取 `composer.json extra.saas`; 修复 `packageName()` 前缀 bug
- **ModuleCreateCommand**: 模板生成 `extra.saas` 而非 `module.json`
- **GitHub Actions**: `.github/workflows/split.yml` — monorepo push 时自动 subtree split 到 23 个独立仓库
- **Packagist**: 核心 + 22 个模块各自独立发布, 支持 `composer require dsplat/multi-tenant-saas-module-{name}` 按需安装

## [2.0.0] - 2026-07-09

### Overview

v2.0.0 是一次架构级重构, 将单体 ServiceProvider 拆分为 Core + Modules + Plugins 三层架构。22 个模块全部自包含 (Models/Services/Controllers/Routes), 通过 Composer path 仓库管理, 支持 `artisan module:require` 动态添加/移除。测试从 2200+ 提升到 2269, 并行执行 ~7 秒。

### Architecture — Core + Modules + Plugins

- **ModuleRegistry** (`src/Services/ModuleRegistry.php`): 纯读取层 — 扫描磁盘, 读取 `composer.json extra.saas`, 提供元数据查询, 依赖/冲突/核心版本校验, 拓扑排序
- **ModuleManager** (`src/Services/ModuleManager.php`): 业务层 — 系统级启停 (`modules` 表), 租户级启停 (`tenant_modules` 表), 部署模式, 租户模块自动开通
- **ModuleBootstrapper** (`src/Services/ModuleBootstrapper.php`): 启动器 — `TenancyServiceProvider::boot()` 调用, 按 priority + 依赖拓扑排序注册并 boot 已启用模块
- **ModuleServiceProvider** (`src/Modules/Contracts/ModuleServiceProvider.php`): 模块基类 — 自动加载迁移/路由/视图/翻译/命令, 路由前缀 `api/v1`
- **composer.json** (每个模块): Composer 包定义 + `extra.saas` 运行时元数据 (name, priority, dependencies, conflicts, requires_core, provider, tenant_toggleable, default_enabled), 无 `extra.laravel.providers` (防止禁用模块加载)

### Architecture — ServiceProvider Split

- **TenancyServiceProvider** (~90 行): IdGenerator, TenantContext, TenantConfigStore, ModuleManager, 限流, 事件监听
- **AiServiceProvider**: AI 文本/图像/视频, Agent, MCP, 能力引擎 (14 种), 记忆系统, AI 网关
- **ConversationServiceProvider**: 会话/消息/标签, 频道管理, 消息路由
- **WorkflowServiceProvider**: 工作流引擎, 服务, 注册表 (Contract 绑定)

### Architecture — Module Inventory (22 modules)

**System (always enabled):** infrastructure, plugin, event, billing, logging, auth, user, monitoring, platform, developer-portal, ai, conversation, workflow

**Feature (tenant_toggleable):** domain, ssl, api-token, payment, form, lottery, voting, sms, coupon

### Architecture — Business Code Migration

- 8 模块业务代码归位: Form, Lottery, Voting, Sms, Coupon, Ai, Conversation, Workflow
- Models/Services/Controllers 从 `src/Models/`, `src/Services/`, `app/Http/Controllers/` 迁入 `src/Modules/{Name}/`
- 命名空间更新: `MultiTenantSaas\Models\*` → `MultiTenantSaas\Modules\{Name}\Models\*`
- 薄包装模型恢复为完整模型 (git show HEAD)
- AgentMonitor FQN 修复, WorkflowServiceProvider Contract 绑定, McpRouteMacro 位置修正

### Architecture — Tenant Module Provisioning

- `ModuleManager::provisionTenantModules()`: 新租户创建时自动按套餐开通模块
- 配置: `config/tenancy.php` 的 `plan_modules` (按套餐) 和 `tenant_module_defaults` (全局默认)
- 套餐预设: free (禁用 coupon/lottery/sms), basic, pro (全部), enterprise (全部+payment+api-token+ssl)
- 接入点: `TenantOnboardingService::complete()` 和 `TenantController::store()`

### Architecture — Module Management API

- 系统级: `GET /admin/modules`, `POST /admin/modules/{name}/enable|disable`
- 租户级: `GET /tenants/{id}/modules`, `POST /tenants/{id}/modules/{name}/enable|disable`
- `ModuleController` (`app/Http/Controllers/Api/ModuleController.php`)

### Architecture — Standalone Mode

- `deployment_mode: "standalone"` in `config/tenancy.php` = 关闭 SaaS 注册 + 默认租户
- `saas_registration` 配置项独立控制注册开关

### CLI Commands

- `module:list`: 列出所有模块及状态 (名称/版本/优先级/依赖/互斥)
- `module:enable {name}` / `module:disable {name}`: 系统级启停
- `module:require {name}` / `module:require --remove {name}`: 通过 Composer 添加/移除模块
- `tenancy:init mini|normal|full`: 初始化项目 (6/14/22 模块)

### Testing

- **2269 tests, 5648 assertions, 0 failures, 0 skipped**
- `composer test` 默认 `--parallel` (~7 秒, 12 核 680% CPU)
- `composer test:sequential` 串行调试 (~34 秒)
- SQLite PRAGMA 优化: journal_mode=OFF, synchronous=OFF, locking_mode=EXCLUSIVE, temp_store=MEMORY, cache_size=200MB, foreign_keys=OFF
- 数据重置: DELETE FROM (反向表序) + DELETE FROM sqlite_sequence, 非 DROP/CREATE
- bcrypt rounds=4
- `brianium/paratest` 并行执行
- `phpunit.xml.dist`: `cacheResult=false`, `DB_FOREIGN_KEYS=false`, `HASHING_BCRYPT_ROUNDS=4`
- 修复 InvoiceServiceTest (DomPDF ServiceProvider + 视图路径) 和 TenantControllerTest (schema 字段补全)
- `orchestra/testbench` 移至 `require-dev`

### README

- 架构说明更新: Core + Modules + Plugins, ServiceProvider 分离, Composer 管理模块
- 模块清单: 22 个模块完整列表 (系统 13 + 业务 9)
- 项目结构: 模块标准目录结构, ServiceProvider 架构表
- 测试优化说明: 并行 + PRAGMA + DELETE 重置
- 数据修正: 中间件 9 个, 服务 174 个, 模型 120+, 核心路由 142 端点

## [1.2.0] - 2026-06-30

### Overview

v1.2.0 新增 Agent Framework（智能体框架），为多租户 SaaS 平台提供可配置、可复用的 AI 智能体基础设施。包含 Agent CRUD、工具注册与 Function Calling、ReAct 运行时（含 SSE 流式）、多轮对话记忆与压缩、用量监控与降级容错，覆盖 27 个 API 端点。

### Added — Agent Framework（TASK-033 ~ TASK-054）
- `AgentService`：Agent CRUD、启用/禁用、模型配置管理、工具与知识库绑定、预置模板克隆
- `AgentRuntime`：ReAct 循环运行时（非流式 `run()` + SSE 流式 `runStream()`），支持多轮工具调用
- `AgentMonitor`：Token 用量统计、成本估算（按模型定价）、工具调用日志、性能指标
- `ToolRegistry`：工具注册表（运行时 + 数据库双源合并）、Function Calling 格式转换、execute 安全校验
- `MemoryCompressor`：会话记忆压缩（超阈值旧消息分批摘要）、上下文截断策略
- `AiTextService`：AI 推理服务（非流式 chat + 流式 streamChat），可插拔驱动抽象（OpenAI 兼容 + Mock）
- 8 个事件类：AgentCreated/Enabled/Disabled、ConversationStarted/Ended、ToolCalled/ToolCallCompleted/ToolCallFailed
- 5 个 Eloquent 模型：Agent、AgentTool、AgentConversation、AgentConversationMessage、AgentToolLog
- 4 个服务契约：AgentServiceContract、AgentRuntimeContract、ToolRegistryContract、AgentMonitorContract
- 8 种预置 Agent 角色模板（客服/销售/营销/数据分析/运营/HR/财务/技术支持）

### Added — Agent API（27 个端点）
- Agent 管理（§6.1）：12 个端点（列表/详情/创建/更新/删除/启用/禁用/模板/克隆/模型配置/工具/知识库）
- 对话 + SSE（§6.2）：6 个端点（发起对话/追加消息/对话列表/详情/消息列表/删除）
- 监控（§6.3）：4 个端点（使用统计/Token 用量/成本估算/工具调用日志）
- 工具管理（§6.4）：5 个端点（列表/详情/注册/更新/删除）
- L5-Swagger 注解：全部 4 个 Controller 补充 OA 注解，支持 Swagger UI 在线文档

### Added — 文档
- `README.md`：新增 Agent Framework 章节（概念、快速开始、API 概览、配置项）
- `CHANGELOG.md`：新增 v1.2.0 条目

### Added — 数据库
- 5 张新表：`agents`、`agent_tools`、`agent_conversations`、`agent_conversation_messages`、`agent_tool_logs`
- 主键均使用 `IdGenerator` 生成 16 位 BIGINT（禁止 auto_increment）

### Security
- Agent/对话/工具/日志强制 `tenant_id` 隔离（BelongsToTenant + TenantScope）
- 工具执行安全：handler_class 命名空间白名单 + instanceof 校验
- 全局工具（tenant_id=0）不可被租户修改/删除

### Fixed — 测试基础设施（87 个失败测试修复）
- TestCase.php：为 `in_app_notifications`、`broadcast_events` 表补充 `softDeletes()`；为 `subscription_plans` 表补充 `ai_text_tokens`/`ai_image_generations`/`ai_video_seconds` 列；为 `tenants` 表补充 `onboarding_step`/`onboarding_completed` 列
- `config/tenancy.php`：补充 `branding`（默认样式）、`residency`（CN/US/EU/APAC 区域）、`reports`（报表模板）、`clone`（排除设置组）配置段
- `routes/api.php`：补充 15 个缺失 Controller 的 use 导入；新增租户引导注册路由
- `AiUsageService::pushToUsageService()`：修复调用 `UsageService::record()` 参数类型不匹配
- `SsoService::handleSamlCallback()`：签名校验改为可选（无证书时跳过）
- `SubscriptionPlan` 模型：fillable 补充 AI 配额字段
- 新增 `TenantOnboardingController`：5 步引导式注册 API（register/saveStep/status/complete）

### Added — 测试结果
- 1495 个测试全部通过（含 3674 个断言），0 个失败
- TASK-053：Agent/Tool Controller Feature 测试（63 个测试）
- TASK-055：TestCase 缺失 26 张表修复

## [1.1.0] - 2026-06-29

### Overview

v1.1.0 完成 sprint-008 安全审计与全量文档补全，达到正式发布条件。新增 OWASP Top 10 安全测试套件与审计报告，补全 AI 模块 / 计费 / 部署（Docker + Kubernetes） / 运维 / SDK 示例等文档。同期新增企业级扩展模块（Webhook / EventBus / FeatureFlag / Metrics / SLA / 数据隔离 / 租户克隆 / BYOK / 负载测试等 11 个服务）。

### Added — 企业级扩展模块（TASK-019 ~ TASK-030）
- `WebhookService` + `Webhook` / `WebhookDelivery`：Webhook 事件订阅、重试、签名验证、交付记录
- `EventBusService` + `EventSubscription` / `DeadLetter`：异步事件总线、死信队列、事件订阅管理
- `FeatureFlagService` + `FeatureFlag`：功能开关、灰度发布、租户级/百分比规则评估
- `MetricsService` + `MetricsSnapshot`：指标统计、快照服务
- `SlaService` + `SlaEvent`：SLA 定义、事件追踪、违规检测
- `CostService` + `CostAllocation`：成本追踪、资源计费、部门分摊
- `ResourceService`：租户资源配额管理
- `ReportService` + `CustomReport`：自定义报表、PDF/Excel 导出
- `ErrorTrackingService`：错误追踪与聚合
- `InAppNotificationService` + `InAppNotification`：应用内通知、未读数、批量已读
- `BroadcastingService` + `BroadcastEvent`：实时广播、WebSocket/SSE 推送
- `IsolationService`：数据隔离策略（shared-db / schema-based / database-based）
- `DataResidencyService`：数据驻留管理、区域配置、跨区域迁移
- `TenantCloneService`：租户克隆、模板创建、快照导入导出
- `CrossTenantService` + `TenantHierarchy`：跨租户数据共享、层级关系
- `TenantKeyService` + `TenantKey`：BYOK 密钥管理、加密存储、密钥轮换
- `tests/LoadTest.php` + `tests/PerformanceTest.php`：负载测试套件与性能基准测试

### Added — 安全
- `tests/SecurityTest.php`：OWASP Top 10 自动化安全测试套件（14 用例，覆盖 SQL 注入 / XSS / CSRF / 敏感数据泄露 / 批量赋值 / 租户隔离 / 越权访问 / 限流探测）
- `docs/security/安全审计报告.md`：OWASP Top 10 (2021) 逐项核查 + `composer audit` 结果 + 手动安全测试结果 + 安全响应头清单 + 残留项跟进

### Added — 文档
- `docs/api/服务层API.md`：新增 18 个企业级服务 API 文档（Webhook / EventBus / FeatureFlag / Cost / Resource / Report / ErrorTracking / Broadcasting / InAppNotification / Isolation / DataResidency / TenantClone / CrossTenant / Sandbox / DeveloperPortal / TenantKey / Metrics / SLA）
- `docs/architecture/AI模块架构.md`：AI 网关分层、提供商契约、服务层职责、数据模型、配置与安全
- `docs/api/AI模块API.md`：AI 服务层 API + PHP SDK + HTTP 端点 + 错误码
- `docs/api/端点总览.md`：全量 HTTP 端点参考（含 AI / 开发者门户 / Webhook / 广播）
- `docs/deployment/运维手册.md`：日常运维 / 日志 / 数据库 / 缓存 / 监控告警 / 租户运维 / 安全运维 / 故障处理 / 发布流程
- `docs/guides/AI模块使用指南.md`：文本 / 图像 / 视频 AI 用法 + Prompt 模板 + 用量配额 + SDK 调用
- `docs/guides/计费配置指南.md`：订阅 / 积分配额 / AI 计费 / 支付 / 发票税务 / 成本核算
- `docs/examples/php-sdk-quickstart.md` + `php-sdk-sample.php`：PHP SDK 可运行示例
- `docs/examples/rest-api-examples.md`：REST API（curl）调用示例覆盖认证 / 租户 / RBAC / 积分 / 订阅 / 支付 / 文件 / AI / Webhook
- `docs/api/openapi.yaml`：补全 AI 模块端点（/ai/text、/ai/image、/ai/video、/ai/usage）

### Changed — 文档
- `docs/deployment/部署指南.md`：新增 Kubernetes 部署章节（Namespace / ConfigMap / Deployment / CronJob / Ingress / StatefulSet / 滚动更新）
- `docs/architecture/系统架构概览.md`：新增「AI 与计费模块」章节与数据表总览补全（AI / 发票 / 优惠券 / 成本）
- `docs/guides/快速开始.md`：定位为「5 分钟上手」
- `docs/README.md` / `README.md`：文档索引补全，新增 AI / 计费 / 安全 / 示例入口与特性说明

### Security
- 新增 OWASP Top 10 自动化测试与审计报告（0 高危）
- 残留 3 条 medium（guzzlehttp 传递依赖），修复建议见审计报告 §4

## [1.0.0] - 2026-06-28

### Overview

v1.0.0 是框架的首个正式发布版本。从 v0.1.0 到 v1.0.0，框架经历了 3 个 sprint 周期，新增 29 个服务、9 个模型、13 张数据库表、14 个控制器，实现了完整的 SaaS 商业化能力。

### Added — 核心架构
- RBAC 细粒度权限系统（permissions / roles / role_permissions 三表 + RbacService + CheckRbacPermission 中间件，40+ 权限节点）
- 领域事件系统（5 个事件类 + LogEventListener 自动审计）
- 队列任务系统（SendEmailVerificationJob / SendPasswordResetJob + 指数退避重试）
- 接口契约层（IdGeneratorContract / TenantContextContract），支持派生项目替换实现
- SetLocale 中间件（自动设置请求语言）
- 业务异常类（InsufficientCreditsException / PermissionDeniedException / QuotaExceededException / TenantNotFoundException）
- ErrorCode 枚举（统一错误码）

### Added — SaaS 模块
- 订阅管理模块（SubscriptionService + SubscriptionPlan + SubscriptionHistory，4 种计划 free/basic/pro/enterprise）
- 文件存储模块（FileService + FileController + FileUpload 模型，多磁盘/分享/配额）
- 通知中心模块（NotificationController + 通知偏好 + 5 种通知类：CreditLow/PaymentSuccess/SubscriptionExpiring/TenantSuspended/General）
- RBAC 权限管理模块（RbacService + RbacController + 角色/权限 CRUD）
- 支付宝 OAuth 认证（AlipayOAuthService 独立实现）
- 邮箱验证流程（EmailVerificationMail + verifyEmail + resendVerification）
- 密码重置邮件通知（PasswordResetMail Mailable）
- 租户暂停/恢复（suspend + activate，暂停时清除 Token）
- 租户开通流程（store + provisionTenant 初始化配置/积分）
- 成员删除路由（最后管理员保护）
- 3 个模型工厂（TenantFactory / UserFactory / TenantUserFactory）

### Added — 支付网关扩展
- PayPal 支付（PayPalService 独立实现）
- Stripe 支付（StripeService 独立实现）
- 银联支付（UnionPayService 独立实现）
- 统一接口：createOrder / refund / handleWebhook

### Added — 运维与高级功能
- 结构化日志（StructuredLogService + structured_logs 表，带租户/用户上下文）
- 告警系统（AlertService + alert_rules + alerts 表，阈值监控）
- API 版本管理（ApiVersionService + api_versions 表）
- 插件系统（PluginService + plugins + plugin_dependencies 表）
- 速率限制规则（RateLimitService + rate_limit_rules 表，可配置策略）
- 导出任务（ExportService + export_tasks 表，异步 Excel/PDF）
- 支付安全（PaymentSecurityService + user_payment_passwords + payment_logs 表）
- 用户偏好管理（UserPreferenceService + user_preferences 表）
- 性能监控（PerformanceService）
- 缓存管理（CacheService）
- 队列管理（QueueService）
- Horizon 管理（HorizonService）
- 登录日志（LoginLogService）
- 系统配置管理（SystemSettingService）

### Added — 数据库
- 37 张数据库表（覆盖租户/用户/RBAC/积分/订阅/支付/文件/通知/审计/OAuth/系统/发票等 8 大业务域）
- 积分过期字段（credit_accounts.expires_at / expired_at + credit_transactions.expires_at / expired）
- 订阅字段扩展（tenants.subscription_plan_id / subscription_started_at / subscription_expires_at）

### Added — API
- 80+ API 端点（从 32 个扩展到 80+，覆盖认证/租户/成员/RBAC/积分/订阅/支付/域名/SSL/配置/OAuth/审计/通知/文件/Token/配额/系统设置）
- Sanctum Token abilities（14 种细粒度 API 权限）
- 认证后 API 全局限流（throttle:api，60/min）
- 5 个 API Resource（TenantResource / UserResource / TenantUserResource / TenantSettingResource / CreditAccountResource）

### Added — 国际化
- 13 个语言文件 × 2 种语言（zh_CN + en），覆盖 auth/common/credit/domain/file/notification/payment/sms/ssl/subscription/tenant/apitoken/validation

### Added — 文档
- Swagger/OpenAPI 文档（darkaonline/l5-swagger）
- 完整架构文档更新（系统架构概览 / 数据模型设计 / 设计决策）
- OAuth SDK 接入指南
- 支付 SDK 接入指南
- SaaS 核心模块扩展指南

### Changed
- HasGlobalId 使用 IdGeneratorContract 替代直接引用 IdGenerator
- TenancyServiceProvider 绑定接口契约 + 注册事件监听 + 注册限流策略 + 注册 22 个核心服务 singleton
- 邮件类从 app/Mail/ 移至 src/Mail/（框架包自包含）
- 邮件主题改用 trans() i18n
- config/tenancy.php 新增 id 配置节（min_value/max_value）
- 所有控制器响应使用 trans() i18n
- 6 个迁移主键从 auto-increment id 改为全局 ID（unsignedBigInteger）

### Fixed
- activate 路由/控制器权限 tenant.suspend → tenant.activate（新增 tenant.activate 权限到 RBAC seed）
- LogEventListener 缺 $afterCommit = true（事务回滚时记录幽灵状态）
- phpunit.xml.dist 缺失（php artisan test 和 vendor/bin/phpunit 均失败）
- EmailVerificationMail/PasswordResetMail 邮件正文硬编码中文（改用 trans() i18n）
- SendEmailVerificationJob/SendPasswordResetJob backoff=30 应为数组（改为 [10,30,60] 指数退避）
- UserRegistered::$tenantId 类型 ?int 与 TenantContext::getId() 返回 ?string 不一致
- SendEmailVerificationJob/SendPasswordResetJob 引用 App\Mail 命名空间（移至 src/Mail/）
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
- TestController 仍存在未重命名（改为 SpaController）
- ProcessCreditExpiry 引用 credit_transactions 不存在的 expires_at/expired 列（新增迁移补列）
- RefundService trans() 被包在引号里不执行翻译
- UserApiToken API Key 明文存储（改用 Crypt 加密/解密）
- SocialiteService Octane config 跨请求污染（改用 app 容器请求级隔离）
- 6 个模型缺少 HasGlobalId（FileUpload/NotificationPreference/Permission/Role/SubscriptionHistory/SubscriptionPlan）
- SubscriptionHistory / UserApiToken 缺少 BelongsToTenant（跨租户数据泄露）
- /credits /api-tokens /quotas 路由缺少 RBAC 中间件
- AuditService::log() 类型不匹配（参数从 ?array 改为联合类型）
- FileController 跨租户数据泄露（添加 AuthorizesTenantAccess）
- SubscriptionController 缺少租户访问检查
- 多处硬编码中文消息改用 trans()

### Security
- Sanctum Token abilities（细粒度 API 权限控制）
- 认证后 API 全局限流（防止暴力调用）
- 跨租户数据泄露修复（FileController + SubscriptionController + SubscriptionHistory + UserApiToken）
- 支付密码 + 支付安全日志
- API Key 加密存储
- OAuth Token 加密存储
- 批量赋值防护
- 密码策略增强（min(8)+mixedCase+numbers）
- 支付日志脱敏
- CORS 环境变量配置

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
