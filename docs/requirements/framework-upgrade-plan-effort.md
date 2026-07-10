# 框架层升级规划 (v1.2.0 → v1.3.0)

## 背景

SCRM 平台需求规划过程中，识别出以下能力不应由 SCRM 业务层实现，而应作为平台级基础设施纳入 `multi-tenant-saas` 框架层。这些能力未来会被 ERP、OMS、工单、社区等业务域复用。

**核心原则**: 框架提供能力（Capability），业务提供领域（Domain）。

---

## 一、Conversation Center（会话中心）

### 定位

会话中心是跨业务域的通用基础设施。SCRM 的客户聊天、ERP 的工单讨论、OA 的审批留言、Agent 间通信、知识库 QA 问答、社区帖子评论——本质上都是 Conversation + Message + Participant + Attachment。

### 数据模型

```
Conversation
├── id
├── tenant_id
├── channel_id          → 关联 Channel
├── type                (direct/group/channel)
├── subject
├── status              (active/closed/archived)
├── metadata
└── timestamps

Message
├── id
├── conversation_id
├── parent_id           → 支持引用回复
├── participant_id
├── type                (text/image/video/file/voice/system/event)
├── content
├── metadata
└── timestamps

Participant
├── id
├── conversation_id
├── participant_type    (user/staff/bot/channel/customer)
├── participant_id
├── role                (owner/admin/member/observer)
├── joined_at
└── left_at

Attachment
├── id
├── message_id
├── type                (image/video/file/voice)
├── url
├── filename
├── size
├── mime_type
├── duration            (仅语音/视频)
├── transcription       (AI 转写文本)
└── metadata

Reaction
├── id
├── message_id
├── participant_id
├── emoji
└── created_at

Mention
├── id
├── message_id
├── participant_id      → 被 @ 的人
└── created_at

ReadState
├── id
├── message_id
├── participant_id
├── read_at
└── created_at

Session
├── id
├── conversation_id
├── agent_id            → 处理的 Agent
├── status              (open/active/waiting/closed)
├── summary             → AI 生成的会话摘要
├── started_at
└── closed_at

ConversationTag
├── id
├── conversation_id
├── tag_id
├── source              (manual/auto)
└── created_at
```

### 核心能力

| 能力 | 说明 |
|------|------|
| Conversation CRUD | 创建/查询/关闭/归档会话 |
| Message 收发 | 同步/异步消息发送，支持富媒体 |
| Participant 管理 | 参与者加入/离开/角色变更 |
| Timeline 查询 | 按时间线查询消息流，支持分页和游标 |
| Mention 解析 | 自动识别 @提及，触发通知和 Agent 唤醒 |
| ReadState 追踪 | 消息已读/未读状态管理 |
| Session 管理 | 会话分段，跟踪一次完整的服务闭环 |
| ConversationSummary | 调用 Capability/Summarize 自动生成会话摘要 |
| ConversationTag | 调用 Capability/Classify 自动分类会话 |

### 业务域使用方式

```
SCRM:  Conversation + Customer
ERP:   Conversation + Order
OA:    Conversation + Approval
Agent: Conversation + Agent
```

---

## 二、Channel 抽象层

### 定位

Channel 是 Conversation 的数据源。企业微信、Telegram、Slack、WhatsApp 都是 Channel Provider，不属于任何业务域。

### 核心接口

```php
interface ChannelContract {
    public function onMessage(callable $handler): void;
    public function sendMessage(string $conversationId, Message $message): void;
    public function getParticipants(string $conversationId): array;
    public function getConversationInfo(string $conversationId): array;
}
```

### 第1期 Provider

| Provider | 说明 |
|----------|------|
| EnterpriseWechat | 企业微信回调、消息收发、客户联系、客户群、通讯录、会话存档 |
| WechatOfficial | 微信公众号消息、模板消息、客服消息 |
| WechatMiniProgram | 微信小程序消息 |

### 第2期 Provider（预留接口）

| Provider | 说明 |
|----------|------|
| Telegram | Telegram Bot API |
| Slack | Slack Events API |
| Discord | Discord Gateway |
| WhatsApp | WhatsApp Business API |
| DingTalk | 钉钉机器人 |
| Feishu | 飞书机器人 |

### 消息路由

所有 Channel 收到的消息统一转换为框架 `Message` 对象，通过 `EventBus` 分发给订阅者。SCRM 订阅 `MessageReceived` 事件处理业务逻辑。

---

## 三、Workflow 引擎

### 定位

Agent → Workflow → Tool 三层调用架构。Agent 不直接调用 Tool，而是通过 Workflow 编排多步业务流程。Workflow 引擎是跨业务域的通用编排能力。

### 数据模型

