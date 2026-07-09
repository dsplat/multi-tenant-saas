# Agent Framework 框架层需求描述

> **目标仓库**: `https://github.com/dsplat/multi-tenant-saas`
> **用途**: 为 SCRM 平台及其他业务系统提供统一的 AI 智能体（数字员工）基础设施
> **版本**: v1.0

---

## 1. 需求背景

SCRM 平台有 51 个 AI 功能点分布在 15 个业务模块中，如果每个功能点都需要用户单独配置开关和驱动选择，用户将面临 51 个配置项的认知负担。

**解决方案：数字员工架构** — 用 8 个数字员工角色覆盖全部 51 个功能点。用户只需管理数字员工（启用/禁用、选择驱动/模型），每个数字员工自动管理其下所有功能点。

数字员工本质上是一个 AI Agent，具备角色定义、工具集、知识库访问、模型配置和对话记忆。

**为什么放在框架层：** 智能体是跨业务模块的基础设施，需要访问全业务数据、租户隔离、复用现有 AI 基础设施、多终端复用。

---

## 2. 核心概念

### 2.1 Agent（智能体/数字员工）

一个 Agent 是一个可配置的 AI 角色：

| 属性 | 说明 | 示例 |
|------|------|------|
| 角色定义 | 系统提示词，定义 Agent 的人格和行为 | "你是一个专业的客服顾问..." |
| 工具集 | Agent 可以调用的 Function Calling 工具 | search_customer, send_message |
| 知识库 | Agent 可以访问的知识库 | kb_001, kb_002 |
| 模型配置 | 驱动选择、降级策略、温度参数 | openai/gpt-4o-mini |
| 功能点映射 | (业务层) Agent 管理的 AI 功能点 | auto_reply, sentiment_detect |

### 2.2 Tool（工具）

一个 Tool 是一个 Agent 可以调用的函数，通过 Function Calling 机制暴露给 AI：

```json
{
  "name": "search_customer",
  "description": "根据姓名或手机号搜索客户",
  "parameters": {
    "type": "object",
    "properties": {
      "query": { "type": "string", "description": "搜索关键词" },
      "limit": { "type": "integer", "default": 5 }
    },
    "required": ["query"]
  }
}
```

### 2.3 Agent Runtime（运行时）

Agent 执行采用 ReAct（Reasoning + Acting）模式：

```
用户消息 → AgentRuntime.run(agent, conversation, message)
  → Step 1: 构建上下文（系统提示词 + 历史消息 + 新消息）
  → Step 2: 调用 AI 模型（AiTextService.chat）
  → Step 3: 解析响应
    → 如果是文本回复 → 返回给用户
    → 如果是工具调用 → 执行工具 → 将结果加入上下文 → 回到 Step 2
  → Step 4: 记录日志、更新 Token 用量
```

---

## 3. 数据模型

### 3.1 `agents` 表

```sql
CREATE TABLE agents (
    agent_id BIGINT UNSIGNED PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(50) NOT NULL,
    avatar VARCHAR(500) NULL,
    system_prompt TEXT NOT NULL,
    description TEXT NULL,
    tools JSON NULL,
    kb_ids JSON NULL,
    feature_keys JSON NULL,           -- 映射的 AI 功能点列表 (业务层使用)
    model_config JSON NOT NULL DEFAULT '{}',
    enabled TINYINT(1) DEFAULT 1,
    is_builtin TINYINT(1) DEFAULT 0,
    metadata JSON NULL,
    version INT DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_tenant (tenant_id),
    INDEX idx_role (tenant_id, role),
    INDEX idx_enabled (tenant_id, enabled)
);
```

**model_config JSON 结构:**
```json
{
  "preferred_provider": "openai",
  "preferred_model": "gpt-4o-mini",
  "fallback_provider": "zhipu",
  "fallback_model": "glm-5.2",
  "temperature": 0.7,
  "max_tokens": 2000,
  "max_tool_calls": 5,
  "stream": true
}
```

### 3.2 `agent_tools` 表

```sql
CREATE TABLE agent_tools (
    tool_id BIGINT UNSIGNED PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NOT NULL,
    category VARCHAR(50) NULL,
    parameters_schema JSON NOT NULL,
    handler_class VARCHAR(255) NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_slug (slug),
    INDEX idx_tenant (tenant_id)
);
```

