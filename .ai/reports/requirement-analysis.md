我先探索一下现有代码库，了解技术栈和已有基础设施。
让我深入了解现有的 AI 服务、契约层和路由结构，以理解技术约定。
需求文档提到了 `AiTextService` 等服务，但 glob 没找到。让我确认这些 AI 基础设施是否已存在。
发现已有需求分析报告和相关 TASK 文件。让我读取它们以了解现状，避免重复工作。
已有 TASK-033~038。让我读取这些已有 Agent Framework 任务和 AiTextService 任务，以确认当前规划状态。
最后确认 Sprint-agent 目录内容、服务绑定惯例和路由结构，以保证分析精准。
已掌握代码库现状。基于实际核查（而非文档声称），输出需求分析如下。

> 核查结论：需求文档第 7.1 节声称"复用 `AiTextService` 等现有服务"，但 `src/` 中**不存在**这些服务——`TASK-011(AiTextService)` 计划在 sprint-003，状态 READY 未实现，且**明确标注"Function Calling 暂不实现"**。这是整个 Agent Framework 的硬前置缺口。

---

## 需求理解

在多租户 SaaS 框架层构建一套可配置的 AI 智能体（数字员工）基础设施：Agent 具备角色定义/工具集/知识库/模型配置/对话记忆，通过 ReAct 循环 + 流式 SSE 对外提供对话能力，复用现有 `IdGenerator`/`TenantContext`/`BelongsToTenant`，为上层 SCRM 等 51 个 AI 功能点统一收口。验收共 15 项。

---

## 功能拆解

> 标注「✅TASK-0XX 已有」/「❌缺失」/「🔧需扩写」。P0=必须 P1=重要 P2=增强。每个 Task ≤4h、1-5 文件。

### P0 — 基础设施与核心运行时

1. **数据库迁移** — 5 张表（agents/agent_tools/agent_conversations/agent_conversation_messages/agent_tool_logs） — ✅TASK-033（可直接用，须补 IdGenerator 16位主键规范）
2. **数据模型** — 5 个 Eloquent Model + `HasGlobalId`+`BelongsToTenant`+`$casts=['json']`+关联 — ✅TASK-034
3. **接口契约** — 4 个 Contract — ✅TASK-035（须补 `AgentResponse` DTO 定义，契约已引用但无）
4. **事件系统** — 7 个 Event 类 — ✅TASK-037
5. **AI 推理引擎 + Function Calling** — `AiTextServiceContract`+实现（chat/streamChat/**FC**/降级） — ❌**缺失且为关键路径**；TASK-011 仅做补全/嵌入/流式且**明确不做 FC**，须扩写或新建 FC Task
6. **ToolHandler 抽象** — `Tool` 值对象 + `ToolHandlerContract` + 1-2 示例工具（echo/search） — ❌缺失，是 `ToolRegistry.execute` 前提
7. **AgentService 实现** — CRUD/启用禁用/模型配置/工具&KB attach·detach/模板克隆 — 🔧TASK-036 含但须**拆出独立**
8. **ToolRegistry 实现** — register/all/get/`getToolDefinitions`(FC格式)/execute/isAvailable — 🔧TASK-036 含但须**拆出**
9. **AgentRuntime 非流式 ReAct** — run/continueWithToolResults/getConversationContext + ReAct 循环 + max_tool_calls 兜底 — 🔧TASK-036 含但须**拆出**

### P1 — 流式、记忆、监控、降级、API 出口

10. **AgentRuntime 流式 SSE** — `runStream` Generator + 流式中途遇 tool_calls 暂停→执行→续流 + `[DONE]` — ❌缺失，状态机最复杂，独立
11. **MemoryCompressor** — 旧消息摘要、token 超限自动触发 — 🔧TASK-036 含但须**拆出**
12. **AgentMonitor 实现** — logConversationTurn/logToolCall/getTokenUsage/getPerformanceMetrics/getCostEstimate — 🔧TASK-036 含但须**拆出**
13. **错误处理与降级** — fallback_provider 切换/工具失败回传 AI/超时/压缩触发 — ❌缺失，独立
14. **服务容器绑定** — `TenancyServiceProvider::register()` 注册 4 服务（沿用 `singleton(Contract,Impl)`+`alias` 惯例） — 🔧TASK-038 含
15. **Agent 管理 API** — `AgentController`+路由（CRUD/enable/disable/templates/clone/model-config/tools/kb） — 🔧TASK-038 含但须**拆出**
16. **Agent 对话 API(非SSE)** — `AgentChatController`+路由（chat/chat/{cid}/conversations/messages/delete） — 🔧TASK-038 含但须**拆出**

### P2 — 增强

17. **Agent 对话流式 API(SSE)** — `chat` 端点接 `runStream` — ❌缺失
18. **监控统计 API** — `AgentStatsController`（stats/token-usage/cost/tool-logs） — 🔧TASK-038 含但须**拆出**
19. **工具管理 API** — `ToolController`（tools CRUD） — 🔧TASK-038 含但须**拆出**
20. **预置 Agent 模板** — `is_builtin=1` 空骨架 Seeder + 克隆机制（框架只供空模板，业务层填角色） — ❌缺失
21. **测试** — Service 单测（ReAct/工具/降级/压缩）+ Controller 集成测试 — ❌缺失

---

## 技术约束

