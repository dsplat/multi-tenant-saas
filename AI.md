# Loop Engineering — AI Multi-Agent Development Workflow

**Version:** v2.0  
**Status:** Active  
**Path:** `.ai/WORKFLOW.md` (建议迁移至此)

---

## 一、工具栈全景

```
工具层（3 个 Agent 工具）
┌──────────────────┬──────────────────┬──────────────────┐
│  Claude Code     │  OpenCode        │  MimoCode        │
│  claude          │  opencode        │  mimo            │
│  Reviewer        │  Developer       │  Fixer           │
│  mimo-v2.5-pro ✅│  glm-5.2 ✅     │  mimo-auto ✅    │
└──────────────────┴──────────────────┴──────────────────┘

调度层（Dispatcher，随阶段演进）
┌───────────────────────────────────────────────────────┐
│  Phase 1：Human（手动触发每步）                       │
│  Phase 2：loop-run.sh（shell 自动循环）               │
│  Phase 3：OpenCode/deepseek-r1（AI 自主调度）         │
└───────────────────────────────────────────────────────┘
```

> **Qoder 已废弃**：不支持 CLI 非交互模式，由 OpenCode 替代。  
> **MimoCode 说明**：小米出品，基于 OpenCode 框架，内置 mimo-auto（1M context，免费）。  
> **当前模型来源**：全部为国产模型（小米 / 阿里云百炼），无官方 Claude/GPT 授权。

---

## 二、模型绑定策略

**OpenCode 开发模型（代码生成）：**

```bash
# 主力：glm-5.2，均衡的代码能力
opencode run "..." -m bailian/glm-5.2

# 备选：deepseek-v3（复杂逻辑）
opencode run "..." -m bailian/deepseek-v3

# 备选：qwen3-coder-480b（大模型精细任务）
opencode run "..." -m bailian/qwen3-coder-480b
```

**Claude Code 审核模型（Review / Architect）：**

```bash
# 主力：mimo-v2.5-pro（已验证，当前默认配置）
claude -p "..."

# Claude Code 不支持自定义 OpenAI 兼容端点，模型在其自身配置中设置
# 当前已配置 mimo-v2.5-pro，Review 质量已验证 ✅
```

**MimoCode 修复模型（Refactor / Fix）：**

```bash
# 固定：mimo-auto（内置免费模型，1M 上下文）
mimo run "..."
```

**模型来源：** 阿里云百炼 (Bailian)
```bash
# 环境变量配置（不要写入代码）
export BAILIAN_API_KEY="<your-bailian-key>"
export OPENCODE_DEFAULT_MODEL="bailian/glm-5.2"
```

---

## 三、团队角色分工

**Claude Code（Architect / Reviewer）**

职责：
- Sprint Planning / Roadmap
- Task 拆分与细化
- Architecture 设计与评审
- Code Review（只评估，不修改代码）
- Merge Approval

禁止：
- 不做大规模业务开发
- 不做 Bug Fix（由 MimoCode 负责）

CLI 用法：
```bash
# 非交互式 Review
claude -p "$(cat .ai/prompts/review.md)" --output-format text

# 指定模型
claude -p "..." --model deepseek-r1-0528 --output-format text
```

---

**OpenCode（Developer）**

职责：
- Feature 开发（API / Service / Component / CRUD）
- 跟随 Task 范围开发，禁止超出 Task 边界

禁止：
- 不修改 Architecture
- 不修改无关模块或目录结构
- 不自行 Review

CLI 用法：
```bash
# 开发任务（无人值守）
opencode run "$(cat .ai/tasks/TASK-0001.md)" \
    -m bailian/glm-5.2 \
    --dangerously-skip-permissions \
    --dir $PROJECT_DIR

# 继续上次会话
opencode run "继续完成上次的任务" --continue -m bailian/glm-5.2
```

---

**MimoCode（Refactor Engineer）**

职责：
- 基于 Review 意见进行 Bug Fix
- ESLint / Type Error 修复
- 代码清理与小范围重构

禁止：
- 不新增需求
- 不修改产品设计
- 不改变 API 契约

CLI 用法：
```bash
# 修复任务（无人值守）
mimo run "$(cat .ai/review/TASK-0001-review.md)" \
    --dangerously-skip-permissions \
    --dir $PROJECT_DIR
```

---

**Human（Dispatcher — Phase 1）**

职责：
- 读取 Task 状态
- 决定下一阶段执行者
- 传递上一阶段输出给下一阶段
- 触发脚本并检查 DoD

禁止：
- 不参与开发、Review、设计
- 不修改代码逻辑
- Phase 2 后逐步退出

---

## 四、Loop Engineering 核心架构

**三工具协作模型：**

```
              [ Dispatcher ]                  ← 调度层（Phase 1: Human）
                    │                            （Phase 2: loop-run.sh）
       ┌────────────┼────────────┐               （Phase 3: OpenCode/AI）
       ↓            ↓            ↓
  Claude Code    OpenCode    MimoCode
  Reviewer       Developer    Fixer
  mimo-v2.5-pro  glm-5.2     mimo-auto
       │            │            │
       │←───────────┘            │
       │   ① 读取 git diff       │
       │   ② 输出 Review 报告   │
       │                         │
       └─────────────────────────┘
         FAIL → 把 Review 报告交给 Fixer
         PASS → Dispatcher 标记 DONE
```