### 3.3 `agent_conversations` 表

```sql
CREATE TABLE agent_conversations (
    conversation_id BIGINT UNSIGNED PRIMARY KEY,
    agent_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    staff_id BIGINT UNSIGNED NULL,
    channel VARCHAR(20) DEFAULT 'web',
    subject VARCHAR(255) NULL,
    status VARCHAR(20) DEFAULT 'active',
    summary TEXT NULL,
    token_usage JSON NULL,
    message_count INT DEFAULT 0,
    metadata JSON NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (agent_id) REFERENCES agents(agent_id),
    INDEX idx_agent (agent_id),
    INDEX idx_tenant (tenant_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status)
);
```

### 3.4 `agent_conversation_messages` 表

```sql
CREATE TABLE agent_conversation_messages (
    message_id BIGINT UNSIGNED PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role ENUM('user', 'assistant', 'tool', 'system') NOT NULL,
    content TEXT NULL,
    tool_calls JSON NULL,
    tool_call_id VARCHAR(100) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (conversation_id) REFERENCES agent_conversations(conversation_id),
    INDEX idx_conversation (conversation_id),
    INDEX idx_created (conversation_id, created_at)
);
```

### 3.5 `agent_tool_logs` 表

```sql
CREATE TABLE agent_tool_logs (
    log_id BIGINT UNSIGNED PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    agent_id BIGINT UNSIGNED NOT NULL,
    tool_name VARCHAR(100) NOT NULL,
    input JSON NULL,
    output JSON NULL,
    duration_ms INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'success',
    error TEXT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_conversation (conversation_id),
    INDEX idx_agent (agent_id),
    INDEX idx_tool (tool_name, created_at)
);
```

---

## 4. 服务层接口

### 4.1 AgentService

```php
interface AgentServiceContract
{
    // Agent CRUD
    public function create(array $data): Agent;
    public function update(int $agentId, array $data): Agent;
    public function delete(int $agentId): void;
    public function find(int $agentId): ?Agent;
    public function listForTenant(int $tenantId): Collection;

    // 启用/禁用
    public function enable(int $agentId): void;
    public function disable(int $agentId): void;

    // 预置模板
    public function getBuiltinTemplates(): Collection;
    public function cloneFromTemplate(int $templateId, int $tenantId, array $overrides = []): Agent;

    // 模型配置
    public function updateModelConfig(int $agentId, array $modelConfig): void;
    public function getEffectiveModelConfig(int $agentId): array;

    // 工具管理
    public function attachTools(int $agentId, array $toolSlugs): void;
    public function detachTools(int $agentId, array $toolSlugs): void;
    public function getAgentTools(int $agentId): Collection;

    // 知识库管理
    public function attachKnowledgeBases(int $agentId, array $kbIds): void;
    public function detachKnowledgeBases(int $agentId, array $kbIds): void;
}
```

### 4.2 AgentRuntime

```php
interface AgentRuntimeContract
{
    /**
     * 执行 Agent 对话
     * @return AgentResponse {message, tool_calls, token_usage, finish_reason}
     */
    public function run(int $agentId, int $conversationId, string $message, array $options = []): AgentResponse;

    /**
     * 流式执行 Agent 对话 (SSE)
     */
    public function runStream(int $agentId, int $conversationId, string $message, array $options = []): Generator;

    /**
     * 继续执行（工具调用后）
     */
    public function continueWithToolResults(int $conversationId, array $toolResults): AgentResponse;

    /**
     * 获取会话上下文
     */
    public function getConversationContext(int $conversationId, int $maxMessages = 20): array;

    /**
     * 压缩会话记忆（摘要旧消息）
     */
    public function compressMemory(int $conversationId): void;
}
```

### 4.3 ToolRegistry

```php
interface ToolRegistryContract
{
    /** 注册工具 */
    public function register(string $slug, string $handlerClass, array $schema): void;
    /** 获取所有工具 */
    public function all(): Collection;
    /** 获取指定工具 */
    public function get(string $slug): ?Tool;
    /** 获取 Function Calling 格式的工具定义 */
    public function getToolDefinitions(array $slugs): array;
    /** 执行工具 */
    public function execute(string $slug, array $arguments, int $tenantId): mixed;
    /** 工具是否可用 */
    public function isAvailable(string $slug, int $tenantId): bool;
}
```

