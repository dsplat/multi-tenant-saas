# 活动运营编排（Event Plan / Campaign）设计规范

> 状态：**Phase 0 已实施**（排期骨架：两表 + 编译器 + 调度 + 待办确认 + 管理 API）；Phase 1/2/3 未启动
> 实现位置：`src/Modules/Campaign/`（独立模块，composer split 产出 `dsplat/multi-tenant-saas-module-campaign`）
> 关联：`docs/task-chain.md`（会话内任务链）· AI 小助手完整化计划 · SCRM Event 模块
> 核心思想：**Plan 即文档（JSON/YAML 可移植），Schedule 即数据（编译散入数据库）**

## 一、定位与边界

活动运营编排是框架层的**日历级编排能力**：把一个跨越天/周的运营周期（如「一场线下课程从策划到闭环」）
表达为一份**计划文档**，由 AI 与人在对话中共创定稿，随后**编译**为散布在时间轴上的任务记录，
由系统调度到点执行——AI 编排计划、系统调度执行、AI 填充触点、数据驱动修订。

与相邻机制的职责划分（三层互补，不竞争）：

| 机制 | 时间尺度 | 载体 | 决策方 |
|---|---|---|---|
| **单工具调用** | 秒级，一步完成 | 会话消息 | LLM 自主选择工具 |
| **预设任务链**（task-chain.md） | 分钟级，会话内多步推进 | `task_chain_runs` | 人预定义步骤，LLM 步内执行 |
| **活动运营编排**（本文） | 天/周级，跨会话跨日历 | `campaign_plans` + `campaign_tasks` | AI 生成计划 + 人确认，系统调度触发 |
| Workflow 引擎 | 事件驱动无人值守自动化 | workflow 节点图 | 系统调度，无对话参与 |

**组合关系**：campaign_task 的一个触点可以是"启动一条任务链"——任务链是触点内的步骤编排，
campaign 是触点之间的日历编排。两层通过 `task_type: task_chain` 组合，互不重复造轮子。

设计原则（与任务链共享同一套铁律）：

- **人定骨架、AI 填肉**：计划结构由 playbook 模板约束 + 人逐条确认（可预期铁律），触点内容由 LLM 到点生成
- **计划即数据**：plan 文档是纯数据（JSON 权威 / YAML 视图），不含执行逻辑，可导出/导入/diff/版本化
- **执行永远委托既定 Service**：SmsService / MassPushService / EventService…，编排层不直接写业务表（AI 可选性铁律）
- **写操作必确认**：L2 任务按编排时预设走 `auto` 或 `require_confirm`（异步待办，非会话确认卡片）
- **编排引擎可关闭**：关闭后 Event 模块、普通对话、任务链完全不受影响

## 二、生命周期四阶段

```
Plan（共创）──定稿──▶ Scheduled（编译落库）──到期──▶ Running（触点执行）──活动结束──▶ Reviewing ──▶ Closed
     ▲                                                    │
     └────────────── 数据反馈 → 计划修订 → 重编译 ◀────────┘
```

| 阶段 | plan.status | 主要活动 | 参与方 |
|---|---|---|---|
| Plan | `planning` | 多轮对话补齐资料、AI 按 playbook 生成计划草案、人逐项确认修订 | 人 + 秘书/营销策划员工 |
| 排期 | `scheduled` | 计划定稿 → 编译为 campaign_tasks 散入时间轴，锚点解析为绝对时间 | 系统（编译器） |
| Run | `running` | 调度器到点触发：agent 任务派数字员工、human 任务发待办、on_event 任务挂事件监听 | 系统 + 数字员工 + 人 |
| Review/Close | `reviewing` → `closed` | 数据汇总、AI 复盘报告、课后服务任务、人工确认关闭 | AI 生成 + 人确认 |

计划修订：`running` 中可回到修订流程——已执行任务不可变（审计保留），
未执行任务按新计划 diff 后重编译（新增/改期/取消），修订记录追加进 plan 文档的 `revisions`。

## 三、Plan 文档 Schema（可移植核心）