```
Workflow
├── id
├── tenant_id
├── name
├── description
├── triggers            → 触发条件
├── nodes               → 步骤节点
├── status
└── timestamps

WorkflowNode
├── id
├── workflow_id
├── type                (action/condition/wait/confirm)
├── config              → 节点配置（Tool 调用、条件表达式、等待时长）
├── next_node_id        → 下一节点
├── position
└── metadata

WorkflowExecution
├── id
├── workflow_id
├── agent_id
├── input_data
├── current_node_id
├── status              (running/waiting_confirmation/completed/failed)
├── result
├── started_at
└── completed_at
```

### 核心能力

| 能力 | 说明 |
|------|------|
| 节点类型 | action（执行 Tool）、condition（条件分支）、wait（等待确认）、delay（延时） |
| 确认节点 | 暂停执行等待用户确认，确认后继续 |
| 分支逻辑 | 基于条件表达式的分支判断 |
| 并行执行 | 多个 action 节点并行执行 |
| 重试机制 | 失败节点自动重试，可配置重试次数和退避策略 |
| 执行记录 | 完整记录每次 Workflow 执行的节点状态和结果 |

### SCRM 使用示例

```
SalesWorkflow (客户成交分析):
  Node 1: action → get_customer_profile
  Node 2: action → analyze_intent
  Node 3: action → suggest_tags
  Node 4: action → recommend_script
  Node 5: confirm → 等待用户确认
  Node 6: action → create_follow_up
```

---

## 四、AI Capability 中台

### 定位

将 AI 原子能力从 Agent 中解耦，形成可复用的 Capability 中台。Agent 不直接调 LLM，而是调 Capability。Capability 可以独立测试、独立调优、独立计费。

### Capability 列表

| Capability | 功能 | 输入 | 输出 |
|-----------|------|------|------|
| Summarize | 文本摘要 | 原始文本 | 摘要文本 |
| Tag | 智能标签推荐 | 文本 + 上下文 | 标签列表 |
| Translate | 多语言翻译 | 文本 + 目标语言 | 翻译文本 |
| Intent | 意图识别 | 文本 | 意图分类 + 置信度 |
| Sentiment | 情感分析 | 文本 | 情感类别 + 分数 |
| Extract | 实体提取 | 文本 | 实体列表（人名/手机号/地址/产品名） |
| Classify | 文本分类 | 文本 + 分类体系 | 分类结果 |
| Rewrite | 文案改写 | 文本 + 风格要求 | 改写文本 |
| Generate | 内容生成 | 提示词 + 格式要求 | 生成内容 |
| Search | 语义检索 | 查询 + 知识库 ID | 检索结果 |
| OCR | 图片文字识别 | 图片 | 识别文本 |
| Vision | 图片理解 | 图片 + 问题 | 理解结果 |
| Embedding | 文本向量化 | 文本 | 向量 |

### 核心接口

```php
interface CapabilityContract {
    public function execute(string $capability, array $input, array $options = []): CapabilityResult;
}

class CapabilityResult {
    public string $capability;
    public array $output;
    public float $confidence;
    public array $tokenUsage;
    public float $durationMs;
}
```

### 计费模型

每个 Capability 独立统计调用次数和 Token 消耗，支持按 Capability 维度核算 AI 成本。

---

## 五、Memory 系统扩展

### 定位

框架已有 ConversationMemory（会话上下文）和 SummaryMemory（历史摘要）。新增 TenantMemory 和 EntityMemory，形成完整的 Memory 分层。

### Memory 分层

| Memory 类型 | 说明 | 生命周期 |
|------------|------|----------|
| ConversationMemory | 当前会话上下文窗口 | 会话期间 |
| SummaryMemory | 历史会话摘要 | 长期 |
| TenantMemory | 租户级偏好、业务规则、历史决策 | 租户生命周期 |
| EntityMemory | 通用实体记忆（SCRM 的 CustomerMemory 是其子类） | 实体生命周期 |

### EntityMemory 使用方式

```
SCRM:   EntityMemory(type=customer, id=123) → 客户历史互动摘要、偏好、敏感点
ERP:    EntityMemory(type=supplier, id=456) → 供应商历史报价、质量记录
```

### 核心能力

| 能力 | 说明 |
|------|------|
| 记忆写入 | Agent 执行后自动写入关键信息到 EntityMemory |
| 记忆检索 | Agent 执行前自动检索相关 EntityMemory 注入上下文 |
| 记忆压缩 | 定期将 ConversationMemory 压缩为 SummaryMemory |
| 衰减策略 | 旧记忆权重衰减，支持时间衰减和重要性衰减 |

---

## 六、Framework Tool 体系

### 定位

框架层提供基础设施 Tool，不绑定任何业务域。SCRM Tool 和未来的 ERP Tool 继承框架 Tool 分类体系。

