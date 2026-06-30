# 框架层升级规划 — 任务拆分 (sprint-20260630)

> **Sprint ID**: sprint-20260630
> **版本**: v1.2.0 → v1.3.0
> **日期**: 2026-06-30

---

## Phase 1: Conversation Center + Channel

### TASK-001: Conversation 数据模型 — conversations 表

**优先级**: P0 | **依赖**: 无

**内容**:
- migration: `database/migrations/2026_06_30_000001_create_conversations_table.php`
- Model: `src/Models/Conversation.php`
- 字段: `conversation_id`(BigInt PK), `tenant_id`, `channel_id`(nullable), `type`(enum: direct/group/channel), `subject`(nullable), `status`(enum: active/closed/archived, default active), `metadata`(JSON nullable), `timestamps`
- 索引: `idx_tenant`(tenant_id), `idx_channel`(channel_id), `idx_status`(tenant_id, status), `idx_type`(tenant_id, type)
- 使用 `BelongsToTenant`, `HasGlobalId` traits
- Enum: `src/Enums/ConversationType.php`, `src/Enums/ConversationStatus.php`

**验收**: migration 可执行，Model 可 CRUD，TenantScope 生效

---

### TASK-002: Message 数据模型 — messages 表

**优先级**: P0 | **依赖**: TASK-001

**内容**:
- migration: `database/migrations/2026_06_30_000002_create_messages_table.php`
- Model: `src/Models/Message.php`
- 字段: `message_id`(BigInt PK), `conversation_id`(FK→conversations), `parent_id`(nullable, self-ref), `participant_id`(nullable), `type`(enum: text/image/video/file/voice/system/event), `content`(text nullable), `metadata`(JSON nullable), `timestamps`
- 索引: `idx_conversation`(conversation_id), `idx_conversation_created`(conversation_id, created_at), `idx_parent`(parent_id), `idx_type`(type)
- Enum: `src/Enums/MessageType.php`
- 关联: `belongsTo(Conversation)`, `hasMany(Attachment)`, `hasMany(Reaction)`, `hasMany(Mention)`, `belongsTo(Message, 'parent_id')`

**验收**: migration 可执行，Model 关联正确

---

### TASK-003: Participant 数据模型 — conversation_participants 表

**优先级**: P0 | **依赖**: TASK-001

**内容**:
- migration: `database/migrations/2026_06_30_000003_create_conversation_participants_table.php`
- Model: `src/Models/ConversationParticipant.php`
- 字段: `participant_id`(BigInt PK), `conversation_id`(FK→conversations), `participant_type`(enum: user/staff/bot/channel/customer), `participant_ref_id`(BigInt, 关联实际实体), `role`(enum: owner/admin/member/observer), `joined_at`(timestamp), `left_at`(timestamp nullable)
- 索引: `idx_conversation`(conversation_id), `idx_participant`(participant_type, participant_ref_id), `idx_role`(role)
- Enum: `src/Enums/ParticipantType.php`, `src/Enums/ParticipantRole.php`
- 唯一约束: `uk_conversation_participant`(conversation_id, participant_type, participant_ref_id)

**验收**: migration 可执行，唯一约束生效

---

### TASK-004: Attachment 数据模型 — attachments 表

**优先级**: P1 | **依赖**: TASK-002

**内容**:
- migration: `database/migrations/2026_06_30_000004_create_attachments_table.php`
- Model: `src/Models/Attachment.php`
- 字段: `attachment_id`(BigInt PK), `message_id`(FK→messages), `type`(enum: image/video/file/voice), `url`(string), `filename`(string nullable), `size`(integer nullable), `mime_type`(string nullable), `duration`(integer nullable, 秒), `transcription`(text nullable), `metadata`(JSON nullable), `timestamps`
- 索引: `idx_message`(message_id), `idx_type`(type)

**验收**: migration 可执行

---

### TASK-005: Reaction + Mention + ReadState 数据模型

**优先级**: P1 | **依赖**: TASK-002, TASK-003

**内容**:
- migration: `database/migrations/2026_06_30_000005_create_reactions_table.php`
  - 字段: `reaction_id`(BigInt PK), `message_id`(FK), `participant_id`(BigInt), `emoji`(string), `created_at`
  - 唯一约束: `uk_message_participant_emoji`(message_id, participant_id, emoji)
- migration: `database/migrations/2026_06_30_000006_create_mentions_table.php`
  - 字段: `mention_id`(BigInt PK), `message_id`(FK), `participant_id`(BigInt), `created_at`
  - 索引: `idx_message`(message_id), `idx_participant`(participant_id)
- migration: `database/migrations/2026_06_30_000007_create_read_states_table.php`
  - 字段: `read_state_id`(BigInt PK), `message_id`(FK), `participant_id`(BigInt), `read_at`(timestamp), `created_at`
  - 唯一约束: `uk_message_participant`(message_id, participant_id)
- Models: `src/Models/Reaction.php`, `src/Models/Mention.php`, `src/Models/ReadState.php`

**验收**: 3 张表 migration 可执行，唯一约束生效

---

### TASK-006: Session 数据模型 — conversation_sessions 表

**优先级**: P1 | **依赖**: TASK-001

**内容**:
- migration: `database/migrations/2026_06_30_000008_create_conversation_sessions_table.php`
- Model: `src/Models/ConversationSession.php`
- 字段: `session_id`(BigInt PK), `conversation_id`(FK→conversations), `agent_id`(BigInt nullable), `status`(enum: open/active/waiting/closed), `summary`(text nullable), `started_at`(timestamp), `closed_at`(timestamp nullable)
- 索引: `idx_conversation`(conversation_id), `idx_agent`(agent_id), `idx_status`(status)
- Enum: `src/Enums/SessionStatus.php`

**验收**: migration 可执行

---

### TASK-007: ConversationTag 数据模型 — conversation_tags 表

**优先级**: P2 | **依赖**: TASK-001