1. **🔴 AiTextService 缺失 + FC 缺失** — Runtime 的 ReAct/流式/工具调用全部依赖它；TASK-011 明确不做 FC，须先补 `AiTextServiceContract`（含 `chat(messages, tools, model_config)` + `streamChat` + FC 响应解析）。
2. **🔴 控制器命名空间错位** — 现有控制器在 `app/Http/Controllers/Api/`（`App\` 命名空间），而 agent-framework.md 第 9 节指定 `src/Http/Controllers/`（`MultiTenantSaas\`）。须定边界：框架层放 `src/` 还是应用层 `app/`，影响路由 use 与服务发布。
3. **ID 规范** — 主键必须 `IdGenerator`（16位随机数字、JS安全）+ `HasGlobalId` trait。
4. **多租户隔离** — `TenantContext`+`BelongsToTenant`+`TenantScope` 全局作用域，所有查询带 `tenant_id`。
5. **服务绑定惯例** — `src/TenancyServiceProvider.php::register()` 用 `$this->app->singleton(Contract::class, fn()=>new Impl())` + `$this->app->alias(...)`（见 `IdGeneratorContract` 绑定）。
6. **路由惯例** — 注册到 `routes/api.php`，挂 `['auth:sanctum','throttle:api']`+`prefix('v1')`，权限用 `rbac.permission:xxx` 中间件（须新增 `agent.*`/`tool.*` 权限 key）。
7. **JSON 字段** — tools/kb_ids/feature_keys/model_config/parameters_schema/token_usage/tool_calls/input/output/metadata 用 `$casts=['xxx'=>'json']`。
8. **SSE** — Laravel `StreamedResponse`+Generator；流式中途处理 tool_calls 需暂停→执行→续流，状态机复杂。
9. **FC 跨驱动一致性** — preferred/fallback 驱动切换时工具定义 schema 可能不兼容，须在 AiTextService 层统一抽象。
10. **TASK-036/038 违反 4h 原则** — 036 把 5 服务塞 1 Task、038 把 4 控制器+路由+绑定塞 1 Task，**必须拆分**。

---

## 风险点

| 风险 | 影响 | 应对 |
|------|------|------|
| 🔴 AiTextService+FC 缺失 | Runtime 无法实现，是 #5/#9/#10 的硬前置 | 立即确认来源；先建 `AiTextServiceContract`+实现（含 FC）为前置 Task；Runtime 先用接口 mock 驱动开发 |
| 🔴 控制器位置错位 | 框架层 `src/` vs 应用层 `app/` 不一致，导致路由/发布/测试样板错乱 | Sprint 启动前定边界并写入 TASK 范围 |
| TASK-036/038 过大 | 单 Task 超 4h，Loop 易超时/escalate | 036 拆为 #7/#8/#9/#10/#11/#12 六个；038 拆为 #14/#15/#16/#18/#19 |
| 流式 SSE+工具调用交替 | 中途遇 tool_calls 需中断流式、执行、续流，状态机最复杂 | 独立 #10/#17；先交付非流式 ReAct(#9) 打通主链路再叠加流式 |
| FC 跨驱动不一致 | 降级时工具格式不兼容 | AiTextService 统一抽象 FC 请求/响应 |
| token 超限压缩 vs 连贯性 | 摘要丢关键上下文 | 阈值/保留最近 N 轮可配置，#11 独立 P1 |
| 并发 1000+/租户 | 同步 SSE 占 worker | v1.0 先保正确；高并发列性能债，sprint-008 负载测试验证 |
| 预置模板边界 | 需求"框架供空模板，业务层填充" | #20 仅 `is_builtin=1` 空骨架 Seeder+克隆，不内置角色 prompt |

---

## 建议的 Sprint 划分

现有 sprint-001~008 已排满（008=v1.0 生产就绪）。Agent Framework 作为**独立 `sprint-agent`**，按依赖分 3 个 Wave 串行（Wave 内可并行）。`.ai/state.json` 当前 tasks 为空、`sprint-agent/` 目录为空，尚未启动，可干净规划。

### Wave A — 基础设施层（数据底座 + 契约 + 推理引擎就绪）
- TASK-033 迁移 → TASK-034 模型 → {TASK-035 契约(补 AgentResponse DTO) ‖ TASK-037 事件 ‖ **新建: ToolHandler 抽象** ‖ **新建: AiTextService 契约+实现(含FC)**}
- **门控**：AiTextService 跑通一次带 FC 的 chat/streamChat；`php artisan migrate` 通过

### Wave B — 运行时核心（Agent 跑通一次完整对话）
- {新建: AgentService ‖ 新建: ToolRegistry} → 新建: AgentRuntime 非流式 ReAct → {新建: MemoryCompressor ‖ 新建: AgentMonitor ‖ 新建: 错误处理与降级}
- **门控**：`AgentRuntime::run()` 端到端单测通过一次多轮工具调用对话

### Wave C — API 与增强层（可对外提供服务）
- {新建: 服务容器绑定 ‖ 新建: Agent 管理 API ‖ 新建: Agent 对话 API(非SSE)} → 新建: AgentRuntime 流式 SSE + 对话流式 API → {新建: 监控统计 API ‖ 新建: 工具管理 API ‖ 新建: 预置模板 Seeder ‖ 新建: 测试}
- **门控**：验收标准 15 项全部 ✅

> **关键路径**：AiTextService(含FC) 是 Wave A 隐藏关键节点，必须最早确认/实现，否则 Wave B 全部阻塞。**前置动作**：先解决控制器命名空间边界（`src/` vs `app/`）并据此修订 TASK-038 范围。

---

需要我基于此分析**修订现有 TASK-033~038**（拆分 036/038、补 AgentResponse DTO、ToolHandler 抽象、AiTextService+FC、控制器边界），并写入 `.ai/sprints/sprint-agent/sprint-agent.md` 吗？