### Framework Tool 列表

| 分类 | Tool | 功能 |
|------|------|------|
| Core | `llm_call` | 调用 LLM 模型 |
| Core | `http_request` | 发送 HTTP 请求 |
| Core | `webhook_trigger` | 触发 Webhook |
| Core | `email_send` | 发送邮件 |
| Storage | `file_read` | 读取文件 |
| Storage | `file_write` | 写入文件 |
| Storage | `cache_get` | 读取缓存 |
| Storage | `cache_set` | 写入缓存 |
| AI | `ocr_recognize` | OCR 图片识别 |
| AI | `vector_search` | 向量检索 |
| AI | `embedding_generate` | 生成文本向量 |
| KB | `knowledge_search` | 知识库检索 |
| KB | `document_parse` | 文档解析 |

### Tool 分类体系

| 分类 | 说明 | 归属 |
|------|------|------|
| Core | 通用基础设施 Tool | 框架 |
| AI | AI 能力 Tool | 框架 |
| Storage | 存储相关 Tool | 框架 |
| Knowledge | 知识库 Tool | 框架 |
| Customer | 客户管理 Tool | SCRM |
| Campaign | 营销活动 Tool | SCRM |
| Content | 内容创作 Tool | SCRM |
| Report | 数据报表 Tool | SCRM |
| Channel | 渠道通信 Tool | 框架 |
| Workflow | 工作流控制 Tool | 框架 |

---

## 七、Agent → Workflow → Tool 架构升级

### 当前架构

```
Agent
  ├── Tool1
  ├── Tool2
  ├── Tool3
  └── ...
```

### 目标架构

```
Agent
  └── Workflow
        ├── Tool1
        ├── Tool2
        └── Tool3
```

### 升级要点

| 变更 | 说明 |
|------|------|
| Agent.tools 改为 Agent.workflows | Agent 不再直接关联 Tool，而是关联 Workflow |
| ToolRegistry 支持分类 | 按 Core/AI/Storage/Knowledge/Customer/... 分类注册 |
| WorkflowRegistry | 新增 Workflow 注册和发现机制 |
| 确认节点 | Workflow 支持 confirm 节点暂停执行等待用户确认 |
| 执行追踪 | 每次 Workflow 执行记录完整调用链路 |

### 兼容性

- 单步 Tool 调用在 Workflow 中表现为只有一个 action 节点的 Workflow
- 现有 Agent 的 Tool 绑定可自动迁移为单步骤 Workflow

---

## 八、第1期实施范围

**新增模块**: Conversation Center, Channel Providers, Workflow Engine, AI Capability Registry, Memory System, Framework Tools

**架构升级**: Agent → Workflow → Tool 三层架构, Tool 分类体系

**Provider 实现**: EnterpriseWechat, WechatOfficial, WechatMiniProgram

**关键依赖**: 所有新增模块独立于现有 Agent Framework，不阻塞 SCRM 开发

---

## 九、任务拆分建议

**Phase 1: Conversation Center + Channel**
- Conversation 数据模型 (8 张表)
- ChannelContract 接口 + Provider 注册机制
- EnterpriseWechat Provider
- WechatOfficial/WechatMiniProgram Provider
- 消息路由 (Channel → EventBus → Message)

**Phase 2: Workflow Engine + Capability Registry**
- Workflow 数据模型 (3 张表)
- Workflow 执行引擎 (action/condition/wait/confirm/delay 节点)
- Capability 注册与执行
- 10+ 个 Capability 实现

**Phase 3: Agent 架构升级 + Memory + Framework Tools**
- Agent.tools → Agent.workflows 迁移
- ToolRegistry 分类体系
- Memory System 扩展 (TenantMemory, EntityMemory)
- Framework Tools 实现 (llm_call, http_request, email_send, file_read, cache_get, ocr_recognize, vector_search, knowledge_search 等)

---

## 十、技术决策

1. **Conversation**: 独立模块，通过 `EventHandler` 与 Channel 解耦，业务域通过 EventBus 订阅消息
2. **Channel**: Provider 模式，每个平台独立实现 `ChannelContract`，通过 `ChannelManager` 统一管理
3. **Workflow**: 状态机 + 节点编排，支持 JSON 定义和可视化编排
4. **Capability**: 注册表模式，每个 Capability 独立实现，支持动态注册和发现
5. **Memory**: 分层存储，ConversationMemory 用 Redis（热数据），EntityMemory 用 MySQL（冷数据），定期压缩
6. **Tool**: 两级分类（框架/业务），框架 Tool 随框架发布，业务 Tool 由业务域注册
7. **Agent 升级**: 向后兼容，现有 Agent 的 Tool 绑定自动转为单步骤 Workflow