计划本体是一份 schema 版本化的 JSON 文档（存 `campaign_plans.plan_doc`，前端/导出可渲染为 YAML）。
**文档内不出现任何绝对时间与租户内部 ID**——时间用锚点表达式，资源用符号引用，这是可移植性的关键。

**形态类比：声明式 workflow DSL 文件**（如 GitHub Actions `workflow.yml` / Argo Workflows YAML）——
定义文件是唯一权威源，引擎负责解析调度执行；`relative`/`on_event` 对应 `on: schedule/push`，
`depends_on` 对应 `needs`，`{{task.x.output}}` 对应 `${{ needs.x.outputs }}`。正因文件环境无关，
才能跨仓库（跨租户/跨环境）直接复用。注意与框架既有 Workflow 模块（执行态节点图，含具体配置、
不可移植）区分：本设计不复用节点图，而是在编排层新做 DSL。

```yaml
schema: campaign.plan/v1
title: 「增长实战」线下两天课运营计划
playbook: offline_course_launch          # 生成本计划所依据的 playbook key（可空）
anchor_object: { type: event }           # 锚定对象类型；导入时绑定具体 event_id
goals: { signup: 200, revenue: 99800 }   # 复盘阶段对照的目标
phases:
  - key: warmup                          # 阶段仅作分组展示，不影响调度
    title: 预热期
    tasks:
      - key: warmup_poster               # 计划内唯一，供 depends_on / 产出引用
        title: 设计课程海报
        trigger: { type: relative, anchor: event.starts_at, offset: -7d, at: "10:00" }
        assignee: { type: agent, role: scrm_design }
        action: { type: tool, tool: generate_poster, args: { brief: "{{plan.goals}} {{task.warmup_copy.output}}" } }
        execution_mode: require_confirm  # 编排时预设：auto | require_confirm
        depends_on: [warmup_copy]
      - key: daily_sms
        title: 每日短信触达
        trigger: { type: recurring, anchor: event.starts_at, from: -7d, until: -1d, at: "09:30" }
        assignee: { type: agent, role: scrm_marketing }
        action: { type: tool, tool: sms_scheduled_send, args: { audience: registered_users } }
        execution_mode: auto
  - key: run
    title: 开课期
    tasks:
      - key: paid_notify
        title: 购买成功通知
        trigger: { type: on_event, event: order_paid }     # 事件触发，无固定时间
        action: { type: tool, tool: send_notification }
        execution_mode: auto
      - key: checkin_remind
        title: 开课签到提醒
        trigger: { type: relative, anchor: event.starts_at, offset: -2h }
        action: { type: tool, tool: mass_push }
        execution_mode: auto
  - key: review
    title: 复盘期
    tasks:
      - key: retro_report
        title: 复盘报告
        trigger: { type: relative, anchor: event.ends_at, offset: +1d }
        assignee: { type: agent, role: scrm_analyst }
        execution_mode: require_confirm
revisions: []                            # [{at, reason, changed_task_keys}]
```

要点：

- **触发三型**：`at_time`（绝对时间，编译时才产生）/ `relative`（锚点±偏移，文档态唯一推荐）/ `on_event`（业务事件）/ `recurring`（relative 的区间重复展开）
- **上下文引用**：`{{task.<key>.output}}` 引用前序任务产出、`{{plan.*}}` 引用计划字段——语法与任务链的 `{{output_key}}` 占位符同源（见第九节）
- **产出过大存引用**（>16KB 只存 file_upload_id），沿用任务链同款策略

## 四、编译与落库

Plan 文档 → campaign_tasks 是一次**编译**：解析锚点为绝对时间、展开 recurring、校验工具存在与依赖无环。
文档是源码，任务表是编译产物；重排期 = 重编译（幂等，按 task key diff 增量更新未执行任务）。

**Phase 0 实现说明**：编译器（`PlanCompiler::compile`）采用调用方注入 `anchor_times`
（如 `['event.starts_at' => '2026-08-10 09:00']`），不绑定具体业务对象类型；
下游对接 Event 时再做 AnchorResolver 自动解析。主键命名为 `plan_id`/`task_id`（框架 HasGlobalId 惯例）。