**内容**:
- migration: `database/migrations/2026_06_30_000009_create_conversation_tags_table.php`
- Model: `src/Models/ConversationTag.php`
- 字段: `conversation_tag_id`(BigInt PK), `conversation_id`(FK→conversations), `tag_id`(BigInt), `source`(enum: manual/auto), `created_at`
- 索引: `idx_conversation`(conversation_id), `idx_tag`(tag_id), `idx_source`(source)
- Enum: `src/Enums/TagSource.php`

**验收**: migration 可执行

---

### TASK-008: ConversationService — 会话 CRUD + Participant 管理

**优先级**: P0 | **依赖**: TASK-001 ~ TASK-003

**内容**:
- Contract: `src/Contracts/ConversationServiceContract.php`
- Service: `src/Services/Conversation/ConversationService.php`
- 方法:
  - `create(array $data): Conversation` — 创建会话，自动添加创建者为 owner
  - `find(int $id): ?Conversation` — 查找会话（租户隔离）
  - `list(int $tenantId, array $filters): LengthAwarePaginator` — 分页列表，支持 status/type/channel_id 过滤
  - `close(int $id): Conversation` — 关闭会话
  - `archive(int $id): Conversation` — 归档会话
  - `addParticipant(int $conversationId, array $data): ConversationParticipant` — 添加参与者
  - `removeParticipant(int $conversationId, int $participantId): void` — 移除参与者（设 left_at）
  - `updateParticipantRole(int $conversationId, int $participantId, string $role): void` — 角色变更
  - `getParticipants(int $conversationId): Collection` — 获取参与者列表
- 在 `TenancyServiceProvider` 注册绑定
- 所有操作强制 tenant_id 来自 `TenantContextContract`

**验收**: 单元测试覆盖 CRUD + Participant 管理

---

### TASK-009: MessageService — 消息收发 + Timeline 查询

**优先级**: P0 | **依赖**: TASK-002, TASK-004, TASK-005, TASK-008

**内容**:
- Contract: `src/Contracts/MessageServiceContract.php`
- Service: `src/Services/Conversation/MessageService.php`
- 方法:
  - `send(int $conversationId, array $data): Message` — 发送消息（支持富媒体 type）
  - `sendWithAttachments(int $conversationId, array $data, array $attachments): Message` — 发送含附件消息
  - `getTimeline(int $conversationId, array $options): LengthAwarePaginator` — 时间线查询，支持 cursor 分页
  - `getMessagesBefore(int $conversationId, int $cursor, int $limit): Collection` — 游标向前
  - `getMessagesAfter(int $conversationId, int $cursor, int $limit): Collection` — 游标向后
  - `addReaction(int $messageId, int $participantId, string $emoji): Reaction` — 添加 Reaction
  - `removeReaction(int $messageId, int $participantId, string $emoji): void` — 移除 Reaction
  - `parseMentions(int $messageId, array $participantIds): Collection` — 解析 @提及
  - `markAsRead(int $messageId, int $participantId): ReadState` — 标记已读
  - `getUnreadCount(int $conversationId, int $participantId): int` — 未读计数
- 事件: `MessageSent`, `MessageReceived`, `MentionDetected` (新增到 `src/Events/`)
- Mention 解析触发 EventBus 发布 `MentionDetected` 事件

**验收**: 单元测试覆盖消息收发 + Timeline + ReadState

---

### TASK-010: SessionService — Session 管理 + Summary + Tag

**优先级**: P1 | **依赖**: TASK-006, TASK-007, TASK-009

**内容**:
- Service: `src/Services/Conversation/SessionService.php`
- 方法:
  - `openSession(int $conversationId, ?int $agentId): ConversationSession` — 开启 Session
  - `closeSession(int $sessionId): ConversationSession` — 关闭 Session
  - `getActiveSession(int $conversationId): ?ConversationSession` — 获取活跃 Session
  - `generateSummary(int $sessionId): string` — 调用 Capability/Summarize 生成摘要（Phase 2 接入）
  - `tagConversation(int $conversationId, int $tagId, string $source): ConversationTag` — 手动/自动标签
  - `autoClassify(int $conversationId): Collection` — 调用 Capability/Classify 自动分类（Phase 2 接入）
  - `getConversationTags(int $conversationId): Collection` — 获取会话标签
- Phase 1 中 Summary/Classify 预留接口，实际调用在 Phase 2 Capability 就绪后接入

**验收**: Session 生命周期管理正确，Summary/Classify 预留接口可 mock

---

### TASK-011: Conversation API Controller

**优先级**: P0 | **依赖**: TASK-008, TASK-009, TASK-010

**内容**:
- Controller: `src/Http/Controllers/ConversationController.php`
- API Resource: `src/Http/Resources/ConversationResource.php`, `MessageResource.php`
- 路由注册（api 路由组，需 tenant 中间件）:

| 方法 | 端点 | 说明 |
|------|------|------|
| GET | `/api/v1/conversations` | 会话列表（分页 + 过滤） |
| GET | `/api/v1/conversations/{id}` | 会话详情 |
| POST | `/api/v1/conversations` | 创建会话 |
| PUT | `/api/v1/conversations/{id}` | 更新会话 |
| POST | `/api/v1/conversations/{id}/close` | 关闭会话 |
| POST | `/api/v1/conversations/{id}/archive` | 归档会话 |
| GET | `/api/v1/conversations/{id}/messages` | 消息时间线（cursor 分页） |
| POST | `/api/v1/conversations/{id}/messages` | 发送消息 |
| POST | `/api/v1/conversations/{id}/participants` | 添加参与者 |
| DELETE | `/api/v1/conversations/{id}/participants/{pid}` | 移除参与者 |
| POST | `/api/v1/messages/{id}/reactions` | 添加 Reaction |
| DELETE | `/api/v1/messages/{id}/reactions/{emoji}` | 移除 Reaction |
| POST | `/api/v1/messages/{id}/read` | 标记已读 |
| GET | `/api/v1/conversations/{id}/sessions` | Session 列表 |
| POST | `/api/v1/conversations/{id}/sessions` | 开启 Session |
| POST | `/api/v1/sessions/{id}/close` | 关闭 Session |
| GET | `/api/v1/conversations/{id}/tags` | 标签列表 |
| POST | `/api/v1/conversations/{id}/tags` | 添加标签 |

