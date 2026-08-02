# 待办清单

> 跨迭代遗留项与待决策项跟踪。已完成的条目移入文末「已完成归档」。
> 更新时间：2026-08-01

---

## 一、项目大脑（Thread/Brain）遗留项

> 背景：Phase 0-3 已全部上线（框架 5e8b2ff / scrm 816bf6e），E2E 场景 0-3 验收通过。
> 主闭环（策划→定稿→自动跟踪→巡检→主动提醒）完整可用，以下为边缘增强项。

### TODO-BRAIN-001: thread_track 主动提议积极性调优

**优先级**: 低（有手动兜底）

**现状**: 工具已上线且在秘书模板中，用户明确说"帮我持续跟进"即可触发确认卡片。但 E2E 场景 2 中 AI 对建议事项仅给文本列表，未主动提议建立跟踪。

**影响面**: 仅"未走定稿流程的松散事项"——AI 不主动提议则不进入巡检与摘要注入范围；已定稿计划自动 tracked 不受影响。

**方向**: 秘书模板 system_prompt 引导词调优（识别出值得跟进的事项时更积极地提议 thread_track）。等真实使用数据积累后再调，避免过度提议骚扰用户。

---

### TODO-BRAIN-002: Phase 4 会话-脉络精确关联

**优先级**: 中（等前三层运行数据验证后启动）

**现状**: AI 跨会话"事实记忆"完整（计划/任务/健康状态靠摘要注入 + thread_review）；但"对话语境记忆"缺失——上次会话口头说的约束（如预算、渠道偏好）若未落入 plan_doc，新会话不知道。关联会话摘要目前靠主题匹配，可能漏配。

**方案**（计划原文 Phase 4）:
- conversation.metadata 记锚点引用（anchor_type/anchor_id 或 plan_id）
- resolve 附同脉络最近会话 summary，实现"接着上次聊"
- thread_review 的关联会话摘要从主题匹配升级为精确关联

---

### TODO-BRAIN-003: 巡检异常 LLM 分析增强（默认关闭）

**优先级**: 低

**现状**: `thread:health-check` 纯规则零 LLM 已上线；`ai.brain.background_reasoning` 开关与 brain 计费通道设计已定但未实现。

**方案**: 巡检发现异常的脉络追加一次 LLM 分析（推断遗漏/生成建议存入 metadata.health.suggestions），走独立 `brain` 计费通道：用量记真实 tenant_id（scenario=brain），计费主体可配（`ai.brain.billing` = platform/tenant），硬限额每脉络每日一次 + 单次 token 上限。

---

### TODO-BRAIN-004: capability-map 覆盖面扩展

**优先级**: 低（渐进式）

**现状**: 首版 next-actions 段落覆盖 Event 相关模块（Campaign/Poster/MassMessage/Coupon 链路）。

**方向**: 其余 scrm 模块按需在各自 `resources/kb/` 补 next-actions 约定段落，`secretary:kb:index` 部署时自动收录，零中心化配置。

---

## 二、历史待决策项

### TODO-SEC-001: 18 个 SCRM L2 工具是否挂载系统小秘书

**优先级**: 待产品决策

**现状**: 系统小秘书仅挂 3 个 L2 试点工具（tag_customer / create_script_draft / save_oauth_config）+ 本轮新增的 campaign_plan_commit / thread_track / thread_untrack；其余 SCRM L2 工具未挂。L2 确认门（流式 + IM 文本确认）已全链路可用，挂载无技术障碍。

---

## 已完成归档

- ✅ 2026-07-31 项目大脑 Phase 0-3 全量上线 + E2E 场景 0-3 验收（L2 确认门 / capability-map / 摘要注入 / thread_review / AssetProbe / health-check）
- ✅ 2026-07-31 tool_calls 落库格式 400 修复（normalizeToolCalls + reconcileToolCallPairs 配对裁剪，框架 5323880）
- ✅ 2026-07-31 锚点预检修复（collectRequiredAnchors 一次性预检 + missing_anchors 结构化返回 + draft 单锚点纪律，框架 5e8b2ff）
