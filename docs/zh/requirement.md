# multi_tenant_saas 框架层需求：MCP 框架化 + 能力引擎

## 项目背景

multi_tenant_saas 是 SaaS 框架层，提供可复用的能力引擎。当前需要新增 MCP 基础设施（JSON-RPC 2.0 协议处理 + 多 AI 客户端适配）和三个新的能力引擎（表单、抽奖、投票），同时扩展现有的优惠券和短信服务。

## 需求一：MCP 框架化

将 JSON-RPC 2.0 协议处理从 SCRM 业务层提取到框架层，支持多 AI 客户端（WorkBuddy/Hermers/OpenClaw）。

### 1.1 McpServerController（协议处理器）

处理 JSON-RPC 2.0 请求（initialize / tools/list / tools/call / notifications/initialized），不关心具体工具实现。支持 SSE 流式响应（text/event-stream）。

### 1.2 McpException（标准错误码）

JSON-RPC 标准错误码：-32700（Parse error）、-32600（Invalid Request）、-32601（Method not found）、-32603（Internal error）。

### 1.3 McpToolRegistry（抽象基类）

定义工具注册契约，业务层继承并实现 `registerTools()`。提供 `tool()` 方法注册工具，`listTools()` 和 `callTool()` 通用实现。

### 1.4 McpClientRegistry（多客户端管理）

管理不同 AI 客户端的注册、工具白名单、Skill 文件生成。支持 WorkBuddy（Markdown Skill）、Hermers（JSON 配置）、OpenClaw（JSON 配置）。

### 1.5 McpSkillGenerator（自动生成 Skill 文件）

根据客户端类型和工具注册表，自动生成对应格式的 Skill 文件/配置。WorkBuddy 输出 .md Skill，Hermers/OpenClaw 输出 JSON 配置。

### 1.6 McpMiddleware（认证 + 租户隔离）

Sanctum Token 认证 + 租户上下文解析。

### 1.7 Route::mcp() 宏

一行代码注册 MCP 端点：`Route::mcp('scrm', ScrmMcpToolRegistry::class)`，自动注册 POST /api/v1/mcp、GET /api/v1/mcp/{client}/skill、GET /api/v1/mcp/{client}/config、GET /api/v1/mcp/clients。

### 1.8 数据库迁移（框架层 MCP 表）

- `create_mcp_clients_table` — MCP 客户端注册表（name, skill_format, tool_scope, api_key, status）
- `create_mcp_client_tokens_table` — 客户端 Token 表（client_id, token, scopes, expires_at）
- `create_mcp_tool_access_logs_table` — 工具调用审计日志（client_id, tool_name, tenant_id, request, response, duration）

## 需求二：能力引擎

### 2.1 FormBuilderService（表单引擎）

通用表单构建 + 数据收集。支持拖拽式表单设计、多题型（单选/多选/填空/评分/上传/签名）、表单发布、数据收集与统计、数据导出。

数据库迁移：
- `create_forms_table` — 表单引擎（title, description, fields_schema, status）
- `create_form_fields_table` — 表单字段（form_id, field_type, label, required, options）
- `create_form_responses_table` — 表单回复（form_id, respondent_id, answers, submitted_at）

### 2.2 LotteryService（抽奖引擎）

概率控制、防刷、奖品池管理。支持大转盘/刮刮卡/砸金蛋/盲盒、中奖概率控制、抽奖次数规则、防刷机制。

数据库迁移：
- `create_lottery_pools_table` — 抽奖池（name, prize_config, probability_rules, anti_abuse_config）
- `create_lottery_prizes_table` — 奖品（pool_id, name, type, quantity, probability）
- `create_lottery_records_table` — 抽奖记录（pool_id, user_id, prize_id, result, drawn_at）

### 2.3 VotingService（投票引擎）

排行榜、防刷票。支持投票主题配置、选项管理（图文/视频选项）、投票规则、排行榜实时展示、防刷票机制。

数据库迁移：
- `create_voting_polls_table` — 投票主题（title, options, voting_rules, status）
- `create_voting_records_table` — 投票记录（poll_id, option_id, voter_id, voted_at）

### 2.4 CouponService 扩展

现有 `CouponService` + `Coupon`/`CouponUsage` 已有基础优惠券核销能力。需要扩展：批量发券 API、裂变发券 API、券模板管理（满减/折扣/兑换/现金券）、用券规则（门槛/有效期/适用商品/互斥规则）。

### 2.5 SmsService 扩展

现有 `SmsService` 已有验证码发送能力。需要扩展：营销短信模板管理（变量插入、审核）、批量发送（按标签/分组/自定义筛选）、定时发送、到达率统计、短信签名管理。

## 技术约束

- 项目类型：Laravel PHP
- 代码位置：`src/` 目录下
- 数据库迁移：`database/migrations/` 目录
- 遵循现有 PSR-4 命名空间规范
- MCP 组件放在 `src/Mcp/` 目录
- 引擎服务放在 `src/Services/` 目录
- 合约接口放在 `src/Contracts/` 目录