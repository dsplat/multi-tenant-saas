# 预设任务链（Task Chain）设计规范

> 状态：设计稿（本轮仅交付设计，引擎与预设链实现为下一轮独立任务）
> 关联：AI 小助手完整化计划 · 需求 5「复杂任务编排」
> 契约预留点：`GET /v1/ai/assistant/suggestions` 已返回 `task_chains` 字段（引擎就位前固定空数组）

## 一、定位与边界

预设任务链是框架层的**通用能力**：把一个复杂业务目标（如「策划并上线一场营销活动」）
预先拆解为多个有序子任务，由 AI 小助手引导用户逐步推进，每步产出沉淀为链上下文供后续步骤消费。

与相邻机制的职责划分：

| 机制 | 适用场景 | 决策方 |
|---|---|---|
| **单工具调用** | 一步可完成的操作（建优惠券、查数据） | LLM 自主选择工具 |
| **预设任务链** | 多步骤、有依赖、跨数字员工的标准作业流程 | 人预定义步骤，LLM 负责步内执行与话术 |
| **Workflow 引擎**（后续迭代） | 定时/事件触发的无人值守自动化 | 系统调度，无对话参与 |

设计原则：

- **人定骨架、AI 填肉**：链的步骤序列由人预设（可预期铁律），每步的具体执行由 LLM + 工具完成
- **可中断续跑**：链状态持久化，用户随时离开，回来从当前步继续
- **写操作必确认**：链中的 L2 步骤沿用现有 `pending_confirmation` 确认卡片机制，不绕过
- **AI 可选性**：链引擎故障只影响链推进，不影响普通对话与单工具调用

## 二、链定义 Schema

链定义为纯数据（PHP 数组 / JSON），不含执行逻辑：

```php
[
    'key'           => 'launch_marketing_campaign',   // 全局唯一标识
    'title'         => '策划并上线营销活动',            // 展示名
    'description'   => '从策划文档到活动上线的完整流程', // 开场引导卡片文案
    'trigger_hints' => ['策划活动', '上线活动', '搞一场活动'], // 秘书意图匹配提示词
    'steps'         => [ /* 步骤定义，见下 */ ],
]
```

### 步骤（step）定义

```php
[
    'name'         => '解析策划文档',        // 步骤展示名
    'type'         => 'tool',              // tool | delegate | input | upload
    'tool'         => 'document_parse',    // type=tool 时：工具 slug
    'agent_role'   => null,                // type=delegate 时：目标数字员工 role
    'input_schema' => null,                // type=input 时：需要用户补充的字段（JSON Schema）
    'output_key'   => 'plan_text',         // 本步产出写入链上下文的键名
    'optional'     => false,               // 可跳过步骤（如「上传文档」可改口述）
]
```

四种步骤类型：

| type | 语义 | 执行方 |
|---|---|---|
| `tool` | 调用一个已注册工具 | ToolRegistry（沿用 L1/L2 风险分级与确认机制） |
| `delegate` | 转派给指定角色的数字员工完成一段对话式产出 | AgentRuntime（编排）→ AgentChatClient（推理）+ AgentToolExecutor（工具执行） |
| `input` | 需要用户补充结构化信息 | 前端表单卡片 → 写入链上下文 |
| `upload` | 需要用户上传文件 | 前端上传 → file_upload_id 写入链上下文 |

### 步际上下文传递

- 链上下文是一个扁平 KV 包（`context: {output_key: value}`），每步产出按 `output_key` 写入
- 后续步骤的工具参数 / delegate 开场消息中可用 `{{plan_text}}` 占位符引用前序产出
- 上下文随 `task_chain_runs.steps_state` 持久化，值过大时（>16KB）只存引用（如 file_upload_id）

## 三、注册与扩展：TaskChainRegistry

模式与数字员工模板的 `extra_template_classes` 完全一致（下游项目零侵入扩展）：

```php
// 框架 config/ai.php
'task_chains' => [
    'enabled' => env('AI_TASK_CHAINS_ENABLED', false),
    // 下游扩展类：实现 TaskChainProviderContract::chains(): array
    'extra_chain_classes' => [],
],
```

```php
// 下游（如 scrm-platform）AppServiceProvider
config(['ai.task_chains.extra_chain_classes' => [ScrmTaskChains::class]]);
```

`TaskChainRegistry` 职责：

- `all(): array` — 合并框架内置链 + 下游扩展链（key 冲突时下游覆盖框架）
- `find(string $key): ?array` — 按 key 取链定义
- `matchByIntent(string $intent): array` — 按 trigger_hints 关键词粗筛（供秘书提示，不做最终决策）
- 定义合法性校验（key 唯一、step type 合法、tool slug 已注册）在注册时完成，坏定义记日志跳过

## 四、执行模型：TaskChainRunner

### task_chain_runs 表

| 字段 | 类型 | 说明 |
|---|---|---|
| run_id | bigint PK（全局 ID） | |
| tenant_id | bigint index | 租户隔离铁律 |
| conversation_id | bigint index | 归属会话（链在会话内推进） |
| chain_key | string | 链定义 key |
| steps_state | json | 每步状态快照：`[{name, status, output_key, output_summary}]` + `context` KV |
| current_step | int | 当前步下标（0 起） |
| status | string | `running` / `waiting_input` / `waiting_confirm` / `completed` / `failed` / `cancelled` |
| created_at / updated_at | timestamp | |