### campaign_plans 表

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK（全局 ID） | |
| tenant_id | bigint index | 租户隔离铁律 |
| anchor_type / anchor_id | string / bigint | 锚定对象（如 event / 事件 ID），锚点时间从此对象解析 |
| plan_doc | json | Plan 文档权威本体（schema 版本化） |
| status | string | `planning` / `scheduled` / `running` / `reviewing` / `closed` / `cancelled` |
| playbook_key | string nullable | 来源 playbook |
| created_by | bigint | 定稿确认人（Operator） |
| created_at / updated_at | timestamp | |

### campaign_tasks 表（编译产物）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| tenant_id / plan_id | bigint index | |
| task_key | string | 对应文档内 task key（重编译 diff 依据，plan 内唯一） |
| title / phase_key | string | 展示 |
| trigger_type | string | `at_time` / `on_event` |
| scheduled_at | datetime nullable index | at_time：编译解析后的绝对时间（调度扫描键） |
| listen_event | string nullable | on_event：监听的事件名（如 `order_paid`） |
| assignee_type / assignee_ref | string | `agent`+role / `human`+operator_id / `system` |
| action | json | `{type: tool|task_chain|manual, tool, args}` |
| execution_mode | string | `auto` / `require_confirm` |
| depends_on | json | 前置 task_key 数组 |
| status | string | `pending` / `awaiting_confirm` / `running` / `done` / `failed` / `skipped` / `cancelled` |
| output | json nullable | 产出（供 `{{task.x.output}}` 引用与复盘汇总） |
| executed_at | timestamp nullable | |

## 五、调度与执行

- **`campaign:process-due`**：注册进 `SchedulerService`（every 5 min，withoutOverlapping，config 可禁用），
  扫描 `scheduled_at <= now AND status = pending AND 依赖已 done` 的任务
- **执行分派**：
  - `execution_mode = require_confirm` → 置 `awaiting_confirm`，发**异步待办通知**（复用 Notification，
    非 ActionConfirmService——后者 300 秒 TTL + 会话绑定，不适配日历场景），人批准后进入执行
  - `assignee = agent` → 派发 Job，短 ReAct 会话执行 action（AgentRuntime 现有链路，产出写回 output）
  - `assignee = human` → 生成待办，人完成后手动置 done（可附产出）
  - `action.type = task_chain` → 启动一条任务链 run，链 completed 回写任务 done
- **on_event 任务**：编译时登记监听（如 `OrderPaidEvent`），事件到达且归属该 plan 的锚定对象时触发执行
- **失败语义**：与任务链一致——失败置 `failed` 保留现场，不自动重试、不跨任务回滚，人可重试/跳过；
  单任务失败不停整个计划（fail-open）

## 六、导出 / 导入（小巧思落地）

因为 Plan 文档不含绝对时间与内部 ID，天然可移植：

- **导出**：`plan_doc` 直接序列化为 JSON/YAML 下载；`revisions` 与执行统计可选携带（默认剥离）
- **导入**：上传文档 → schema 校验（版本、工具 slug 已注册、锚点可解析、依赖无环）→ 绑定本租户锚定对象 → 进入 `planning` 供修订 → 定稿编译
- **安全降级**：导入的计划中所有 L2 工具任务**强制重置为 `require_confirm`**（不信任外来文档的 auto 预设）
- **衍生能力**：跑得好的活动一键导出成模板 → 沉淀为租户/平台级 playbook；计划文档可进 git 版本管理、跨环境迁移、diff 评审

## 七、Playbook：计划的方法论来源

Playbook 是"如何策划某类活动"的 SOP 知识（运营方法论 + 计划骨架），供 Plan 阶段 AI 生成草案：

- 注册模式照搬 `TaskChainRegistry`：`PlaybookRegistry` + `config('ai.campaign.extra_playbook_classes')`，
  下游（scrm-platform）零侵入扩展 `ScrmPlaybooks::playbooks()`
- Playbook 内容 = 方法论文本（进 SystemKb，供对话引用）+ 计划骨架（campaign.plan/v1 半成品，留待填充）
- SCRM 首批：`offline_course_launch`（线下课）、`live_course_launch`（直播课）