**验收**: API 端点可访问，RBAC 权限控制生效

---

### TASK-012: ChannelContract 接口 + ChannelManager

**优先级**: P0 | **依赖**: TASK-001 ~ TASK-003

**内容**:
- Contract: `src/Contracts/ChannelContract.php`
  ```php
  interface ChannelContract {
      public function getProviderName(): string;
      public function onMessage(callable $handler): void;
      public function sendMessage(string $conversationId, Message $message): void;
      public function getParticipants(string $conversationId): array;
      public function getConversationInfo(string $conversationId): array;
      public function verifyCallback(array $payload, array $headers): bool;
      public function parseCallback(array $payload): array;
  }
  ```
- Manager: `src/Services/Channel/ChannelManager.php`
  - `register(string $provider, ChannelContract $channel): void`
  - `get(string $provider): ChannelContract`
  - `all(): Collection`
  - `isRegistered(string $provider): bool`
- DTO: `src/Services/Channel/Dto/ChannelConfig.php` — Channel 配置（provider, credentials, webhook_url 等）
- Config: `config/channels.php` — Channel Provider 注册配置
- 在 `TenancyServiceProvider` 注册 ChannelManager singleton

**验收**: ChannelManager 可注册/获取 Provider，配置驱动

---

### TASK-013: EnterpriseWechat Provider

**优先级**: P0 | **依赖**: TASK-012

**内容**:
- Provider: `src/Services/Channel/Providers/EnterpriseWechatProvider.php`
- 实现 `ChannelContract` 全部方法
- 回调处理:
  - 消息回调解析（文本/图片/语音/视频/文件/位置/链接）
  - 事件回调解析（客户联系/客户群/通讯录变更）
  - 签名验证（AES 解密 + SHA256 校验）
- 消息收发:
  - 发送文本/图片/文件消息
  - 会话存档消息拉取
- 通讯录:
  - 获取部门列表/成员列表
- 客户联系:
  - 获取客户列表/客户详情
- 客户群:
  - 获取群列表/群详情
- HTTP Client: 封装企业微信 API 调用（access_token 管理、自动刷新）
- 配置: `config/channels.php` 中 enterprise_wechat 配置节

**验收**: 回调签名验证通过，消息收发 mock 测试通过

---

### TASK-014: WechatOfficial + WechatMiniProgram Provider

**优先级**: P1 | **依赖**: TASK-012

**内容**:
- Provider: `src/Services/Channel/Providers/WechatOfficialProvider.php`
  - 消息回调（文本/图片/语音/视频/链接/事件）
  - 被动回复消息
  - 模板消息发送
  - 客服消息发送
  - 签名验证（SHA1）
  - access_token 管理
- Provider: `src/Services/Channel/Providers/WechatMiniProgramProvider.php`
  - 小程序消息回调
  - 订阅消息发送
  - access_token 管理
- 配置: `config/channels.php` 中 wechat_official / wechat_mini_program 配置节

**验收**: 回调签名验证通过，消息收发 mock 测试通过

---

### TASK-015: 消息路由 — Channel → EventBus → Message

**优先级**: P0 | **依赖**: TASK-009, TASK-012, TASK-013, TASK-014

**内容**:
- Service: `src/Services/Channel/MessageRouter.php`
- 流程:
  1. Channel Provider 回调 → `MessageRouter::handle(string $provider, array $payload)`
  2. Provider 解析为标准化 `IncomingMessage` DTO
  3. 查找/创建 Conversation（按 channel_id + 外部会话标识）
  4. 查找/创建 Participant
  5. 转换为框架 `Message` 并持久化
  6. 通过 `EventBusService::publish('conversation.message.received', ...)` 分发
- DTO: `src/Services/Channel/Dto/IncomingMessage.php` — 标准化入站消息
- 事件: 注册 `conversation.message.received` 到 EventBus 支持事件列表
- SCRM 等业务域通过订阅 `conversation.message.received` 事件消费消息

**验收**: 端到端测试：模拟企业微信回调 → Message 持久化 → EventBus 分发

---

## Phase 2: Workflow Engine + Capability Registry

### TASK-016: Workflow 数据模型 — 3 张表

**优先级**: P0 | **依赖**: 无

**内容**:
- migration: `database/migrations/2026_06_30_000010_create_workflows_table.php`
  - 字段: `workflow_id`(BigInt PK), `tenant_id`, `name`, `description`(nullable), `triggers`(JSON nullable), `status`(enum: draft/active/paused/archived), `metadata`(JSON nullable), `timestamps`
  - 索引: `idx_tenant`(tenant_id), `idx_status`(tenant_id, status)
- migration: `database/migrations/2026_06_30_000011_create_workflow_nodes_table.php`
  - 字段: `node_id`(BigInt PK), `workflow_id`(FK→workflows), `type`(enum: action/condition/confirm/delay/parallel), `name`(string), `config`(JSON), `next_node_id`(BigInt nullable, self-ref), `position`(JSON nullable, 可视化坐标), `metadata`(JSON nullable), `timestamps`
  - 索引: `idx_workflow`(workflow_id), `idx_type`(type)
- migration: `database/migrations/2026_06_30_000012_create_workflow_executions_table.php`
  - 字段: `execution_id`(BigInt PK), `workflow_id`(FK), `agent_id`(BigInt nullable), `input_data`(JSON), `current_node_id`(BigInt nullable), `status`(enum: running/waiting_confirmation/completed/failed/cancelled), `result`(JSON nullable), `error`(text nullable), `started_at`(timestamp), `completed_at`(timestamp nullable), `timestamps`
  - 索引: `idx_workflow`(workflow_id), `idx_agent`(agent_id), `idx_status`(status)
- Models: `src/Models/Workflow.php`, `src/Models/WorkflowNode.php`, `src/Models/WorkflowExecution.php`
- Enums: `src/Enums/WorkflowStatus.php`, `src/Enums/WorkflowNodeType.php`, `src/Enums/ExecutionStatus.php`
- Workflow 关联: `hasMany(WorkflowNode)`, `hasMany(WorkflowExecution)`
- WorkflowNode 关联: `belongsTo(Workflow)`, `belongsTo(WorkflowNode, 'next_node_id')`