**完整 Loop 流程：**

```
Human → 设定 Goal
    ↓
Claude Code → PLAN（Task 拆分 + 架构约束）
    ↓
┌─────────────────────── LOOP（最多 3 次）────────────────┐
│                                                         │
│  OpenCode/glm-5.2 → DEV                                │
│      ↓（git diff 变更）                                 │
│  Claude Code/mimo → REVIEW                             │
│      ↓                                                  │
│   PASS? ─── Yes ──→ 退出循环 ✓                        │
│      │                                                  │
│      No（写入 .ai/review/TASK-XXX-review.md）          │
│      ↓                                                  │
│  MimoCode/mimo-auto → FIX                              │
│      └─────────────────────────────────────────────────┘
│         （回到 REVIEW，loop++ ）
│
│  loop > 3 → ESCALATE → 标记 BLOCKED，人工介入          │
└─────────────────────────────────────────────────────────┘
    ↓
Human → 验证 DoD → DONE
```

**各工具不直接通信，只通过文件系统共享状态：**

| 传递内容 | 文件路径 |
|---------|--------|
| Task 描述 | `.ai/tasks/TASK-XXXX.md` |
| Review 报告 | `.ai/review/TASK-XXXX-review.md` |
| 任务状态 | `.ai/state.json` |
| 代码变更 | `git diff`（由 Reviewer 读取） |

---

## 五、自循环脚本（Phase 2 实现）

三个工具均支持非交互式 CLI，自循环脚本可完全自动化：

```bash
#!/bin/bash
# .ai/scripts/loop-run.sh
# 使用: ./loop-run.sh TASK-0001

TASK_ID="$1"
TASK_FILE=".ai/tasks/${TASK_ID}.md"
REVIEW_FILE=".ai/review/${TASK_ID}-review.md"
MAX_LOOPS=3
loop=0

echo "[loop-run] Starting $TASK_ID"

# Step 1: Dev
opencode run "$(cat $TASK_FILE)" \
    -m bailian/glm-5.2 \
    --dangerously-skip-permissions \
    --title "$TASK_ID-dev"

while [ $loop -lt $MAX_LOOPS ]; do
    echo "[loop-run] Review round $((loop+1))"

    # Step 2: Review
    claude -p "$(cat .ai/prompts/review-prompt.md)

Task: $TASK_ID
$(git diff HEAD~1)" \
        --output-format text > "$REVIEW_FILE"

    # Step 3: 判断结果
    if grep -A1 "^## Verdict" "$REVIEW_FILE" | grep -q "PASS"; then
        echo "[loop-run] PASS — $TASK_ID done"
        exit 0
    fi

    echo "[loop-run] FAIL — sending to MimoCode for fix"

    # Step 4: Fix
    mimo run "根据以下 Review 意见修复代码，禁止新增需求：

$(cat $REVIEW_FILE)" \
        --dangerously-skip-permissions

    loop=$((loop + 1))
done

# 超出最大循环
echo "[loop-run] ESCALATE — $TASK_ID exceeded max loops, needs human review"
exit 1
```

**运行示例：**
```bash
chmod +x .ai/scripts/loop-run.sh
.ai/scripts/loop-run.sh TASK-0001
```

---

## 六、任务生命周期

```
NEW → PLAN → READY → DEV → REVIEW ↔ FIX_REQUESTED → TEST → DONE
                               ↓
                            BLOCKED
```

| 状态 | 含义 | Phase 1 执行者 | Phase 2 执行者 |
|------|------|--------------|--------------|
| NEW | 新需求，未规划 | Human | Human |
| PLAN | Claude 已拆分 Task | Claude Code | Claude Code |
| READY | 可开发 | Human 确认 | 自动 |
| DEV | 开发中 | OpenCode/GLM-5.2 | 自动 |
| REVIEW | 等待审核 | Claude Code | 自动 |
| FIX_REQUESTED | 需修复 | MimoCode | 自动 |
| TEST | 最终确认 | Human | Human |
| BLOCKED | 被阻塞 | Human | Human |
| DONE | 完成 | Human | Human |

---

## 七、标准工作流程（Phase 1 手动版）

```
1. Human → 提出需求
   Status: NEW → PLAN

2. Claude Code → 规划 Task
   claude -p "$(cat .ai/prompts/plan-prompt.md)" > .ai/sprints/sprint-001.md
   Status: PLAN → READY

3. Human → 选择 TASK，运行开发
   opencode run "$(cat .ai/tasks/TASK-0001.md)" -m bailian/glm-5.2 --dangerously-skip-permissions
   Status: READY → DEV

4. Human → 触发 Review
   claude -p "$(cat .ai/prompts/review-prompt.md)" --output-format text > .ai/review/TASK-0001-review.md
   Status: DEV → REVIEW

5. Review 结果: FAIL → Status: FIX_REQUESTED

6. Human → 触发 MimoCode 修复
   mimo run "$(cat .ai/review/TASK-0001-review.md)" --dangerously-skip-permissions
   Status: FIX_REQUESTED → REVIEW

7. 二审 PASS → Status: TEST → DoD 检查 → DONE

# Phase 2: 步骤 3-6 全部由 loop-run.sh 自动完成
```