### 4.4 AgentMonitor

```php
interface AgentMonitorContract
{
    public function logConversationTurn(int $conversationId, int $agentId, array $data): void;
    public function logToolCall(int $conversationId, int $agentId, string $toolName, array $input, $output, int $durationMs, ?string $error = null): void;
    public function getTokenUsage(int $agentId, string $startDate, string $endDate): array;
    public function getPerformanceMetrics(int $agentId, string $startDate, string $endDate): array;
    public function getCostEstimate(int $agentId, string $startDate, string $endDate): float;
}
```

---

## 5. Agent 执行流程

### 5.1 ReAct 循环

```
AgentRuntime.run(agentId, conversationId, msg)
  → 加载 Agent 配置 (system_prompt, tools, kb_ids, model_config)
  → 构建消息上下文 [system_prompt] + [历史消息] + [新消息]
  → 调用 AiTextService.chat(messages, tools, model_config)
  → 响应类型判断:
      ├─ 文本回复 → 返回给用户
      └─ 工具调用 → ToolRegistry.execute() → 结果加入上下文
           → 达到最大轮次? → 是 → 强制总结返回
           → 否 → 回到 AiTextService.chat
  → AgentMonitor.log()
```

### 5.2 流式响应 (SSE)

```
用户请求 → AgentRuntime.runStream()
  → AiTextService.streamChat()
  → 每个 token 通过 SSE 推送
  → 遇到 tool_calls → 执行工具 → 结果加入上下文 → 继续流式
  → 完成时发送 [DONE]
```

### 5.3 错误处理

- AI 驱动不可用 → 自动切换 fallback_provider
- 工具执行失败 → 错误信息返回给 AI，由 AI 决定下一步
- Token 超限 → 自动压缩历史消息（摘要旧消息）
- 超时 → 返回已生成内容 + 超时提示

---

## 6. API 端点

### 6.1 Agent 管理

| 方法 | 端点 | 说明 |
|------|------|------|
| GET | `/api/v1/agents` | 获取租户所有 Agent |
| GET | `/api/v1/agents/{id}` | 获取 Agent 详情 |
| POST | `/api/v1/agents` | 创建 Agent |
| PUT | `/api/v1/agents/{id}` | 更新 Agent |
| DELETE | `/api/v1/agents/{id}` | 删除 Agent |
| POST | `/api/v1/agents/{id}/enable` | 启用 Agent |
| POST | `/api/v1/agents/{id}/disable` | 禁用 Agent |
| GET | `/api/v1/agents/templates` | 获取预置模板列表 |
| POST | `/api/v1/agents/templates/{id}/clone` | 从模板克隆 Agent |
| PUT | `/api/v1/agents/{id}/model-config` | 更新模型配置 |
| PUT | `/api/v1/agents/{id}/tools` | 更新工具配置 |
| PUT | `/api/v1/agents/{id}/knowledge-bases` | 更新知识库配置 |

### 6.2 Agent 对话

| 方法 | 端点 | 说明 |
|------|------|------|
| POST | `/api/v1/agents/{id}/chat` | 发起对话（SSE 流式） |
| POST | `/api/v1/agents/{id}/chat/{conversation_id}` | 在已有会话中发消息 |
| GET | `/api/v1/agents/{id}/conversations` | 对话列表 |
| GET | `/api/v1/conversations/{id}` | 对话详情 |
| GET | `/api/v1/conversations/{id}/messages` | 消息列表 |
| DELETE | `/api/v1/conversations/{id}` | 删除对话 |

### 6.3 Agent 监控

| 方法 | 端点 | 说明 |
|------|------|------|
| GET | `/api/v1/agents/{id}/stats` | 使用统计 |
| GET | `/api/v1/agents/{id}/token-usage` | Token 用量 |
| GET | `/api/v1/agents/{id}/cost` | 成本估算 |
| GET | `/api/v1/agents/{id}/tool-logs` | 工具调用日志 |

### 6.4 工具管理

| 方法 | 端点 | 说明 |
|------|------|------|
| GET | `/api/v1/tools` | 所有可用工具 |
| GET | `/api/v1/tools/{slug}` | 工具详情 |
| POST | `/api/v1/tools` | 注册新工具 |
| PUT | `/api/v1/tools/{slug}` | 更新工具 |
| DELETE | `/api/v1/tools/{slug}` | 删除工具 |