**验收**: 3 张表 migration 可执行，Model 关联正确

---

### TASK-017: WorkflowEngine — 执行引擎

**优先级**: P0 | **依赖**: TASK-016

**内容**:
- Contract: `src/Contracts/WorkflowEngineContract.php`
- Engine: `src/Services/Workflow/WorkflowEngine.php`
- 核心方法:
  - `execute(int $workflowId, array $inputData, ?int $agentId): WorkflowExecution` — 启动执行
  - `resume(int $executionId, array $data): WorkflowExecution` — 恢复执行（confirm 节点确认后）
  - `cancel(int $executionId): void` — 取消执行
- 节点处理器（策略模式）:
  - `src/Services/Workflow/NodeHandlers/ActionNodeHandler.php` — 执行 Tool 调用（通过 ToolRegistry）
  - `src/Services/Workflow/NodeHandlers/ConditionNodeHandler.php` — 条件分支（表达式求值）
  - `src/Services/Workflow/NodeHandlers/ConfirmNodeHandler.php` — 暂停等待确认
  - `src/Services/Workflow/NodeHandlers/DelayNodeHandler.php` — 延时（调度队列任务）
  - `src/Services/Workflow/NodeHandlers/ParallelNodeHandler.php` — 并行执行（多 action 并发）
- NodeHandler 接口: `src/Services/Workflow/Contracts/NodeHandlerContract.php`
  ```php
  interface NodeHandlerContract {
      public function handle(WorkflowNode $node, WorkflowExecution $execution): NodeResult;
  }
  ```
- DTO: `src/Services/Workflow/Dto/NodeResult.php` — 节点执行结果（status, output, nextNodeId）
- 重试机制: 失败节点按 config 中的 `retry_count` + `retry_backoff` 自动重试
- 执行记录: 每个节点执行结果写入 `WorkflowExecution.result` 的节点级记录

**验收**: 单元测试覆盖各节点类型，集成测试覆盖多节点编排

---

### TASK-018: WorkflowService — CRUD + 执行管理

**优先级**: P0 | **依赖**: TASK-016, TASK-017

**内容**:
- Contract: `src/Contracts/WorkflowServiceContract.php`
- Service: `src/Services/Workflow/WorkflowService.php`
- 方法:
  - `create(array $data): Workflow` — 创建 Workflow（含 nodes）
  - `update(int $id, array $data): Workflow` — 更新 Workflow
  - `delete(int $id): void` — 删除 Workflow
  - `find(int $id): ?Workflow` — 查找（含 nodes）
  - `list(int $tenantId, array $filters): LengthAwarePaginator` — 分页列表
  - `activate(int $id): Workflow` — 激活
  - `pause(int $id): Workflow` — 暂停
  - `execute(int $workflowId, array $input, ?int $agentId): WorkflowExecution` — 委托 WorkflowEngine
  - `getExecution(int $executionId): ?WorkflowExecution` — 查询执行记录
  - `listExecutions(int $workflowId, array $filters): LengthAwarePaginator` — 执行历史
  - `cancelExecution(int $executionId): void` — 取消执行
- 在 `TenancyServiceProvider` 注册绑定

**验收**: 单元测试覆盖 CRUD + 执行管理

---

### TASK-019: Workflow API Controller

**优先级**: P1 | **依赖**: TASK-018

**内容**:
- Controller: `src/Http/Controllers/WorkflowController.php`
- API Resource: `src/Http/Resources/WorkflowResource.php`, `WorkflowExecutionResource.php`
- 路由:

| 方法 | 端点 | 说明 |
|------|------|------|
| GET | `/api/v1/workflows` | 列表 |
| GET | `/api/v1/workflows/{id}` | 详情（含 nodes） |
| POST | `/api/v1/workflows` | 创建 |
| PUT | `/api/v1/workflows/{id}` | 更新 |
| DELETE | `/api/v1/workflows/{id}` | 删除 |
| POST | `/api/v1/workflows/{id}/activate` | 激活 |
| POST | `/api/v1/workflows/{id}/pause` | 暂停 |
| POST | `/api/v1/workflows/{id}/execute` | 执行 |
| GET | `/api/v1/workflows/{id}/executions` | 执行历史 |
| GET | `/api/v1/workflow-executions/{id}` | 执行详情 |
| POST | `/api/v1/workflow-executions/{id}/resume` | 恢复（confirm 确认） |
| POST | `/api/v1/workflow-executions/{id}/cancel` | 取消 |

**验收**: API 端点可访问

---

### TASK-020: CapabilityContract + CapabilityRegistry

**优先级**: P0 | **依赖**: 无

**内容**:
- Contract: `src/Contracts/CapabilityContract.php`
  ```php
  interface CapabilityContract {
      public function getName(): string;
      public function execute(array $input, array $options = []): CapabilityResult;
      public function validateInput(array $input): bool;
  }
  ```
- Result DTO: `src/Services/Capability/Dto/CapabilityResult.php`
  - 字段: `capability`(string), `output`(array), `confidence`(float), `tokenUsage`(array), `durationMs`(float)
- Registry: `src/Services/Capability/CapabilityRegistry.php`
  - `register(string $name, CapabilityContract $capability): void`
  - `get(string $name): CapabilityContract`
  - `all(): Collection`
  - `has(string $name): bool`
  - `execute(string $name, array $input, array $options = []): CapabilityResult`
- 在 `TenancyServiceProvider` 注册 CapabilityRegistry singleton

**验收**: Registry 可注册/获取/执行 Capability

---

### TASK-021: CapabilityService — 统一入口 + 计费

**优先级**: P0 | **依赖**: TASK-020

**内容**:
- Service: `src/Services/Capability/CapabilityService.php`
- 方法:
  - `execute(string $capability, array $input, array $options = []): CapabilityResult` — 统一执行入口
  - `executeBatch(array $tasks): Collection` — 批量执行
  - `getAvailable(): Collection` — 获取可用 Capability 列表
- 计费集成:
  - 每次执行记录 token 消耗到 `ai_usage_quotas` 表
  - 按 capability 维度统计调用次数和 token 用量
  - 与现有 `AiUsageService` 集成
