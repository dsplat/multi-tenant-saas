# Multi-Tenant SaaS Framework

开箱即用的 Laravel 多租户 SaaS 基础框架，为构建企业级多租户应用提供完整的解决方案。

📖 **完整文档**：[docs/README.md](docs/README.md) ｜ 🛡 [安全审计报告](docs/security/安全审计报告.md) ｜ 🚀 [5 分钟快速开始](docs/guides/快速开始.md) ｜ 🤖 [AI 模块](docs/guides/AI模块使用指南.md)

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-777BB4)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-%5E12.0-FF2D20)](https://laravel.com)
[![Version](https://img.shields.io/badge/version-v1.3.0-blue)](CHANGELOG.md)
[![Tests](https://img.shields.io/badge/tests-2100+%20passed%20(5000+%20assertions)-brightgreen)](#)

---

## 核心特性

### 🏢 四重访问架构

系统分为四个独立的访问层级，每个层级有不同的访问权限和用途：

| 层级 | 域名示例 | 路径 | 角色要求 | 说明 |
|------|----------|------|----------|------|
| **系统后台** | `admin.lyt.com` | `/*` | `super_admin` | 独立域名，避免暴力破解 |
| **租户后台** | `ai.lyt.com` | `/console/*` | `tenant_admin` | 租户管理后台 |
| **用户前台** | `ai.tenant1.local` | `/*` | `end_user` | 租户自定义域名 |
| **访客** | 同用户前台 | `/*` | 未登录 | 登录状态区分 |

### 🔒 数据隔离

- **全局作用域**：自动为所有查询添加 `WHERE tenant_id = ?`
- **自动填充**：创建记录时自动填充 `tenant_id`
- **透明操作**：业务代码无需关心租户隔离逻辑

```php
// 自动按租户过滤
Order::all();
// SQL: SELECT * FROM orders WHERE tenant_id = 123456

// 创建时自动填充 tenant_id
Order::create(['name' => '新订单']);
// 自动设置 tenant_id = 当前租户ID
```

### 🌐 多域名支持

- **单域名模式**：通过路径区分功能（`/console/*`、`/api/*`）
- **多域名模式**：租户使用独立域名，增强品牌感
- **域名白名单**：自动管理 Nginx 域名白名单
- **SSL 证书**：支持自定义域名 SSL 证书管理

### 👥 RBAC 权限控制

- **平台级角色**：`super_admin`（超级管理员）、`platform_user`（普通用户）
- **租户内角色**：`tenant_admin`（租户管理员）、`end_user`（终端用户）
- **细粒度权限**：40+ 权限节点，按 `tenant.view`、`member.create` 等命名
- **自定义角色**：租户可创建自定义角色并分配权限
- **中间件保护**：`rbac.permission:permission.name` 中间件实现路由级权限控制

### 🆔 全局唯一 ID

- 16 位随机数字，JavaScript 安全（`<= Number.MAX_SAFE_INTEGER`）
- 全局唯一，所有表共用 ID 空间
- 完全无序，无法推测业务增长

### 💰 积分/配额管理

- 租户级积分账户
- 用户级积分账户
- 积分过期与到期提醒
- 配额检查和限制
- 交易记录追溯

### 📋 订阅管理

- 订阅计划（free/basic/pro/enterprise）
- 月付/年付定价
- 试用期管理
- 订阅历史记录
- 升级/降级/取消

### 💳 多支付网关

| 支付方式 | 驱动 | 说明 |
|----------|------|------|
| 微信支付 | `wechat` | 通过 yansongda/pay |
| 支付宝 | `alipay` | 通过 yansongda/pay |
| PayPal | `paypal` | 独立 Service 实现 |
| Stripe | `stripe` | 独立 Service 实现 |
| 银联 | `unionpay` | 独立 Service 实现 |

- 统一的 `createOrder` / `refund` / `handleWebhook` 接口
- 支付安全日志
- 租户级支付配置

### 🔐 第三方登录

- 微信（企业微信）
- 钉钉
- 飞书
- 支付宝
- 租户独立配置

### 📁 文件存储

- 多磁盘支持（local/s3/oss）
- 文件分享（签名 URL）
- 存储配额管理
- 图片预览

### 🔔 通知中心

- 站内通知（Laravel Notification）
- 通知偏好配置
- 5 种内置通知类型：
  - 积分不足提醒
  - 支付成功通知
  - 订阅即将到期通知
  - 租户暂停通知
  - 通用通知

### 📝 审计日志

- 自动记录关键操作
- 支持自定义审计事件
- 租户隔离的日志查询

### 🌍 国际化

- 支持 `zh_CN` 和 `en` 双语
- 14 个语言文件覆盖所有业务模块
- `SetLocale` 中间件自动设置语言

### 📊 监控与运维

- **健康检查**：spatie/laravel-health 集成
- **结构化日志**：带租户/用户上下文的日志记录
- **告警系统**：阈值监控 + 告警规则
- **性能监控**：PerformanceService 追踪关键指标
- **队列监控**：Horizon 集成（开发环境）

### 🔧 高级特性

- **API 版本管理**：多版本 API 共存 + 废弃通知
- **插件系统**：租户级插件安装与管理
- **速率限制**：可配置的 API 限流规则
- **导出任务**：Excel/PDF 异步导出
- **支付安全**：支付密码 + 支付日志
- **Swagger/OpenAPI**：API 文档自动生成

### 🤖 AI 网关

- **多提供商统一接口**：OpenAI / 智谱 / Anthropic / DeepSeek（文本），DALL-E / Stability（图像），Runway / 可灵（视频）
- **租户级配置**：能力开关、自定义 API Key（加密）、模型白名单、月度预算
- **用量与配额**：按 `monthly` 周期聚合 token/张数/秒数，超额策略 `block`/`warn`/`allow`
- **异步视频生成**：提交 → 队列延迟轮询 → 完成事件回调 → 结果存储
- **流式输出**：`streamChat` 支持 SSE 风格逐 chunk 输出
- **Prompt 模板**：模板管理 + 变量渲染
- **PHP SDK**：`AiResource` 一行调用文本/图像/视频/用量

### 🤖 Agent Framework（智能体框架 — 8 个数字员工）

开箱即用的 AI 智能体基础设施，为每个租户提供 **8 个预置数字员工模板**，一键克隆即可投入使用：

| # | 角色 | 模板 Key | 职责 |
|---|------|----------|------|
| 1 | 客服专员 | `customer_service` | 接待客户咨询、解答疑问、处理投诉 |
| 2 | 销售顾问 | `sales` | 挖掘需求、推荐产品、跟进商机、促成成交 |
| 3 | 营销专员 | `marketing` | 策划活动、撰写文案、分析投放、优化转化 |
| 4 | 数据分析师 | `data_analyst` | 采集清洗数据、统计分析、输出报表与决策建议 |
| 5 | 运营专员 | `operations` | 日常运营、流程优化、活动执行与效果跟踪 |
| 6 | 人力资源 | `hr` | 招聘、培训、绩效评估、员工关系 |
| 7 | 财务助手 | `finance` | 账务处理、报销审核、发票管理、预算执行 |
| 8 | 技术支持 | `tech_support` | 解答技术问题、排查故障、IT 支持与工单管理 |

**核心能力：**

- **Agent CRUD**：创建、更新、删除、启用/禁用，支持 8 种预置角色模板一键克隆
- **工具注册与 Function Calling**：全局/租户私有工具双级管理，JSON Schema 定义参数，运行时动态注册与执行
- **ReAct 运行时**：非流式 `run()` + SSE 流式 `runStream()`，支持多轮工具调用（受 `max_tool_calls` 限制自动总结）
- **多轮对话记忆**：会话上下文自动管理，超阈值旧消息分批摘要压缩为单条 system 消息
- **降级容错**：AI 驱动异常自动切换 `fallback_provider` 重试；工具执行失败以 `role=tool` 返回 AI 决策
- **用量监控**：Token 用量统计、成本估算（按模型定价）、工具调用日志、性能指标
- **多租户隔离**：所有 Agent/对话/工具/日志强制 `tenant_id` 隔离

**快速开始：**

```php
use MultiTenantSaas\Contracts\AgentServiceContract;
use MultiTenantSaas\Contracts\AgentRuntimeContract;

// 创建 Agent
$agentService = app(AgentServiceContract::class);
$agent = $agentService->create([
    'name' => '客服助手',
    'role' => 'customer_service',
    'system_prompt' => '你是一个专业的客服助手',
    'model_config' => ['preferred_model' => 'gpt-4o-mini'],
]);

// 发起对话（非流式）
$runtime = app(AgentRuntimeContract::class);
$response = $runtime->run($agent->agent_id, $conversationId, '你好');
echo $response->message;

// 流式对话（SSE）
foreach ($runtime->runStream($agent->agent_id, $conversationId, '你好') as $chunk) {
    echo $chunk->text;
}
```

**API 概览（27 个端点）：**

| 分类 | 端点 | 说明 |
|------|------|------|
| Agent 管理（§6.1） | `GET/POST/PUT/DELETE /v1/agents` | CRUD + 启用/禁用/模板/克隆/配置 |
| 对话 + SSE（§6.2） | `POST /v1/agents/{id}/chat` | 发起对话（SSE 流式） |
| | `POST /v1/agents/{id}/chat/{cid}` | 追加消息（SSE 流式） |
| | `GET /v1/agents/{id}/conversations` | 对话列表 |
| | `GET/DELETE /v1/conversations/{id}` | 详情/删除 |
| | `GET /v1/conversations/{id}/messages` | 消息列表 |
| 监控（§6.3） | `GET /v1/agents/{id}/stats` | 使用统计 |
| | `GET /v1/agents/{id}/token-usage` | Token 用量 |
| | `GET /v1/agents/{id}/cost` | 成本估算 |
| | `GET /v1/agents/{id}/tool-logs` | 工具调用日志 |
| 工具管理（§6.4） | `GET/POST/PUT/DELETE /v1/tools` | 工具 CRUD |

**配置项（`config/ai.php`）：**

| 配置 | 说明 | 默认值 |
|------|------|--------|
| `ai.default_provider` | 默认 AI 提供商 | `openai` |
| `ai.default_model` | 默认模型 | `gpt-4o-mini` |
| `ai.providers.{name}.base_url` | 提供商 API 地址 | — |
| `ai.providers.{name}.api_key` | API Key | — |
| `ai.providers.{name}.models` | 可用模型列表 | — |
| `model_config.temperature` | 采样温度 | `0.7` |
| `model_config.max_tokens` | 最大输出 token | `4096` |
| `model_config.max_tool_calls` | 单次对话最大工具调用次数 | `5` |
| `model_config.fallback_provider` | 降级提供商 | — |

### 🎲 抽奖系统

完整的抽奖活动管理引擎，支持多奖品池、概率控制、防刷机制：

- **活动管理**：创建/更新/启用/禁用抽奖活动，支持时间范围、参与次数限制
- **奖品管理**：奖品池配置、库存管理、权重概率控制、乐观锁扣减
- **抽奖执行**：加权随机抽取、用户次数限制、IP 防刷、黑名单过滤
- **黑名单管理**：按用户 ID、IP 地址等维度封禁，支持租户级隔离
- **统计查询**：中奖率统计、用户抽奖记录、中奖记录列表

**数据模型：**

| 模型 | 说明 |
|------|------|
| `LotteryActivity` | 抽奖活动（时间范围、规则、状态） |
| `LotteryActivityPrize` | 活动奖品（库存、权重、概率） |
| `LotteryDrawLog` | 抽奖日志（结果、用户、时间） |
| `LotteryBlacklist` | 黑名单（用户/IP 封禁） |
| `LotteryPool` | 奖品池（全局奖品管理） |

### 📱 短信服务增强

完整的短信营销与管理平台：

- **模板管理**：创建/更新/审核短信模板，支持变量渲染
- **批量发送**：批量任务创建、定时发送、任务取消
- **到达率统计**：发送量/送达量/失败量/点击量/退订量统计
- **退订管理**：用户退订记录、退订检查、退订列表查询
- **多驱动支持**：log（日志）、ww（网建短信）、http（通用 HTTP 网关）

**数据模型：**

| 模型 | 说明 |
|------|------|
| `SmsTemplate` | 短信模板（内容、状态、审核） |
| `SmsBatchTask` | 批量任务（定时发送、状态追踪） |
| `SmsDeliveryStat` | 到达率统计（送达/失败/点击） |
| `SmsUnsubscribe` | 退订记录（用户退订管理） |

### 🎫 优惠券分享（裂变发券）

基于分享链接的裂变营销能力：

- **分享链接生成**：生成唯一分享码，记录分享关系
- **裂变发券**：分享人和被分享人各得一张优惠券
- **防滥用**：分享人不能接受自己的分享、租户隔离校验、行锁防并发
- **分享记录**：查询分享状态、接收时间、关联优惠券

**数据模型：**

| 模型 | 说明 |
|------|------|
| `CouponShare` | 分享记录（分享码、状态、接收人） |

### 🔌 MCP 服务器（Model Context Protocol）

AI 工具调用协议实现，支持 JSON-RPC 2.0 标准：

- **工具注册**：抽象基类 `McpToolRegistry`，子类实现 `registerTools()` 注册业务工具
- **JSON-RPC 2.0**：支持 `initialize`、`tools/list`、`tools/call`、`notifications/initialized` 方法
- **SSE 流式响应**：支持 `text/event-stream` Accept 头的流式工具调用
- **客户端管理**：`McpClientRegistry` 管理多个 MCP 客户端配置
- **技能生成**：`McpSkillGenerator` 生成客户端技能描述文件
- **访问日志**：`McpToolAccessLog` 记录工具调用详情

**核心组件：**

| 组件 | 说明 |
|------|------|
| `McpToolRegistry` | 工具注册表抽象基类 |
| `McpClientRegistry` | 客户端配置注册表 |
| `McpSkillGenerator` | 技能描述文件生成器 |
| `McpMiddleware` | MCP 请求认证中间件 |
| `McpServerController` | JSON-RPC 2.0 服务器控制器 |

### 📡 渠道扩展

新增多个消息渠道支持：

- **钉钉**：`DingTalkProvider` 钉钉机器人消息推送
- **Slack**：`SlackProvider` + `SlackSignatureValidator` Slack 消息与签名验证
- **微信公众号**：`WechatOfficialProvider` 微信公众号消息与事件处理
- **事件总线桥接**：`EventBusBridge` 渠道消息与事件总线集成

### 🔄 工作流引擎

可视化的业务流程编排引擎，支持复杂的多步骤自动化流程：

- **节点类型**：Task（任务）、Condition（条件分支）、Parallel（并行执行）、Delay（延迟等待）、SubWorkflow（子流程）
- **执行引擎**：`WorkflowEngine` 驱动节点按序执行，自动处理条件判断与分支选择
- **重试与回滚**：`RetryService` 支持指数退避重试，`RollbackService` 提供事务级回滚
- **注册表**：`WorkflowRegistry` 管理流程定义，支持版本化
- **定义解析**：`WorkflowDefinitionParser` 解析工作流定义 DSL
- **监控**：`WorkflowExecution` 模型记录每次执行的完整轨迹与节点状态

### 💬 对话中心

通用的会话与消息管理系统，为 Agent 对话和团队协作提供基础设施：

- **会话管理**：创建/归档会话，支持一对一和群组模式
- **消息系统**：富文本消息、附件上传、表情回应（Reaction）、@提及（Mention）
- **参与者**：邀请/移除参与者，角色权限管理
- **已读追踪**：`ReadStateService` 管理每条消息的已读状态
- **标签系统**：`TagService` 支持会话分类与筛选
- **会话隔离**：`SessionService` 管理独立对话上下文

### 🧠 记忆系统

为 AI 智能体提供长期记忆能力，支持租户级和实体级记忆：

- **MemoryService**：记忆的存储、检索与衰减管理
- **MemoryPipeline**：记忆处理流水线（提取 → 存储 → 检索 → 注入）
- **TenantMemory**：租户级知识积累（业务偏好、历史决策、常见问题）
- **EntityMemory**：实体级记忆（特定客户/项目的上下文信息）
- **记忆压缩**：`MemoryCompressor` 将旧对话摘要化，控制上下文窗口大小

### 📡 渠道抽象

统一的消息渠道管理，支持多渠道接入与路由：

- **ChannelManager**：渠道注册与管理（企业微信、微信公众号、小程序等）
- **MessageRouter**：消息路由规则引擎，按条件分发到不同渠道
- **微信生态**：内置企业微信（4 个文件）、微信公众号（2 个）、小程序（2 个）集成

### 🎯 AI 能力系统（14 种能力）

细粒度的 AI 能力注册与计费中间件：

| 类别 | 能力 |
|------|------|
| **文本** | TextCompletion / TextGeneration / TextSummarization / TextClassification / TextTranslation / Conversation / CodeGeneration / CodeReview / Embedding |
| **图像** | ImageGeneration / ImageEditing / ImageVariation |
| **视频** | VideoGeneration |

- `CapabilityRegistry`：能力注册与发现
- `CapabilityBillingService`：按能力维度计费

### 🏗 企业级扩展

- **Webhook**：事件驱动的 HTTP 回调，支持签名验证、重试、死信队列
- **事件总线**：`EventBusService` 解耦模块间通信
- **功能开关**：`FeatureFlagService` 灰度发布与 A/B 测试
- **SLA 管理**：`SlaService` + `SlaEvent` 模型追踪服务水平协议
- **成本分摊**：`CostService` 支持基础设施/AI/第三方成本归因与损益分析
- **数据驻留**：`DataResidencyService` 满足合规要求
- **GDPR 合规**：`GdprService` 支持数据导出与删除权
- **租户克隆**：`TenantCloneService` 一键复制租户配置与数据
- **沙箱环境**：`SandboxService` 为租户提供隔离测试环境
- **品牌定制**：`BrandingService` 租户级 UI 品牌配置
- **BYOK 加密**：`TenantKeyService` 支持租户自带密钥加密
- **数据保留**：`RetentionService` + `DataRetentionPolicy` 自动清理过期数据

### 💲 计费体系

- **订阅**：free / basic / pro / enterprise 四档计划，月付/年付，试用期
- **积分/配额**：租户级预付费积分账户，充值/消耗/退款/过期，配额检查
- **AI 用量计费**：按 token/张/秒计费，月度预算与超额策略
- **发票税务**：发票开具、税率配置、优惠券核销
- **成本核算**：基础设施 / AI / 第三方成本分摊 + 损益与趋势预测

### 🛡 安全

- **OWASP Top 10 合规**：0 高危（见 [安全审计报告](docs/security/安全审计报告.md)）
- **租户数据隔离**：全局作用域 + 跨租户 403
- **RBAC + Token abilities**：40+ 权限节点 + 14 种 API 权限
- **敏感数据保护**：密码哈希、敏感字段隐藏、手机号脱敏、API Key/Tokens 加密存储
- **安全响应头**：`X-Content-Type-Options` / `X-Frame-Options` / HSTS
- **限流与 MFA**：认证端点限流 + TOTP/邮箱/短信多因素认证

---

## 快速开始

### 安装

```bash
composer create-project luoyueliang/multi-tenant-saas my-saas-app
cd my-saas-app
```

### 环境配置

```bash
cp .env.example .env
php artisan key:generate
```

编辑 `.env` 文件，配置数据库和域名：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=multi_tenant_saas
DB_USERNAME=your_username
DB_PASSWORD=your_password

ADMIN_DOMAIN=admin.example.com
```

### 数据库迁移

```bash
php artisan migrate
php artisan db:seed
```

> `db:seed` 会创建平台默认租户（ID: 9007199254740991）

### 创建测试数据

```bash
php artisan tinker
```

```php
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\TenantUser;

// 创建系统管理员
$admin = User::create([
    'name' => '系统管理员',
    'email' => 'admin@example.com',
    'password' => bcrypt('password'),
    'role' => 'super_admin',
]);

// 创建租户
$tenant = Tenant::create([
    'name' => '示例企业',
    'slug' => 'example',
    'custom_domain' => 'ai.example.com',
    'status' => 'active',
]);

// 关联用户到租户
TenantUser::create([
    'tenant_id' => $tenant->tenant_id,
    'user_id' => $admin->id,
    'role' => 'tenant_admin',
    'is_active' => true,
]);
```

### 配置 Nginx

```nginx
server {
    listen 80;
    server_name ai.example.com;
    root /path/to/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
}
```

---

## 项目结构

```
multi-tenant-saas/
├── app/
│   ├── Http/
│   │   ├── Controllers/    # 控制器（26 个）
│   │   ├── Middleware/     # 自定义中间件
│   │   └── Resources/      # API Resource（10 个）
│   ├── Models/             # 业务模型
│   └── Notifications/      # 通知类（5 种）
├── config/
│   ├── tenancy.php         # 框架核心配置
│   ├── ai.php              # AI 网关 & Agent 配置
│   ├── id.php              # ID生成器配置
│   ├── pay.php             # 支付配置
│   ├── socialite.php       # 第三方登录配置
│   ├── queue.php           # 队列配置
│   ├── health.php          # 健康检查配置
│   ├── sanctum.php         # API认证配置
│   ├── cors.php            # CORS配置
│   ├── l5-swagger.php      # Swagger配置
│   ├── channel.php         # 渠道配置
│   ├── cache.php           # 缓存配置
│   └── database.php        # 数据库配置
├── database/
│   ├── factories/          # 模型工厂（3 个）
│   ├── migrations/         # 数据库迁移（98 张表）
│   └── seeders/            # 数据填充（4 个）
├── docs/                   # 文档
├── lang/                   # 国际化
│   ├── zh_CN/              # 中文（14 个文件）
│   └── en/                 # 英文（14 个文件）
├── resources/js/
│   ├── admin/              # 系统后台 SPA（15 个组件）
│   └── console/            # 租户控制台 SPA（16 个组件）
├── routes/
│   ├── api.php             # API 路由（190+ 端点）
│   └── web.php             # Web 路由
├── src/                    # 框架核心代码
│   ├── Concerns/           # Traits（3 个：BelongsToTenant / EnsuresTenantContext / HasGlobalId）
│   ├── Context/            # 上下文管理（TenantContext）
│   ├── Contracts/          # 接口定义（17 个）
│   ├── DTOs/               # 数据传输对象（MessageDTO / WorkflowDefinition）
│   ├── Enums/              # 枚举（ErrorCode）
│   ├── Events/             # 领域事件（14 个）
│   ├── Exceptions/         # 业务异常（6 个）
│   ├── Helpers/            # 辅助函数
│   ├── Http/               # HTTP 层（Controllers / Middleware）
│   ├── Isolation/          # 数据隔离策略（3 种：SharedDatabase / SchemaPerTenant / DatabasePerTenant）
│   ├── Jobs/               # 队列任务（5 个）
│   ├── Listeners/          # 事件监听器
│   ├── Mail/               # 邮件类
│   ├── Mcp/                # MCP 服务器（Model Context Protocol）
│   │   ├── Exceptions/     # MCP 异常类
│   │   ├── Tools/          # 内置工具集
│   │   ├── McpToolRegistry.php    # 工具注册表抽象基类
│   │   ├── McpClientRegistry.php  # 客户端配置注册表
│   │   ├── McpSkillGenerator.php  # 技能描述文件生成器
│   │   └── McpMiddleware.php      # MCP 请求认证中间件
│   ├── Middleware/          # 中间件（8 个）
│   ├── Models/             # 框架模型（85+ 个）
│   │   ├── Lottery/        # 抽奖系统模型（6 个）
│   │   ├── Sms/            # 短信服务模型（4 个）
│   │   └── ...
│   ├── Modules/            # 可选模块（4 个）
│   │   ├── ApiToken/       # API Token 模块
│   │   ├── Domain/         # 域名管理模块
│   │   ├── Payment/        # 支付模块
│   │   └── SSL/            # SSL证书模块
│   ├── SDK/                # PHP SDK 客户端（5 个）
│   ├── Scopes/             # 全局作用域（TenantScope）
│   ├── Services/           # 服务层（170+ 个，含 AI/Agent/Workflow/Lottery/SMS 等子系统）
│   │   ├── Agent/          # 智能体服务
│   │   ├── Ai/             # AI 服务
│   │   ├── Capability/     # AI 能力服务
│   │   ├── Channel/        # 渠道服务（含钉钉/Slack/微信公众号）
│   │   ├── Conversation/   # 对话中心服务
│   │   ├── Memory/         # 记忆系统服务
│   │   ├── Tool/           # 工具服务
│   │   └── Workflow/       # 工作流服务
│   ├── EnterpriseWechat/   # 企业微信集成
│   ├── WechatOfficial/     # 微信公众号集成
│   ├── WechatMiniProgram/  # 微信小程序集成
│   └── TenancyServiceProvider.php
├── tests/                  # 测试（127 个文件，1874 个测试，4559 个断言）
└── composer.json
```

---

## 核心组件

### 中间件

| 中间件 | 别名 | 说明 |
|--------|------|------|
| `IdentifyDomain` | `domain.identify` | 识别域名类型（admin/console/api/app） |
| `IdentifyTenant` | `tenant.identify` | 识别当前租户 |
| `CheckPermission` | `tenant.permission` | 角色级权限控制 |
| `CheckRbacPermission` | `rbac.permission` | RBAC 细粒度权限控制 |
| `EnsureTenantContext` | `tenant.ensure` | 确保租户上下文有效 |
| `SetLocale` | `locale.set` | 自动设置请求语言 |
| `CheckFeatureFlag` | `feature.flag` | 功能开关检查 |
| `CheckIpWhitelist` | `ip.whitelist` | IP 白名单校验 |

### 服务（159 个）

| 分类 | 代表服务 | 说明 |
|------|------|------|
| **基础** | `IdGenerator` | 16位随机ID生成器 |
| | `TenantService` | 租户CRUD管理 |
| | `TenantSettingService` | 租户配置管理 |
| | `TenantMemberService` | 成员管理 |
| | `TenantProfileService` | 租户档案管理 |
| | `UserService` / `UserProfileService` | 用户管理 |
| **权限** | `RbacService` | RBAC权限管理 |
| | `MfaService` | 多因素认证（TOTP/邮箱/短信） |
| | `SsoService` | SSO 单点登录 |
| | `IpWhitelistService` | IP 白名单管理 |
| | `ConsentService` | 用户授权同意管理 |
| | `SessionService` / `TrustedDeviceService` | 会话与设备管理 |
| **积分/计费** | `TenantCreditService` | 积分/配额管理 |
| | `QuotaService` / `UsageService` | 配额检查与用量追踪 |
| | `SubscriptionService` / `PlanChangeService` / `TrialService` | 订阅全生命周期 |
| | `InvoiceService` / `TaxService` / `CouponService` | 发票、税率、优惠券 |
| | `CostService` | 成本分摊与损益分析 |
| | `RefundService` / `DunningService` | 退款与催收 |
| **支付** | `PayService` | 支付统一入口（微信/支付宝） |
| | `PayPalService` / `StripeService` / `UnionPayService` | 独立支付网关 |
| | `PaymentSecurityService` | 支付安全 |
| **OAuth** | `SocialiteService` | 第三方登录（微信/钉钉/飞书） |
| | `AlipayOAuthService` | 支付宝OAuth |
| **文件** | `FileService` / `ExcelService` / `PdfService` | 文件存储与导出 |
| **通知** | `NotificationService` / `InAppNotificationService` | 通知中心 |
| | `MailTemplateService` / `SmsService` | 邮件模板与短信 |
| | `BroadcastingService` | 实时广播 |
| **审计** | `AuditService` / `StructuredLogService` / `LoginLogService` | 审计日志 |
| **运维** | `CacheService` / `QueueService` / `HorizonService` | 缓存/队列 |
| | `PerformanceService` / `AlertService` / `HealthService` | 性能/告警/健康检查 |
| | `SystemSettingService` / `MetricsService` / `SlaService` | 系统配置/指标/SLA |
| | `ExportService` / `ReportService` | 导出与报表 |
| | `ErrorTrackingService` | 错误追踪（Sentry） |
| **AI 网关** | `AiGatewayService` / `AiConfigService` | AI 统一网关与配置 |
| | `AiTextService` / `AiImageService` / `AiVideoService` | 文本/图像/视频生成 |
| | `AiUsageService` | AI 用量统计 |
| **Agent** | `AgentService` | Agent CRUD + 配置管理 |
| | `AgentRuntime` | ReAct 运行时（非流式 + SSE 流式） |
| | `AgentMonitor` | Agent 用量监控与成本估算 |
| | `ToolRegistry` | 工具注册表（运行时 + 数据库双源） |
| | `MemoryCompressor` | 会话记忆压缩 |
| **对话中心** | `ConversationService` / `MessageService` | 会话与消息管理 |
| | `SessionService` / `ParticipantService` | 会话参与者管理 |
| | `ReadStateService` / `TagService` | 已读状态与标签 |
| **工作流** | `WorkflowEngine` / `WorkflowService` | 工作流引擎 |
| | `WorkflowRegistry` | 工作流注册表 |
| | `RetryService` / `RollbackService` | 重试与回滚 |
| **渠道** | `ChannelManager` / `MessageRouter` | 消息渠道抽象与路由 |
| **记忆** | `MemoryService` / `MemoryPipeline` | 记忆系统 |
| | `TenantMemory` / `EntityMemory` | 租户/实体记忆 |
| **能力** | `CapabilityRegistry` / `CapabilityBillingService` | AI 能力注册与计费（14 种能力） |
| **企业扩展** | `WebhookService` / `EventBusService` | Webhook 与事件总线 |
| | `FeatureFlagService` | 功能开关 |
| | `DataResidencyService` / `GdprService` / `RetentionService` | 数据驻留/GDPR/保留策略 |
| | `TenantCloneService` / `SandboxService` | 租户克隆与沙箱 |
| | `BrandingService` / `TenantKeyService` | 品牌定制与 BYOK 加密 |
| | `DeveloperPortalService` / `ResourceService` | 开发者门户 |
| **高级** | `ApiVersionService` / `PluginService` / `RateLimitService` | API版本/插件/限流 |
| | `DomainService` / `SslService` / `NginxConfigService` | 域名/SSL/Nginx |
| | `UserPreferenceService` / `CrossTenantService` | 用户偏好/跨租户 |
| **抽奖** | `LotteryService` | 抽奖活动/奖品/执行/黑名单/统计 |
| **短信** | `SmsService` | 短信模板/批量发送/到达率/退订 |
| **优惠券** | `CouponService` | 优惠券/模板/批量发券/裂变发券/规则 |
| **MCP** | `McpToolRegistry` / `McpClientRegistry` | MCP 工具注册/客户端管理 |
| | `McpSkillGenerator` | MCP 技能描述文件生成 |
| **投票** | `VotingService` | 投票活动管理 |
| **表单** | `FormBuilderService` | 动态表单构建 |
| **入驻** | `TenantOnboardingService` | 租户引导式注册 |
| **密码** | `PasswordPolicyService` | 密码策略管理 |

### 模型（85+ 个）

| 分类 | 模型 | 说明 |
|------|------|------|
| **核心** | `Tenant` / `User` / `TenantUser` | 租户、用户、关系 |
| | `TenantSetting` / `SystemSetting` | 租户/系统配置 |
| | `Role` / `Permission` / `RolePermission` | RBAC 权限 |
| **计费** | `CreditAccount` / `CreditTransaction` | 积分账户与交易 |
| | `FinancialRecord` / `PaymentOrder` | 财务记录与支付订单 |
| | `Invoice` / `Coupon` / `CouponShare` | 发票/优惠券/优惠券分享 |
| | `TaxRule` | 税率规则 |
| | `SubscriptionPlan` / `SubscriptionHistory` | 订阅计划与历史 |
| | `UsageRecord` / `CostAllocation` | 用量记录与成本分摊 |
| **安全** | `MfaDevice` / `MfaRecoveryCode` | 多因素认证设备与恢复码 |
| | `UserSession` / `TrustedDevice` | 会话与可信设备 |
| | `PasswordHistory` / `SsoProvider` | 密码历史与 SSO |
| | `IpWhitelist` / `Consent` | IP白名单与授权同意 |
| **AI** | `AiProvider` / `AiPrompt` / `AiModelAlias` | AI 提供商/提示词/模型别名 |
| | `AiUsageQuota` / `AiRequest` | AI 用量配额与请求记录 |
| **Agent** | `Agent` / `AgentTool` | 智能体与工具 |
| | `AgentConversation` / `AgentConversationMessage` | 对话与消息 |
| | `AgentToolLog` | 工具调用日志 |
| **对话中心** | `Conversation` / `Message` / `Participant` | 会话/消息/参与者 |
| | `Attachment` / `Reaction` / `Mention` | 附件/表情回应/@提及 |
| | `ReadState` / `ConversationSession` / `ConversationTag` | 已读/会话/标签 |
| **工作流** | `Workflow` / `WorkflowNode` / `WorkflowExecution` | 工作流定义与执行 |
| **抽奖系统** | `LotteryActivity` / `LotteryActivityPrize` | 抽奖活动与活动奖品 |
| | `LotteryDrawLog` / `LotteryBlacklist` | 抽奖日志与黑名单 |
| | `LotteryPool` / `LotteryPrize` | 奖品池与全局奖品 |
| **短信服务** | `SmsTemplate` / `SmsBatchTask` | 短信模板与批量任务 |
| | `SmsDeliveryStat` / `SmsUnsubscribe` | 到达率统计与退订记录 |
| **MCP** | `McpClient` / `McpTool` | MCP 客户端与工具 |
| | `McpToolAccessLog` | MCP 工具访问日志 |
| **企业** | `Webhook` / `WebhookDelivery` / `EventSubscription` | Webhook 与事件订阅 |
| | `FeatureFlag` / `MetricsSnapshot` / `SlaEvent` | 功能开关/指标快照/SLA |
| | `BrandingConfig` / `TenantHierarchy` / `SandboxEnvironment` | 品牌/层级/沙箱 |
| | `DataRetentionPolicy` / `CustomReport` / `InAppNotification` | 保留策略/报表/站内通知 |
| **文件** | `FileUpload` | 文件上传 |
| **其他** | `AuditLog` / `NotificationPreference` / `UserApiToken` | 审计/通知偏好/API Token |

### 模块

| 模块 | 说明 |
|------|------|
| `ApiToken` | API Token 管理（用户级 Token + abilities） |
| `Domain` | 域名管理（白名单/自定义域名） |
| `Payment` | 支付模块（多网关统一接口） |
| `SSL` | SSL 证书管理（申请/续期/部署） |

---

## 使用示例

### 继承基类模型

```php
use MultiTenantSaas\Models\Tenant;

class Customer extends Tenant
{
    protected $primaryKey = 'customer_id';
    
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
    ];
}
```

### 使用辅助函数

```php
// 获取当前租户ID
$tenantId = tenant_id();

// 获取租户配置
$corpId = tenant_config('wecom', 'corp_id');

// 检查配额
check_quota('customers', 1);

// 生成唯一ID
$id = generate_id();
```

### 路由配置

```php
// 系统后台路由
Route::prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard']);
});

// 租户后台路由
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    Route::get('/', [ConsoleController::class, 'dashboard']);
});

