# AI 小助手代操作方案（指路 → 代办）实施计划

> 状态：已评审通过，**阶段一已实施**（suggest_form_fill 激活）；阶段二部分实施（L2 确认令牌基建 + 流式链路 L2 拦截已就位）
> 关联：`docs/ai-usage-architecture.md`、`src/Modules/Ai/`
> 原则：不引入新协议、不新增身份（AI 以当前 Operator 身份行事）、每个写动作有人类确认点 + 审计留痕

## 1. 目标

小助手从「告诉你去哪里、怎么做」升级为「征得确认后直接帮你做」。
例：用户说"帮我配置企业微信登录，AppID 是 xxx"，小助手产出配置摘要卡片 → 用户点确认 → 服务端执行保存 → 回报结果。

## 2. 技术路线决策（已定）

| 路线 | 结论 |
|------|------|
| **A. Agent 工具（服务端 Function Calling）** | ✅ 采用。复用 AgentRuntime（编排）+ AgentToolExecutor（工具执行）+ ToolRegistry，工具 handler 委托既有 Service |
| B. MCP 协议 | ❌ 不走。MCP 定位是外部 AI 客户端接入面；小助手在服务端进程内，绕道 MCP 徒增 HTTP + 鉴权层。但 scrm MCP 工具背后的 Service 层逻辑可复用 |
| C. Skill / 前端代点 | ❌ 否决。UI 一改就断、无法审计、绕过后端校验 |

## 3. 安全模型：三级操作分级

工具注册时声明 `risk` 属性（L1/L2/L3）：

| 级别 | 范围 | 策略 |
|------|------|------|
| **L1 读** | 查数据、搜索知识库、导航、查字典 | 直接执行（现状） |
| **L2 低风险写** | 保存配置、打标签、启用数字员工、创建草稿 | **先建议后执行**：确认令牌机制（见 §4） |
| **L3 高风险写** | 删除、群发、支付、批量操作 | 不提供工具。小助手仅 navigate 带路，由人在页面操作 |

## 4. 确认令牌机制（L2 核心，防"AI 自说自话"）

```
用户请求 → LLM 调用 L2 工具
         → 服务端【不执行】，签发一次性 confirm_token
           （含：工具 slug + 参数哈希 + 会话 ID + 过期时间，存 cache，TTL 5 分钟）
         → SSE 下发 pending_confirmation 事件（含参数摘要 + token）
         → 前端渲染确认卡片（复用 FormFillCard 交互范式）
用户点确认 → 前端携 token 调 POST /api/v1/assistant/confirm-action
         → 服务端校验：token 有效 + 参数哈希一致 + 会话归属当前 Operator
         → 通过 ToolRegistry.execute() 真正执行 handler
         → 结果写审计日志 + 回传会话（role=tool 消息），LLM 续答
```

关键点：
- 确认凭证在服务端闭环，LLM 无法伪造或跳过
- token 一次性消费，参数哈希绑定，确认的就是看到的
- 用户点"取消"→ token 作废，以 role=tool 回传"用户已取消"，LLM 正常续答

## 5. 权限与审计切面

- **权限**：L2 工具执行前校验当前 Operator 在本租户的 RBAC 权限（复用 `CheckPermission` 同款 operator_tenants + roles 查询）。AI 的权限 ≡ 当前登录者权限，绝不提权
- **审计**：每次 L2 执行写 `audit_logs`：operator_id、tenant_id、conversation_id、工具 slug、参数、结果、耗时
- **架构铁律**：handler 一律委托既有 Service（写操作必经既定 Service），禁止在 handler 里直接写库

## 6. 分层归属（框架 vs scrm）

| 内容 | 归属 |
|------|------|
| Tool DTO 加 `risk` 属性、ToolRegistry 分级执行、确认令牌服务、confirm-action 端点、SSE `pending_confirmation` 事件、前端确认卡片、审计切面 | **框架** `src/Modules/Ai/` |
| 具体 L2 业务工具（save_oauth_config、客户打标签等），经 ToolRegistry->register() 注册 | **scrm** `app/Modules/<X>/`，handler 委托各业务 Service |

## 7. 实施阶段

### 阶段一：零新工具，激活 suggest_form_fill（1 天内）

- 秘书模板 `tools` 数组加入 `suggest_form_fill`（`BuiltinAgentTemplates.php`），提示词补充使用时机
- 前后端通路已就绪（AssistantController extractFormFill → FormFillCard → useAiFormFill），纯配置激活
- 效果：AI 建议填表 + 用户确认 + 字段级撤销 + AI 标注，立即可用
- 发布：`secretary:install --sync-prompt`

### 阶段二：确认令牌基建 + 首批 L2 试点（核心）——部分实施

框架侧（已实施）：
1. `Tool` DTO / `agent_tools` 表加 `risk` 字段（默认 L1，向后兼容）✅
2. `ActionConfirmService`：签发/校验/消费 confirm_token（cache 存储）✅
3. `AgentRuntime` 流式循环：AgentToolExecutor 遇 L2 工具 → 不执行，emit `pending_confirmation`，本轮结束 ✅
4. `AssistantController` 新增 `POST confirm-action`：校验 token → execute → 审计 → 结果入会话 ✅
5. 前端 `ChatMessage` 新增 ActionConfirmCard（参数摘要 + 确认/取消）✅
6. 权限切面：execute 前 RBAC 校验；审计写入 ✅
7. Node 流式链路 L2 拦截：`ToolExecuteController` 遇 L2 工具 → 签发令牌 → 返回 `pending_confirmation` 载荷 ✅

scrm 侧试点（待实施）：
- `save_oauth_config`：保存 OAuth 平台配置（委托 Auth 模块 Service）
- `tag_customer`：给客户打标签（委托 TagService）
- `create_script_draft`：创建话术草稿（委托 ScriptService）

### 阶段三：多步编排（视阶段二效果排期）

- 秘书加 `suggest_workflow`，激活 WorkflowProgress 组件
- 步骤级执行状态回传（当前仅支持整体提交），每个写步骤走 L2 确认

## 8. 测试计划

- 框架：`tests/` 新增 ActionConfirmServiceTest（签发/过期/哈希不符/重放）、AgentToolExecutor L2 拦截测试；跑 `composer test:filter "Secretary|ActionConfirm|Agent"`
- scrm：每个 L2 工具 handler 单测（委托 Service 断言 + 越权拒绝）
- E2E：生产浏览器验证确认卡片全流程（确认执行 / 取消续答 / token 过期）

## 9. 明确不做

- 不给 AI 独立身份或独立 token（永远用当前登录者身份）
- 不提供 L3 破坏性工具
- 不做前端 DOM 代点/脚本模拟操作
- 不在小助手内部走 MCP 协议