- 日志: 每次执行记录到 `ai_requests` 表（扩展字段 capability_name）
- 在 `TenancyServiceProvider` 注册绑定

**验收**: 执行 Capability 后 token 计费正确

---

### TASK-022: Summarize + Tag + Classify Capability

**优先级**: P0 | **依赖**: TASK-020, TASK-021

**内容**:
- `src/Services/Capability/Capabilities/SummarizeCapability.php`
  - 输入: `{ text: string, max_length?: int }`
  - 输出: `{ summary: string }`
  - 实现: 调用 `AiTextService.chat()` 使用摘要 prompt
- `src/Services/Capability/Capabilities/TagCapability.php`
  - 输入: `{ text: string, context?: array, max_tags?: int }`
  - 输出: `{ tags: [{ name: string, confidence: float }] }`
  - 实现: 调用 `AiTextService.chat()` 使用标签推荐 prompt
- `src/Services/Capability/Capabilities/ClassifyCapability.php`
  - 输入: `{ text: string, categories: array, context?: array }`
  - 输出: `{ category: string, confidence: float, alternatives: array }`
  - 实现: 调用 `AiTextService.chat()` 使用分类 prompt
- 每个 Capability 有独立 prompt 模板（存 `ai_prompts` 表或配置文件）
- 注册: 在 `TenancyServiceProvider` 注册到 CapabilityRegistry

**验收**: 3 个 Capability 单元测试通过，输出格式正确

---

### TASK-023: Translate + Intent + Sentiment Capability

**优先级**: P0 | **依赖**: TASK-020, TASK-021

**内容**:
- `src/Services/Capability/Capabilities/TranslateCapability.php`
  - 输入: `{ text: string, target_language: string, source_language?: string }`
  - 输出: `{ translated_text: string, source_language: string }`
- `src/Services/Capability/Capabilities/IntentCapability.php`
  - 输入: `{ text: string, intents?: array }`
  - 输出: `{ intent: string, confidence: float, alternatives: array }`
- `src/Services/Capability/Capabilities/SentimentCapability.php`
  - 输入: `{ text: string }`
  - 输出: `{ sentiment: string, score: float, details: array }`
- 注册到 CapabilityRegistry

**验收**: 3 个 Capability 单元测试通过

---

### TASK-024: Extract + Rewrite + Generate Capability

**优先级**: P1 | **依赖**: TASK-020, TASK-021

**内容**:
- `src/Services/Capability/Capabilities/ExtractCapability.php`
  - 输入: `{ text: string, entity_types?: array }`
  - 输出: `{ entities: [{ type: string, value: string, confidence: float }] }`
- `src/Services/Capability/Capabilities/RewriteCapability.php`
  - 输入: `{ text: string, style: string, tone?: string }`
  - 输出: `{ rewritten_text: string }`
- `src/Services/Capability/Capabilities/GenerateCapability.php`
  - 输入: `{ prompt: string, format?: string, max_length?: int }`
  - 输出: `{ content: string }`
- 注册到 CapabilityRegistry

**验收**: 3 个 Capability 单元测试通过

---

### TASK-025: Search + OCR + Vision + Embedding Capability

**优先级**: P1 | **依赖**: TASK-020, TASK-021

**内容**:
- `src/Services/Capability/Capabilities/SearchCapability.php`
  - 输入: `{ query: string, knowledge_base_id: string, top_k?: int }`
  - 输出: `{ results: [{ content: string, score: float, metadata: array }] }`
  - 实现: 调用向量检索（依赖知识库模块）
- `src/Services/Capability/Capabilities/OcrCapability.php`
  - 输入: `{ image_url: string, language?: string }`
  - 输出: `{ text: string, regions: array }`
  - 实现: 调用 Vision 类 AI 模型
- `src/Services/Capability/Capabilities/VisionCapability.php`
  - 输入: `{ image_url: string, question: string }`
  - 输出: `{ answer: string, confidence: float }`
  - 实现: 调用多模态 AI 模型
- `src/Services/Capability/Capabilities/EmbeddingCapability.php`
  - 输入: `{ text: string, model?: string }`
  - 输出: `{ embedding: float[], dimensions: int }`
  - 实现: 调用 `AiProviderContract::embeddings()`
- 注册到 CapabilityRegistry

**验收**: 4 个 Capability 单元测试通过

---

### TASK-026: Capability API Controller

**优先级**: P1 | **依赖**: TASK-021 ~ TASK-025

**内容**:
- Controller: `src/Http/Controllers/CapabilityController.php`
- 路由:

| 方法 | 端点 | 说明 |
|------|------|------|
| GET | `/api/v1/capabilities` | 可用 Capability 列表 |
| POST | `/api/v1/capabilities/{name}/execute` | 执行 Capability |
| POST | `/api/v1/capabilities/batch` | 批量执行 |

**验收**: API 端点可访问

---

## Phase 3: Agent 架构升级 + Memory + Framework Tools

### TASK-027: Agent Model 升级 — workflows 字段

**优先级**: P0 | **依赖**: TASK-016

**内容**:
- migration: `database/migrations/2026_06_30_000013_add_workflows_to_agents_table.php`
  - 新增字段: `workflows`(JSON nullable) — 关联的 Workflow ID 列表
- Agent Model 更新:
  - `fillable` 新增 `workflows`
  - `casts` 新增 `'workflows' => 'array'`
  - 新增关联: `hasMany(WorkflowExecution, 'agent_id', 'agent_id')`
- 向后兼容: `tools` 字段保留，Agent 仍可直接绑定 Tool

**验收**: migration 可执行，Agent Model 可读写 workflows 字段

---

### TASK-028: WorkflowRegistry — 注册 + 发现

**优先级**: P0 | **依赖**: TASK-016, TASK-027

