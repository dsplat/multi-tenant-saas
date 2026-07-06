# 多租户SaaS平台需求规划

## 需求1: MCP框架化基础设施
### 优先级: P0
### 描述
将 multi_tenant_saas 框架层扩展为支持 MCP（Model Context Protocol）的基础设施平台。
### 子任务
1. McpServerController - JSON-RPC 2.0 协议处理器，支持 SSE 流式响应
2. McpException - 标准 JSON-RPC 错误码定义
3. McpToolRegistry - MCP 工具注册抽象基类
4. McpClientRegistry - 多 AI 客户端管理（WorkBuddy/Hermers/OpenClaw）
5. McpSkillGenerator - 自动生成 Skill 文件/配置
6. McpMiddleware - Sanctum Token 认证 + 租户隔离
7. Route::mcp() 宏 - 一行代码注册 MCP 端点
8. 数据库迁移 - MCP 相关表（mcp_clients, mcp_client_tokens, mcp_tool_access_logs）

## 需求2: 能力引擎
### 优先级: P1
### 描述
新增三个能力引擎：表单构建器、抽奖系统、投票系统。
### 子任务
1. FormBuilderService - 通用表单构建 + 数据收集
2. LotteryService - 概率控制、防刷、奖品池管理
3. VotingService - 排行榜、防刷票
4. 数据库迁移 - 表单、抽奖、投票相关表

## 需求3: 现有服务扩展
### 优先级: P2
### 描述
扩展现有优惠券和短信服务的功能。
### 子任务
1. CouponService 扩展 - 批量发券、裂变发券、券模板、用券规则
2. SmsService 扩展 - 营销短信模板、批量发送、定时发送、到达率统计
3. 数据库迁移 - 优惠券和短信扩展表

## 技术约束
1. 项目框架: Laravel PHP，遵循 PSR-4 命名空间规范
2. 代码位置:
   - MCP 组件: src/Mcp/ 目录
   - 引擎服务: src/Services/ 目录
   - 合约接口: src/Contracts/ 目录
   - 数据库迁移: database/migrations/ 目录
3. 现有依赖:
   - 已有 ToolRegistryContract 用于 Agent 工具注册
   - 已有 CouponService 和 SmsService 基础实现
   - 已有 Sanctum 认证系统
4. 兼容性要求:
   - 新增 MCP 工具注册表需与现有 ToolRegistryContract 共存
   - 多客户端适配需支持不同格式（Markdown Skill、JSON 配置）