## 八、秘书工具契约

| slug | risk | 语义 |
|---|---|---|
| `campaign_plan_draft` | L1 | 按 playbook + 用户输入生成/修订计划草案（只改 plan_doc，不落任务） |
| `campaign_plan_commit` | **L2** | 定稿并编译落库（弹确认卡片展示完整时间轴），plan 进入 `scheduled` |
| `campaign_status` | L1 | 查询计划进度：各任务状态、待确认项、目标对照数据 |
| `campaign_plan_revise` | **L2** | 运行中修订：提交新 plan_doc → diff 预览确认 → 重编译未执行任务 |

Plan 阶段的多轮共创就是普通秘书对话 + `campaign_plan_draft` 反复调用，无需新会话形态。

## 九、与 task-chain.md 的比较与复用收益

| 维度 | 预设任务链 | 活动运营编排 |
|---|---|---|
| 时间尺度 | 分钟级，会话内 | 天/周级，跨会话 |
| 推进方 | 用户在对话中逐步 advance | 调度器到点自动触发 |
| 步骤来源 | 人预定义（代码内数据） | AI 按 playbook 生成 + 人确认（运行时数据） |
| 确认机制 | 会话内确认卡片（ActionConfirmService） | 异步待办（Notification，无 TTL） |
| 状态载体 | task_chain_runs（绑 conversation） | campaign_plans/tasks（绑 anchor 对象） |

**直接继承的模式**（不重复设计）：

1. **定义即纯数据**：链定义是 PHP 数组/JSON，Plan 是 JSON 文档——同一哲学，campaign 走得更远（运行时生成、可导出）
2. **Registry + extra_classes 下游扩展**：PlaybookRegistry 照搬 TaskChainRegistry 模式
3. **`{{key}}` 上下文占位符**：任务产出引用与链上下文引用同一语法，LLM 与开发者认知成本减半
4. **>16KB 存引用**、**失败不自动重试不跨步回滚**、**引擎关闭不影响主链路**的验收标准，逐条沿用
5. **task_chain 作为 action 类型**：campaign 触点直接复用任务链引擎执行多步触点，两个引擎组合而非竞争

**campaign 补齐 task-chain 覆盖不到的**：时间轴调度、事件触发、human 任务、异步确认、计划可移植、数据驱动修订。

## 十、分期实施与验收

**Phase 0（排期骨架）✅ 已实施**：两表迁移 + 编译器（relative/at_time）+ `campaign:process-due` + 异步待办确认门 + 手工建计划 API。
实现：`src/Modules/Campaign/`（CampaignServiceProvider / PlanCompiler / CampaignTaskExecutor /
CampaignProcessDueCommand / CampaignAdminController / CampaignTaskPendingNotification）。
开关：`AI_CAMPAIGN_ENABLED`（默认 false）；调度：SchedulerService `campaign-process-due` */5min。

**Phase 1（AI 共创）**：`campaign_plan_draft/commit` 工具 + PlaybookRegistry + 首个 playbook + 前端时间轴视图。
验收：对话中从"我要办一场线下课"到计划定稿编译全程走通，L2 定稿必出确认卡片。

**Phase 2（Run 闭环）**：on_event 编译登记（OrderPaidEvent 首个）+ agent 任务 Job 执行 + task_chain action 组合 + recurring 展开。
验收：购买后通知、开课提醒按锚点自动触发；一个触点成功启动并完成一条任务链。

**Phase 3（Review/Close + 可移植）**：复盘报告任务 + goals 对照 + 计划修订重编译 + 导出/导入（含 L2 降级）。
验收：活动结束后自动产出复盘待办；导出的计划在另一租户导入后可完整重排期。

通用验收标准：

- 编排引擎关闭（`AI_CAMPAIGN_ENABLED=false`）时，Event 模块、秘书对话、任务链完全不受影响
- 所有 plan/task 记录带 tenant_id，跨租户不可见（租户隔离铁律）
- 执行路径全部经既定 Service，编排层直接写业务表视为阻断性缺陷
- 导入计划的 auto 预设未被降级为 require_confirm 视为阻断性缺陷
