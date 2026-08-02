# MCP 工具归一设计（ToolRegistry 单一权威源）

> 状态：设计定稿，待排期实施。
> 结论先行：**ToolRegistry 是工具定义的唯一权威源，MCP 降级为协议适配器（Bridge），REST 控制器保持现状不动。**

## 1. 背景与问题

系统当前存在三条"操作通道"，业务执行虽已统一在 Service 层，但**工具元数据（名称/描述/参数 Schema/注册代码）与风险管控是分裂的**：

| 通道 | 定义结构 | 执行方式 | 风险管控 |
|---|---|---|---|
| 内部工具（AgentRuntime 编排 → AgentToolExecutor 执行） | `Tool` DTO：slug / schema / **risk L1-L2** / category / handlerClass | 容器实例化 `ToolHandlerContract`，显式传 tenantId | **L2 → ActionConfirmService 确认卡片 + AuditService 审计** |
| MCP（`api/v1/mcp`，JSON-RPC 2.0） | 数组：name / schema / **Closure** | 直接执行闭包 | **无**——写操作（manage_tags / send_message 等）外部客户端可直接执行 |
| REST API 控制器 | 路由 + FormRequest | Controller → Service | RBAC 中间件 |

具体痛点：

1. **双份元数据**：同一能力（如 `list_agents`、打标签）在 ToolRegistry 与 McpToolRegistry 各写一份 schema 与描述，变更需同步两处。
2. **scrm `app/Modules/Mcp/Services/McpToolRegistry.php` 约 700 行、30+ 闭包工具**，是重复维护的主要成本载体。
3. **安全缺口（比重复更严重）**：MCP 通道完全绕过 L2 确认与审计体系。内部小助手做一个 L2 写操作要用户确认，外部 MCP 客户端做同样的事零门槛。
4. 新增工具要"写三遍"的错觉，实际是"元数据写两遍 + 无法复用风控"。

## 2. 目标架构

```
                       ┌── 内部通道：AgentRuntime 编排 → AgentToolExecutor 执行（既有 L2 确认，不变）
一份工具定义             │
ToolRegistry ──────────┼── MCP 通道：ToolRegistryMcpBridge（新增）
(slug/schema/risk       │     tools/list = ToolRegistry->all() → MCP 格式映射
 /category/handler)     │     tools/call = ToolRegistry->execute()（继承 risk 策略 + 统一审计）
                       │
                       └── REST API：保持现状（面向 UI，分页/校验/资源化职责不同，不强行统一）
```

设计铁律：

- **工具只在 ToolRegistry 注册一次**（handler 类 + 一行 register），内部 Agent 与 MCP 客户端自动同步获得。
- **MCP 侧不再允许新增闭包工具**；存量闭包按迁移计划逐个搬迁清零。
- **REST 不参与归一**。守住"控制器薄、业务全在 Service"即可；把 REST 硬套工具协议属于过度设计。

## 3. Bridge 规格

新增 `src/Modules/Ai/Mcp/ToolRegistryMcpBridge.php`（框架层）：

### 3.1 tools/list（元数据映射，纯机械转换）

| Tool DTO 字段 | MCP tool 字段 | 说明 |
|---|---|---|
| slug | name | 唯一标识 |
| description | description | 原样 |
| parametersSchema | inputSchema | 均为 JSON Schema，直传 |
| category | —（注解进 description 前缀或 `_meta`） | MCP 协议无分类概念 |
| risk | `_meta.risk`（自定义扩展） | 供客户端提示，服务端仍强制校验 |

### 3.2 tools/call（执行 + 风险策略）

```
callTool(name, args)
  → tool = ToolRegistry->get(name)          // 不存在 → JSON-RPC -32601
  → if tool.risk == L2:
        按 config('ai.mcp.l2_policy') 分派：
          'deny'（默认）  → 返回结构化拒绝："该操作需在控制台确认后执行"
          'confirm_token' → 校验请求携带的预授权令牌（复用 ActionConfirmService 签发/消费语义）
  → tenantId = TenantContext 解析（端点已有 auth:sanctum + tenant.ensure 中间件）
  → result = ToolRegistry->execute(name, args, tenantId)
  → AuditService->log('mcp_tool_call', ...)   // 无论 L1/L2 一律审计
```

要点：

- **L2 默认拒绝（fail-closed）**，放开需显式配置。这与"生产端 AI 只提案不直写"的既有哲学一致。
- 审计动作名与内部通道区分（`mcp_tool_call` vs `ai_action_execute`），排查时可辨来源。
- Bridge 不做业务逻辑，只做协议转换 + 策略拦截，预计 <200 行。

## 4. 迁移计划（三步，各自独立可交付）

### Step 1：框架落 Bridge（不动 scrm）

- 新增 ToolRegistryMcpBridge + `ai.mcp.l2_policy` 配置。
- 框架 McpServer 的 tools/list、tools/call 改为：**先查 Bridge（ToolRegistry），miss 再落既有 McpToolRegistry**——桥接期双源共存，零破坏。
- 测试：Bridge 映射正确性、L2 deny、审计落库。

### Step 2：scrm 存量闭包搬迁（可分批、迁一个删一个）

- 每个闭包工具改写为 `ToolHandlerContract` 类（多数只是把闭包体搬进 `__invoke`，本就直调 Service），在 ScrmServiceProvider 里 `ToolRegistry->register(...)`，`category='scrm'`。
- **风险定级原则**：读操作 L1；一切写操作（打标签、发消息、建活码、发券……）一律 L2。
- 每迁一个即从 McpToolRegistry 删除对应闭包条目；全部迁完后删除 700 行注册表文件。
- 副产品：内部小助手自动获得这 30+ 个 scrm 工具的调用能力（按秘书模板 tools 白名单控制暴露）。

### Step 3：清尾

- 删除框架侧 McpToolRegistry 抽象基类的双源兜底，Bridge 成为唯一路径。
- 文档与 MCP 客户端对接说明更新（tools/list 结构含 `_meta.risk`）。

## 5. 兼容与回滚

- 桥接期 tools/list 输出 = ToolRegistry 工具 ∪ 旧闭包工具（同名冲突以 ToolRegistry 为准），外部客户端无感。
- 任一步出问题，删掉 Bridge 查询优先级即回到旧行为；Step 2 按工具粒度回滚（restore 单个闭包条目）。

## 6. 验收标准

1. 新增一个工具只需：1 个 handler 类 + 1 行 register；内部对话与 MCP `tools/list` 同时可见。
2. MCP 通道调用 L2 工具默认被拒绝，audit_logs 有 `mcp_tool_call` 记录。
3. scrm McpToolRegistry.php 删除，`composer test:filter Mcp` 全绿。
4. 既有 MCP 客户端（WorkBuddy/Hermers/OpenClaw）对 L1 工具的调用行为不变。