// 需要特定角色的路由
Route::middleware(['tenant.permission:tenant_admin'])->group(function () {
    // 仅 tenant_admin 可访问
});
```

### 查询数据

```php
// 自动按租户过滤（模型需使用 BelongsToTenant Trait）
$orders = Order::all();

// 跨租户查询（仅 admin 域名下可用）
$allOrders = Order::withoutTenantScope()->get();

// 指定租户查询（仅 admin 域名下可用）
$tenantOrders = Order::withTenant('1234567890123456')->get();

// 查询所有租户数据（仅 admin 域名下可用）
$allOrders = Order::forAllTenants()->get();
```

---

## 文档

- [文档目录](docs/README.md)
- [系统架构概览](docs/architecture/系统架构概览.md)
- [多域名架构设计](docs/architecture/多域名架构设计.md)
- [租户隔离架构](docs/architecture/租户隔离架构.md)
- [数据模型设计](docs/architecture/数据模型设计.md)
- [设计决策](docs/architecture/设计决策.md)
- [快速开始（5 分钟上手）](docs/guides/快速开始.md)
- [四重访问架构](docs/guides/四重访问架构.md)
- [域名配置指南](docs/guides/域名配置指南.md)
- [权限控制指南](docs/guides/权限控制指南.md)
- [AI 模块使用指南](docs/guides/AI模块使用指南.md)
- [计费配置指南](docs/guides/计费配置指南.md)
- [OAuth SDK接入指南](docs/guides/OAuth_SDK接入指南.md)
- [支付SDK接入指南](docs/guides/支付SDK接入指南.md)
- [SaaS核心模块扩展指南](docs/guides/SaaS核心模块扩展指南.md)
- [部署指南（Docker / Kubernetes）](docs/deployment/部署指南.md)
- [运维手册](docs/deployment/运维手册.md)
- [发布检查清单](docs/deployment/发布检查清单.md)
- [备份恢复流程](docs/deployment/备份恢复流程.md)
- [故障应急手册](docs/deployment/故障应急手册.md)
- [监控告警配置](docs/deployment/监控告警配置.md)
- [Nginx配置指南](docs/deployment/Nginx配置指南.md)
- [本地开发环境](docs/development/本地开发环境.md)
- [编码规范](docs/development/coding-standards.md)
- [HTTP 端点总览](docs/api/端点总览.md)
- [AI 模块 API](docs/api/AI模块API.md)
- [核心API](docs/api/核心API.md)
- [中间件API](docs/api/中间件API.md)
- [服务层API](docs/api/服务层API.md)
- [OpenAPI规范](docs/api/openapi.yaml)
- [安全审计报告（OWASP Top 10）](docs/security/安全审计报告.md)
- [框架层升级规划](docs/Requirement/框架层升级规划.md)
- [框架层升级规划 - 工作量评估](docs/Requirement/框架层升级规划_task-eff.md)
- [PHP SDK 使用示例](docs/examples/php-sdk-quickstart.md)
- [REST API 调用示例](docs/examples/rest-api-examples.md)
- [需求文档](docs/requirement.md)

---

## 技术栈

- **PHP**: ^8.2
- **Laravel**: ^12.0
- **数据库**: MySQL 8.0+
- **缓存**: Redis (推荐) / Database
- **Web服务器**: Nginx + PHP-FPM
- **前端**: Vue.js 3 + TypeScript + Vite（系统后台 15 组件 / 租户控制台 16 组件）
- **CSS**: Bootstrap（管理后台 UI）

## 集成库

| 库 | 用途 | 配置 |
|---|---|---|
| `laravel/sanctum` | API 认证 + Token abilities | `config/sanctum.php` |
| `laravel/socialite` | 第三方登录（微信/钉钉/飞书） | `config/socialite.php` |
| `yansongda/pay` | 支付（微信/支付宝） | `config/pay.php` |
| `spatie/laravel-health` | 健康检查 | `config/health.php` |
| `darkaonline/l5-swagger` | Swagger/OpenAPI 文档 | `config/l5-swagger.php` |
| `maatwebsite/excel` | Excel 导入导出 | 内置 |
| `barryvdh/laravel-dompdf` | PDF 生成 | 内置 |
| `laravel/horizon` | 队列监控 (dev) | `/horizon` |
| `sentry/sentry-laravel` | 错误追踪 (dev) | `.env` |
| `guzzlehttp/guzzle` | HTTP 客户端（短信/渠道） | 内置 |

## 更新框架

```bash
composer update luoyueliang/multi-tenant-saas
```

---

## 许可证

MIT License

---

## 贡献

欢迎提交 Issue 和 Pull Request！

---

## 致谢

感谢 [aistudio_backend](https://github.com/luoyueliang/aistudio_backend) 项目提供的架构参考。