### 推进语义

- `start(chainKey, conversationId)` → 建 run 记录，进入第 0 步
- `advance(runId, ?stepInput)` → 执行当前步：
  - `tool` 步：组装参数（含上下文占位符替换）→ ToolRegistry 执行；L2 工具走确认卡片，run 置 `waiting_confirm`，确认回调后续推
  - `delegate` 步：经 AgentRuntime 编排 → AgentToolExecutor 执行，产出摘要写上下文
  - `input` / `upload` 步：置 `waiting_input`，前端提交后带 stepInput 再次 advance
  - 成功 → `current_step + 1`；到尾 → `completed`
- **中断续跑**：run 按 conversation_id 可查，开场引导 `task_chains` 字段返回未完成 run 供「继续」入口
- **失败重试**：步骤失败置 `failed` 但保留 `current_step`，用户可「重试当前步」或「跳过」（仅 optional 步）；不自动重试
- **回滚边界**：链不做跨步事务回滚——每步是独立业务操作（已过确认的写操作有审计），失败只停在当前步，已完成步骤的产出保留

## 五、秘书工具契约

链引擎通过三个框架工具暴露给小助手（注册于 `AiServiceProvider::registerFrameworkTools`）：

| slug | risk | 语义 |
|---|---|---|
| `list_task_chains` | L1 | 返回可用链目录（key/title/description）+ 当前会话未完成 run |
| `start_task_chain` | **L2** | 启动链。先弹确认卡片（展示链标题 + 全部步骤计划），确认后建 run 并返回第一步指令 |
| `advance_task_chain` | L1* | 推进当前步。*步内若含 L2 工具，由该工具自身的确认机制把关，advance 本身不重复确认 |

返回结构约定：三工具均返回 `{run_id, chain_key, title, steps: [{name, status}], current_step, status, next_action}`，
`next_action` 为面向 LLM 的下一步指令文本（如「请让用户上传策划文档」）。

## 六、前置工具依赖（本轮已落地）

| 工具 | 层 | 状态 |
|---|---|---|
| `document_parse` | 框架 | ✅ 已实现（txt/md/csv/xlsx/docx，PDF 依赖可选 smalot/pdfparser） |
| `generate_poster` | 框架 | ✅ 已实现（BailianImageProvider · qwen-image-2.0，出图失败降级为海报文案） |
| `create_distribution_plan` | SCRM | ✅ 已实现（L2，启用分销 + 建佣金规则，max_level 合规锁 2 级） |

## 七、预设链目录（SCRM 首批）

### 1. 策划并上线营销活动 `launch_marketing_campaign`

| # | 步骤 | type | 产出 |
|---|---|---|---|
| 0 | 上传策划文档（可跳过改口述） | upload (optional) | file_id |
| 1 | 解析文档入上下文 | tool: document_parse | plan_text |
| 2 | 营销策划员工出活动方案 | delegate: marketing_planner | campaign_brief |
| 3 | 创建营销活动 | tool: create_campaign (L2) | campaign_id |
| 4 | 设计制作员工出海报 | tool: generate_poster | poster_file_id |
| 5 | 建分销计划 | tool: create_distribution_plan (L2) | distribution_config |
| 6 | 素材入库 + 汇报总结 | delegate: system_secretary | summary |

### 2. 新客欢迎旅程配置 `setup_welcome_journey`

欢迎语配置 → 标签规则 → 首单优惠券（L2）→ 跟进 SOP，产出完整新客接待链路。

### 3. 本周经营数据分析 `weekly_business_review`

数据分析员工拉取本周核心指标 → 客户洞察员工归因 → 产出周报 + 改进建议清单。

## 八、前端渲染

- 链进度复用 `WorkflowProgress.vue`（`WorkflowSuggestion` 结构兼容：链 → name/steps/status 直接映射）
- 每步产出以消息卡片呈现（海报图卡片 / 活动详情卡片 / 文本摘要）
- 开场引导：`suggestions.task_chains` 返回 `[{key, title, description, unfinished_run_id?}]`，
  前端渲染任务链卡片（有 `unfinished_run_id` 时展示「继续」按钮）——前端卡片位已在 AssistantPanel 空状态预留
- `input` / `upload` 步的表单/上传卡片为新增组件（实现期交付）

## 九、分期实施与验收

**Phase 1（引擎最小闭环）**：TaskChainRegistry + task_chain_runs 迁移 + TaskChainRunner（仅 tool/input 步）+ 三个秘书工具 + 单测。
验收：框架内置一条两步演示链可走通「启动确认 → 逐步推进 → 完成」，中断后可续跑。

**Phase 2（delegate/upload + SCRM 首链）**：delegate/upload 步型 + `launch_marketing_campaign` 全链 + WorkflowProgress 渲染接线 + suggestions.task_chains 实数据。
验收：生产环境从上传策划文档到活动上线全链走通，每个 L2 步骤均出现确认卡片。

**Phase 3（链目录扩充）**：欢迎旅程链、周报链，以及 Admin 后台链目录管理界面（可选）。

通用验收标准：

- 链引擎关闭（`AI_TASK_CHAINS_ENABLED=false`）时，秘书对话与单工具调用完全不受影响
- 所有 run 记录带 tenant_id，跨租户不可见（租户隔离铁律）
- L2 步骤绕过确认卡片视为阻断性缺陷