**内容**:
- Contract: `src/Contracts/WorkflowRegistryContract.php`
- Registry: `src/Services/Workflow/WorkflowRegistry.php`
- 方法:
  - `register(string $slug, string $workflowClass, array $config): void` — 注册 Workflow 定义
  - `get(string $slug): ?WorkflowDefinition` — 获取 Workflow 定义
  - `all(): Collection` — 获取所有已注册 Workflow
  - `getForAgent(int $agentId): Collection` — 获取 Agent 关联的 Workflow
  - `getByCategory(string $category): Collection` — 按分类获取
- DTO: `src/Services/Workflow/Dto/WorkflowDefinition.php` — Workflow 定义（slug, name, description, category, config）
- 与 WorkflowService 集成：数据库 Workflow + 运行时注册 Workflow 合并

**验收**: WorkflowRegistry 可注册/发现 Workflow

---

### TASK-029: AgentRuntime 升级 — Workflow → Tool 执行路径

**优先级**: P0 | **依赖**: TASK-017, TASK-027, TASK-028

**内容**:
- AgentRuntime 改造:
  - `run()` 方法: 检查 Agent 是否配置 workflows
    - 有 workflows → 通过 WorkflowEngine 执行 Workflow，Workflow 内 action 节点调用 ToolRegistry
    - 无 workflows → 保持现有 ReAct 循环（向后兼容）
  - 新增 `runWorkflow(int $agentId, int $conversationId, string $workflowSlug, array $input): AgentResponse`
- Workflow 内 Tool 调用复用现有 `ToolRegistry::execute()`
- Workflow 执行结果转换为 AgentResponse 格式
- 执行追踪: 记录完整调用链路 Agent → Workflow → Tool

**验收**: 单步 Tool 调用（无 Workflow）行为不变；配置 Workflow 后通过 Workflow 执行

---

### TASK-030: Agent.tools → Agent.workflows 自动迁移

**优先级**: P1 | **依赖**: TASK-027, TASK-028, TASK-029

**内容**:
- Artisan Command: `src/Console/Commands/MigrateAgentToolsToWorkflows.php`
  - `php artisan agent:migrate-tools-to-workflows`
- 迁移逻辑:
  - 遍历所有 Agent
  - 对每个 Agent 的 `tools` JSON 数组，创建单步骤 Workflow（一个 action 节点）
  - 将生成的 Workflow ID 写入 Agent 的 `workflows` 字段
  - 保留 `tools` 字段不删除（向后兼容）
- 迁移报告: 输出迁移数量、失败列表

**验收**: 迁移后 Agent 行为不变，workflows 字段正确填充

---

### TASK-031: ToolRegistry 分类体系升级

**优先级**: P0 | **依赖**: TASK-027

**内容**:
- `agent_tools` 表已有 `category` 字段，需完善分类体系
- migration: `database/migrations/2026_06_30_000014_update_agent_tools_category.php`
  - 确保 category 字段覆盖: core/ai/storage/knowledge/customer/campaign/content/report/channel/workflow
- ToolRegistry 升级:
  - `getByCategory(string $category): Collection` — 按分类获取工具
  - `getCategories(): array` — 获取所有分类
  - `getFrameworkTools(): Collection` — 获取框架级工具（tenant_id=0）
  - `getBusinessTools(int $tenantId): Collection` — 获取业务级工具
- Enum: `src/Enums/ToolCategory.php`
- Tool DTO 扩展: 增加 `category` 字段

**验收**: 按分类查询工具正确

---

### TASK-032: TenantMemory 实现

**优先级**: P1 | **依赖**: 无

**内容**:
- Contract: `src/Contracts/MemoryContract.php`
  ```php
  interface MemoryContract {
      public function read(array $context): array;
      public function write(array $data): void;
      public function forget(?string $key = null): void;
  }
  ```
- Service: `src/Services/Memory/TenantMemory.php`
- 存储: MySQL 表 `tenant_memories`（新增 migration）
  - 字段: `memory_id`(BigInt PK), `tenant_id`, `category`(string), `key`(string), `value`(JSON), `importance`(float default 1.0), `access_count`(int default 0), `last_accessed_at`(timestamp), `timestamps`
  - 索引: `idx_tenant_category`(tenant_id, category), `idx_key`(tenant_id, category, key)
- 功能:
  - 租户级偏好存储（如回复风格、业务规则）
  - 历史决策记录
  - 按 category + key 读写
  - 按 importance 排序检索

**验收**: TenantMemory 读写正确，租户隔离生效

---

### TASK-033: EntityMemory 实现

**优先级**: P1 | **依赖**: TASK-032

**内容**:
- Service: `src/Services/Memory/EntityMemory.php`
- 存储: MySQL 表 `entity_memories`（新增 migration）
  - 字段: `memory_id`(BigInt PK), `tenant_id`, `entity_type`(string), `entity_ref_id`(BigInt), `category`(string), `key`(string), `value`(JSON), `importance`(float default 1.0), `decay_rate`(float default 0.0), `access_count`(int default 0), `last_accessed_at`(timestamp), `timestamps`
  - 索引: `idx_entity`(tenant_id, entity_type, entity_ref_id), `idx_category`(entity_type, category)
- 功能:
  - 通用实体记忆（type + ref_id 定位实体）
  - SCRM: `EntityMemory(type='customer', id=123)` → 客户记忆
  - ERP: `EntityMemory(type='supplier', id=456)` → 供应商记忆
  - 支持 `read()` 时自动注入 Agent 上下文
  - 支持 `write()` 时 Agent 执行后自动写入关键信息
  - 衰减策略: `decay_rate` 控制记忆权重随时间衰减

**验收**: EntityMemory 读写正确，衰减策略生效

---

### TASK-034: Memory 压缩管道 + 衰减策略

**优先级**: P1 | **依赖**: TASK-032, TASK-033

**内容**:
- Service: `src/Services/Memory/MemoryPipeline.php`
- 功能:
  - `compressConversationToSummary(int $conversationId): void` — 将 ConversationMemory 压缩为 SummaryMemory
  - `applyDecay(string $memoryType, int $tenantId): int` — 对指定类型记忆应用衰减（定时任务）
  - `cleanupExpired(string $memoryType, int $retentionDays): int` — 清理过期记忆
- 定时任务:
  - `src/Console/Commands/MemoryDecayCommand.php` — `php artisan memory:decay`
  - `src/Console/Commands/MemoryCleanupCommand.php` — `php artisan memory:cleanup`