---

## 7. 与现有框架的集成

### 7.1 复用现有服务

| 现有服务 | 用途 |
|----------|------|
| `AiTextService` | Agent 的文本推理引擎（chat/complete/streamChat） |
| `AiImageService` | Agent 的图片生成工具 |
| `AiVideoService` | Agent 的视频生成工具 |
| `IdGenerator` | 所有表主键生成 |
| `TenantContext` | 多租户上下文隔离 |

### 7.2 服务容器绑定

```php
// 在 TenancyServiceProvider 中注册
$this->app->singleton(AgentServiceContract::class, AgentService::class);
$this->app->singleton(AgentRuntimeContract::class, AgentRuntime::class);
$this->app->singleton(ToolRegistryContract::class, ToolRegistry::class);
$this->app->singleton(AgentMonitorContract::class, AgentMonitor::class);
```

### 7.3 事件系统

```php
// Agent 事件
AgentCreated::class       // Agent 创建后
AgentEnabled::class       // Agent 启用后
AgentDisabled::class      // Agent 禁用后

// 对话事件
ConversationStarted::class   // 对话开始
ConversationEnded::class     // 对话结束
MessageReceived::class       // 收到消息
MessageSent::class           // 发送消息

// 工具事件
ToolCalled::class         // 工具被调用
ToolCallCompleted::class  // 工具调用完成
ToolCallFailed::class     // 工具调用失败
```

---

## 8. 性能要求

| 指标 | 目标 |
|------|------|
| 首次响应时间（TTFB） | < 2s |
| 流式首 Token 延迟 | < 500ms |
| 工具调用执行时间 | < 5s（单个工具） |
| 最大工具调用轮次 | 可配置（默认 5 轮） |
| 并发对话数 | 1000+ per tenant |
| 单次对话最大 Token | 可配置（默认 8000） |

---

## 9. 文件结构

```
src/
├── Contracts/
│   ├── AgentServiceContract.php
│   ├── AgentRuntimeContract.php
│   ├── ToolRegistryContract.php
│   └── AgentMonitorContract.php
├── Services/
│   └── Agent/
│       ├── AgentService.php
│       ├── AgentRuntime.php
│       ├── ToolRegistry.php
│       ├── AgentMonitor.php
│       └── MemoryCompressor.php
├── Models/
│   ├── Agent.php
│   ├── AgentTool.php
│   ├── AgentConversation.php
│   ├── AgentConversationMessage.php
│   └── AgentToolLog.php
├── Events/
│   ├── AgentCreated.php
│   ├── AgentEnabled.php
│   ├── AgentDisabled.php
│   ├── ConversationStarted.php
│   ├── ConversationEnded.php
│   ├── ToolCalled.php
│   └── ToolCallFailed.php
├── Http/
│   └── Controllers/
│       ├── AgentController.php
│       ├── AgentChatController.php
│       ├── AgentStatsController.php
│       └── ToolController.php
└── database/
    └── migrations/
        ├── xxxx_create_agents_table.php
        ├── xxxx_create_agent_tools_table.php
        ├── xxxx_create_agent_conversations_table.php
        ├── xxxx_create_agent_conversation_messages_table.php
        └── xxxx_create_agent_tool_logs_table.php
```

---

## 10. 验收标准

- [ ] 租户可创建/编辑/删除 Agent
- [ ] Agent 支持设置系统提示词、工具集、知识库
- [ ] Agent 支持模型配置（驱动选择、降级策略、温度、Token 限制）
- [ ] Agent 运行时支持 ReAct 循环（思考→行动→观察→思考）
- [ ] Agent 支持流式响应（SSE）
- [ ] ToolRegistry 支持工具注册、发现、执行
- [ ] Agent 对话支持多轮对话记忆
- [ ] Agent 支持记忆压缩（摘要旧消息）
- [ ] Agent 对话完整日志记录
- [ ] Token 用量统计和成本估算
- [ ] 预置 Agent 模板（框架提供空模板，业务层填充具体定义）
- [ ] 工具调用失败时 Agent 能自动恢复
- [ ] AI 驱动不可用时自动降级到备选驱动
- [ ] 多租户数据隔离
- [ ] 所有 ID 使用 IdGenerator 生成