---

## 八、异常处理

**Review 反复失败（> 3 轮）**
```
现象: FIX_REQUESTED → REVIEW 重复超过 3 次
处理: 标记 BLOCKED → Claude 重新评估架构 → 拆细 or 标记技术债
```

**DEV 超时**
```
现象: 超过预估时间 2 倍
处理: 检查是否范围蔓延 → 拆细 Task → rollback 重来
```

**需求变更**
```
处理: 原 Task 不动，新需求开新 Task → 下一 Sprint
     禁止在进行中的 Task 内加需求
```

**依赖阻塞**
```
处理: 两个 Task 都标记 BLOCKED → 先完成依赖 Task
     或评估能否 mock 依赖，先行开发
```

---

## 九、目录结构

```
.ai/
├── WORKFLOW.md             # 本文档
├── scripts/
│   ├── loop-run.sh         # 自循环执行脚本
│   └── plan-task.sh        # Claude 规划脚本
├── prompts/
│   ├── review-prompt.md    # Claude Review 标准 Prompt
│   ├── dev-prompt.md       # OpenCode 开发 Prompt 模板
│   └── fix-prompt.md       # MimoCode 修复 Prompt 模板
├── sprints/
│   ├── sprint-001.md
│   └── ...
├── tasks/
│   ├── TASK-0001.md
│   └── ...
├── review/
│   ├── TASK-0001-review.md
│   └── ...
├── reports/
│   └── sprint-001-report.md
└── state.json              # 所有 Task 状态快照
```

**TASK-XXXX.md 模板：**
```markdown
# TASK-XXXX: [Feature Name]

## 目标
[明确输出物]

## 范围
只允许修改:
- src/modules/auth/

禁止:
- 修改其他模块
- 修改数据库 Schema
- 新增依赖包

## 依赖
- 需要 TASK-YYYY 先完成（或无依赖）

## 预期输出
- 功能可运行
- 单元测试覆盖 ≥ 80%
- 文档已更新

## 预估时间
2 小时

## 状态
READY
```

---

## 十、Prompt 模板

**`.ai/prompts/review-prompt.md`（Claude Code Review 用）：**
```
请 Review 以下代码变更，只评估不修改代码。

## Architecture
[架构合理性评估]

## Code Quality
[命名/可维护性/重复代码]

## Type Safety
[TypeScript 类型安全]

## Security
[OWASP Top 10 相关风险]

## Performance
[性能隐患]

## Potential Bugs
[潜在 Bug]

## Verdict
PASS 或 FAIL（如果 FAIL，列出必须修复的问题）
```

**`.ai/prompts/dev-prompt.md`（OpenCode 开发用）：**
```
完成以下 Task，严格遵守范围限制。

[TASK 内容]

完成后输出：
1. 修改了哪些文件
2. 每处修改的原因
3. 是否有遗留问题

不要 Review，不要超出范围。
```

---

## 十一、Git 规范

一个 Task 对应一个 Commit：

```
feat(auth): login api [TASK-0001]
feat(auth): jwt middleware [TASK-0002]
fix(auth): token refresh edge case [TASK-0003]
refactor(auth): extract auth service [TASK-0004]
```

禁止：一个 Commit 包含多个 Feature。

---

## 十二、Definition of Done

DONE 前必须全部满足：

- [ ] 代码构建成功
- [ ] Type Check 无错误
- [ ] ESLint 无警告
- [ ] 单元测试通过（覆盖 ≥ 80%）
- [ ] Claude Code Review PASS
- [ ] 文档已更新
- [ ] Git Commit 完成
- [ ] 无 console.log / debug 代码残留
- [ ] 无注释掉的废弃代码

---

## 十三、演进路线

```
Phase 1（当前）
  Human Dispatcher 手动触发每个步骤
  三个工具分别手动调用
  shell 脚本辅助减少重复操作

Phase 2（近期）
  loop-run.sh 自动化 DEV → REVIEW → FIX 循环
  Human 只处理 BLOCKED 和最终 DONE 确认
  OpenCode multi-session 并行处理多个 Task

Phase 3（未来）
  OpenCode 作为 AI Dispatcher
  自动读取 state.json → 分配 Task → 触发循环
  Human 只设定 Goal 和处理异常
  完整无人值守工程流水线
```

---

## 十四、核心原则

1. **Workflow 高于工具** — 工具可替换，流程不变
2. **Task 高于 Prompt** — 清晰的 Task 定义 > 精妙的 Prompt
3. **状态驱动** — 靠 state.json，不靠人工记忆
4. **Loop 收敛** — Review 是质量门控，不是一次性流程
5. **单一职责** — 每个 Agent 只做自己的角色
6. **原子 Commit** — 一个 Task 一个 Commit
7. **渐进自动化** — Phase 1 → 2 → 3，不是一步到位