- 与现有 `MemoryCompressor` 集成: ConversationMemory 压缩后写入 SummaryMemory
- Agent 上下文注入:
  - AgentRuntime 执行前调用 `MemoryPipeline::prepareContext()` 注入 TenantMemory + EntityMemory
  - AgentRuntime 执行后调用 `MemoryPipeline::extractAndStore()` 提取关键信息写入 EntityMemory

**验收**: 压缩管道正确，衰减定时任务可执行

---

### TASK-035: Framework Tools — Core 类

**优先级**: P0 | **依赖**: TASK-031

**内容**:
- `src/Services/Tool/Framework/Core/LlmCallTool.php` — `llm_call`
  - 调用 LLM 模型，封装 `AiTextService.chat()`
  - 参数: `{ prompt: string, model?: string, temperature?: float, max_tokens?: int }`
- `src/Services/Tool/Framework/Core/HttpRequestTool.php` — `http_request`
  - 发送 HTTP 请求，封装 Laravel HTTP Client
  - 参数: `{ method: string, url: string, headers?: object, body?: object, timeout?: int }`
- `src/Services/Tool/Framework/Core/WebhookTriggerTool.php` — `webhook_trigger`
  - 触发 Webhook，封装 `WebhookService`
  - 参数: `{ url: string, event: string, payload: object, secret?: string }`
- `src/Services/Tool/Framework/Core/EmailSendTool.php` — `email_send`
  - 发送邮件，封装 Laravel Mail
  - 参数: `{ to: string, subject: string, body: string, template?: string }`
- 所有 Tool 实现 `ToolHandlerContract`
- category: `core`

**验收**: 4 个 Tool 单元测试通过

---

### TASK-036: Framework Tools — Storage 类

**优先级**: P1 | **依赖**: TASK-031

**内容**:
- `src/Services/Tool/Framework/Storage/FileReadTool.php` — `file_read`
  - 读取文件内容，封装 `FileService`
  - 参数: `{ path: string, encoding?: string }`
- `src/Services/Tool/Framework/Storage/FileWriteTool.php` — `file_write`
  - 写入文件，封装 `FileService`
  - 参数: `{ path: string, content: string, encoding?: string }`
- `src/Services/Tool/Framework/Storage/CacheGetTool.php` — `cache_get`
  - 读取缓存，封装 `CacheService`
  - 参数: `{ key: string, default?: mixed }`
- `src/Services/Tool/Framework/Storage/CacheSetTool.php` — `cache_set`
  - 写入缓存，封装 `CacheService`
  - 参数: `{ key: string, value: mixed, ttl?: int }`
- category: `storage`

**验收**: 4 个 Tool 单元测试通过

---

### TASK-037: Framework Tools — AI 类

**优先级**: P1 | **依赖**: TASK-025, TASK-031

**内容**:
- `src/Services/Tool/Framework/Ai/OcrRecognizeTool.php` — `ocr_recognize`
  - 调用 OCR Capability
  - 参数: `{ image_url: string, language?: string }`
- `src/Services/Tool/Framework/Ai/VectorSearchTool.php` — `vector_search`
  - 调用向量检索（依赖知识库模块）
  - 参数: `{ query: string, knowledge_base_id: string, top_k?: int }`
- `src/Services/Tool/Framework/Ai/EmbeddingGenerateTool.php` — `embedding_generate`
  - 调用 Embedding Capability
  - 参数: `{ text: string, model?: string }`
- category: `ai`

**验收**: 3 个 Tool 单元测试通过

---

### TASK-038: Framework Tools — KB 类

**优先级**: P1 | **依赖**: TASK-031

**内容**:
- `src/Services/Tool/Framework/Kb/KnowledgeSearchTool.php` — `knowledge_search`
  - 知识库检索，封装知识库服务
  - 参数: `{ query: string, knowledge_base_id: string, top_k?: int, filters?: object }`
- `src/Services/Tool/Framework/Kb/DocumentParseTool.php` — `document_parse`
  - 文档解析（PDF/Word/HTML → 文本）
  - 参数: `{ file_url: string, format?: string }`
- category: `knowledge`

**验收**: 2 个 Tool 单元测试通过

---

### TASK-039: Framework Tools 注册 + Seeder

**优先级**: P1 | **依赖**: TASK-035 ~ TASK-038

**内容**:
- Seeder: `database/seeders/FrameworkToolSeeder.php`
- 注册所有 Framework Tool 到 `agent_tools` 表（tenant_id=0 表示全局）
- 注册顺序:
  - Core: llm_call, http_request, webhook_trigger, email_send
  - Storage: file_read, file_write, cache_get, cache_set
  - AI: ocr_recognize, vector_search, embedding_generate
  - KB: knowledge_search, document_parse
- 每个 Tool 注册完整的 `parameters_schema`（JSON Schema 格式）
- 在 `TenancyServiceProvider` 中注册 FrameworkToolServiceProvider
- 同时注册所有 Capability 到 CapabilityRegistry

**验收**: `php artisan db:seed --class=FrameworkToolSeeder` 执行成功，13 个 Tool 注册到数据库

---

## 汇总

| Phase | 任务范围 | TASK 编号 | 任务数 |
|-------|---------|-----------|--------|
| Phase 1 | Conversation Center + Channel | TASK-001 ~ TASK-015 | 15 |
| Phase 2 | Workflow Engine + Capability Registry | TASK-016 ~ TASK-026 | 11 |
| Phase 3 | Agent 升级 + Memory + Framework Tools | TASK-027 ~ TASK-039 | 13 |
| **合计** | | | **39** |

---

## 新增文件清单

### Migrations (14)
```
database/migrations/2026_06_30_000001_create_conversations_table.php
database/migrations/2026_06_30_000002_create_messages_table.php
database/migrations/2026_06_30_000003_create_conversation_participants_table.php
database/migrations/2026_06_30_000004_create_attachments_table.php
database/migrations/2026_06_30_000005_create_reactions_table.php
database/migrations/2026_06_30_000006_create_mentions_table.php
database/migrations/2026_06_30_000007_create_read_states_table.php
database/migrations/2026_06_30_000008_create_conversation_sessions_table.php
database/migrations/2026_06_30_000009_create_conversation_tags_table.php
database/migrations/2026_06_30_000010_create_workflows_table.php
database/migrations/2026_06_30_000011_create_workflow_nodes_table.php
database/migrations/2026_06_30_000012_create_workflow_executions_table.php
database/migrations/2026_06_30_000013_add_workflows_to_agents_table.php
database/migrations/2026_06_30_000014_update_agent_tools_category.php
```

### Models (14)
```
src/Models/Conversation.php
src/Models/Message.php
src/Models/ConversationParticipant.php
src/Models/Attachment.php
src/Models/Reaction.php
src/Models/Mention.php
src/Models/ReadState.php
src/Models/ConversationSession.php
src/Models/ConversationTag.php
src/Models/Workflow.php
src/Models/WorkflowNode.php
src/Models/WorkflowExecution.php
src/Models/TenantMemory.php (TASK-032)
src/Models/EntityMemory.php (TASK-033)
```

### Enums (9)
```
src/Enums/ConversationType.php
src/Enums/ConversationStatus.php
src/Enums/MessageType.php
src/Enums/ParticipantType.php
src/Enums/ParticipantRole.php
src/Enums/SessionStatus.php
src/Enums/TagSource.php
src/Enums/WorkflowStatus.php
src/Enums/WorkflowNodeType.php
src/Enums/ExecutionStatus.php
src/Enums/ToolCategory.php
```

### Contracts (6)
```
src/Contracts/ConversationServiceContract.php
src/Contracts/MessageServiceContract.php
src/Contracts/ChannelContract.php
src/Contracts/WorkflowEngineContract.php
src/Contracts/WorkflowServiceContract.php
src/Contracts/WorkflowRegistryContract.php
src/Contracts/CapabilityContract.php
src/Contracts/MemoryContract.php
```

### Services — Conversation Center (3)
```
src/Services/Conversation/ConversationService.php
src/Services/Conversation/MessageService.php
src/Services/Conversation/SessionService.php
```

### Services — Channel (7)
```
src/Services/Channel/ChannelManager.php
src/Services/Channel/MessageRouter.php
src/Services/Channel/Dto/ChannelConfig.php
src/Services/Channel/Dto/IncomingMessage.php
src/Services/Channel/Providers/EnterpriseWechatProvider.php
src/Services/Channel/Providers/WechatOfficialProvider.php
src/Services/Channel/Providers/WechatMiniProgramProvider.php
```

### Services — Workflow (9)
```
src/Services/Workflow/WorkflowEngine.php
src/Services/Workflow/WorkflowService.php
src/Services/Workflow/WorkflowRegistry.php
src/Services/Workflow/Contracts/NodeHandlerContract.php
src/Services/Workflow/Dto/NodeResult.php
src/Services/Workflow/Dto/WorkflowDefinition.php
src/Services/Workflow/NodeHandlers/ActionNodeHandler.php
src/Services/Workflow/NodeHandlers/ConditionNodeHandler.php
src/Services/Workflow/NodeHandlers/ConfirmNodeHandler.php
src/Services/Workflow/NodeHandlers/DelayNodeHandler.php
src/Services/Workflow/NodeHandlers/ParallelNodeHandler.php
```

### Services — Capability (17)
```
src/Services/Capability/CapabilityRegistry.php
src/Services/Capability/CapabilityService.php
src/Services/Capability/Dto/CapabilityResult.php
src/Services/Capability/Capabilities/SummarizeCapability.php
src/Services/Capability/Capabilities/TagCapability.php
src/Services/Capability/Capabilities/ClassifyCapability.php
src/Services/Capability/Capabilities/TranslateCapability.php
src/Services/Capability/Capabilities/IntentCapability.php
src/Services/Capability/Capabilities/SentimentCapability.php
src/Services/Capability/Capabilities/ExtractCapability.php
src/Services/Capability/Capabilities/RewriteCapability.php
src/Services/Capability/Capabilities/GenerateCapability.php
src/Services/Capability/Capabilities/SearchCapability.php
src/Services/Capability/Capabilities/OcrCapability.php
src/Services/Capability/Capabilities/VisionCapability.php
src/Services/Capability/Capabilities/EmbeddingCapability.php
```

### Services — Memory (4)
```
src/Services/Memory/TenantMemory.php
src/Services/Memory/EntityMemory.php
src/Services/Memory/MemoryPipeline.php
```

### Services — Framework Tools (13)
```
src/Services/Tool/Framework/Core/LlmCallTool.php
src/Services/Tool/Framework/Core/HttpRequestTool.php
src/Services/Tool/Framework/Core/WebhookTriggerTool.php
src/Services/Tool/Framework/Core/EmailSendTool.php
src/Services/Tool/Framework/Storage/FileReadTool.php
src/Services/Tool/Framework/Storage/FileWriteTool.php
src/Services/Tool/Framework/Storage/CacheGetTool.php
src/Services/Tool/Framework/Storage/CacheSetTool.php
src/Services/Tool/Framework/Ai/OcrRecognizeTool.php
src/Services/Tool/Framework/Ai/VectorSearchTool.php
src/Services/Tool/Framework/Ai/EmbeddingGenerateTool.php
src/Services/Tool/Framework/Kb/KnowledgeSearchTool.php
src/Services/Tool/Framework/Kb/DocumentParseTool.php
```

### Controllers (4)
```
src/Http/Controllers/ConversationController.php
src/Http/Controllers/WorkflowController.php
src/Http/Controllers/CapabilityController.php
```

### Events (3)
```
src/Events/MessageSent.php
src/Events/MessageReceived.php
src/Events/MentionDetected.php
```

### Commands (4)
```
src/Console/Commands/MigrateAgentToolsToWorkflows.php
src/Console/Commands/MemoryDecayCommand.php
src/Console/Commands/MemoryCleanupCommand.php
```

### Config (1)
```
config/channels.php
```

### Seeders (1)
```
database/seeders/FrameworkToolSeeder.php
```
