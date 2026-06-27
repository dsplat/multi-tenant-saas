#!/bin/bash
# =============================================================================
# loop-run.sh — Loop Engineering 自循环执行脚本
# 用法: .ai/scripts/loop-run.sh TASK-0001
#
# 流程: OpenCode(DEV) → Claude(REVIEW) → MimoCode(FIX) → 循环至 PASS
# =============================================================================

set -euo pipefail

TASK_ID="${1:?用法: $0 TASK-ID（如 TASK-0001）}"
PROJECT_DIR="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
TASK_FILE="$PROJECT_DIR/.ai/tasks/${TASK_ID}.md"
REVIEW_FILE="$PROJECT_DIR/.ai/review/${TASK_ID}-review.md"
REVIEW_PROMPT="$PROJECT_DIR/.ai/prompts/review-prompt.md"
DEV_PROMPT="$PROJECT_DIR/.ai/prompts/dev-prompt.md"
MAX_LOOPS=3

# 颜色输出
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()    { echo -e "${NC}[loop-run] $*"; }
ok()     { echo -e "${GREEN}[loop-run] ✓ $*${NC}"; }
warn()   { echo -e "${YELLOW}[loop-run] ⚠ $*${NC}"; }
fail()   { echo -e "${RED}[loop-run] ✗ $*${NC}"; }

# 前置检查
[[ -f "$TASK_FILE" ]]         || { fail "Task 文件不存在: $TASK_FILE"; exit 1; }
[[ -f "$REVIEW_PROMPT" ]]     || { fail "Review prompt 不存在: $REVIEW_PROMPT"; exit 1; }
[[ -f "$DEV_PROMPT" ]]        || { fail "Dev prompt 不存在: $DEV_PROMPT"; exit 1; }
command -v opencode &>/dev/null || { fail "opencode 未安装"; exit 1; }
command -v claude   &>/dev/null || { fail "claude 未安装"; exit 1; }
command -v mimo     &>/dev/null || { fail "mimo 未安装"; exit 1; }

# 更新 state.json
update_state() {
    local status="$1"
    local state_file="$PROJECT_DIR/.ai/state.json"
    python3 - <<PYEOF
import json, datetime
try:
    with open("$state_file") as f:
        data = json.load(f)
except:
    data = {"tasks": []}

tasks = data.get("tasks", [])
found = False
for t in tasks:
    if t["id"] == "$TASK_ID":
        t["status"] = "$status"
        t["updated"] = datetime.datetime.now().isoformat()
        found = True
        break
if not found:
    tasks.append({"id": "$TASK_ID", "status": "$status", "updated": datetime.datetime.now().isoformat()})
data["tasks"] = tasks
with open("$state_file", "w") as f:
    json.dump(data, f, indent=2, ensure_ascii=False)
print(f"state updated: $TASK_ID → $status")
PYEOF
}

# =============================================================================
# STEP 1: DEV — OpenCode + glm-5.2
# =============================================================================
log "=== STEP 1: DEV ($TASK_ID) ==="
update_state "DEV"

# 记录 DEV 前的 commit，用于后续 diff
GIT_BASE=$(git rev-parse HEAD 2>/dev/null || echo "")

opencode run "$(cat "$DEV_PROMPT")

---
$(cat "$TASK_FILE")" \
    -m bailian/glm-5.2 \
    --dangerously-skip-permissions \
    --dir "$PROJECT_DIR" \
    --title "$TASK_ID-dev"

ok "DEV 完成"

# =============================================================================
# REVIEW ↔ FIX LOOP
# =============================================================================
loop=0
while [[ $loop -lt $MAX_LOOPS ]]; do
    log "=== STEP 2: REVIEW 第 $((loop+1)) 轮 ==="
    update_state "REVIEW"

    # 获取代码变更（含未提交变更，相对 DEV 前的 base commit）
    if [[ -n "$GIT_BASE" ]]; then
        DIFF=$(git diff "$GIT_BASE" 2>/dev/null || echo "（无 git diff 可用）")
    else
        DIFF=$(git diff 2>/dev/null || echo "（无 git diff 可用）")
    fi

    if [[ -z "$DIFF" ]]; then
        warn "git diff 为空，代码可能未发生变更"
    fi

    # Claude Code (mimo-v2.5-pro) 执行 Review
    claude -p "$(cat "$REVIEW_PROMPT")

---
## Task
$(cat "$TASK_FILE")

---
## Code Changes
\`\`\`diff
$DIFF
\`\`\`" \
        --output-format text > "$REVIEW_FILE" 2>&1

    log "Review 结果写入: $REVIEW_FILE"
    cat "$REVIEW_FILE"
    echo ""

    # 判断 PASS / FAIL
    if grep -A1 "^## Verdict" "$REVIEW_FILE" | grep -q "PASS"; then
        ok "=== REVIEW PASS — $TASK_ID 完成 ==="
        update_state "TEST"
        exit 0
    fi

    warn "REVIEW FAIL — 进入第 $((loop+1)) 次修复"
    update_state "FIX_REQUESTED"

    # ==========================================================================
    # STEP 3: FIX — MimoCode + mimo-auto
    # ==========================================================================
    log "=== STEP 3: FIX 第 $((loop+1)) 轮 ==="

    mimo run "根据附件中的 Review 报告修复代码。

【要求】
- 只修复 Review 中明确列出的问题
- 禁止新增需求
- 禁止修改 Architecture
- 禁止修改无关模块" \
        -f "$REVIEW_FILE" \
        --dangerously-skip-permissions \
        --dir "$PROJECT_DIR"

    ok "FIX 完成，重新进入 REVIEW"
    loop=$((loop + 1))
done

# =============================================================================
# 超出最大循环 → ESCALATE
# =============================================================================
fail "=== ESCALATE — ${TASK_ID} 已循环 ${MAX_LOOPS} 次仍未通过 ==="
update_state "BLOCKED"

# AUTO_SPLIT=1 时，自动调用 claude 拆分并并行执行子任务
if [[ "${AUTO_SPLIT:-0}" == "1" ]]; then
    warn "AUTO_SPLIT 已启用，尝试自动拆分并执行..."
    SPLIT_PROMPT_FILE="$PROJECT_DIR/.ai/prompts/split-prompt.md"

    SPLIT_OUTPUT=$(claude -p "以下是 Review 报告：

$(cat "${REVIEW_FILE}")

---
该 Task 循环 ${MAX_LOOPS} 次 Review 仍未通过，需要拆分。

请将剩余问题拆分为 2-3 个独立的子任务，每个子任务：
- 只涉及 1-3 个文件
- 不超过 2 小时
- 文件范围互不重叠（避免 git 冲突）

输出格式（严格按此格式，每个 SUBTASK 块之间空行分隔）：

SUBTASK: ${TASK_ID}a
目标: [一句话目标]
只允许修改:
- [文件路径1]
- [文件路径2]
禁止: 修改其他文件、新增依赖
预估时间: [X] 小时

SUBTASK: ${TASK_ID}b
目标: [一句话目标]
只允许修改:
- [文件路径]
禁止: 修改其他文件、新增依赖
预估时间: [X] 小时" \
        --output-format text 2>/dev/null)

    if [[ -z "$SPLIT_OUTPUT" ]]; then
        warn "claude 未返回有效拆分结果，退出 AUTO_SPLIT"
    else
        log "claude 拆分输出："
        echo "$SPLIT_OUTPUT"
        echo ""

        # 解析 SUBTASK 块，生成子任务文件
        SUBTASK_IDS=()
        CURRENT_ID=""
        CURRENT_CONTENT=""

        while IFS= read -r line; do
            if [[ "$line" =~ ^SUBTASK:[[:space:]]*(.+) ]]; then
                # 保存上一个块
                if [[ -n "$CURRENT_ID" && -n "$CURRENT_CONTENT" ]]; then
                    SUBTASK_FILE="$PROJECT_DIR/.ai/tasks/${CURRENT_ID}.md"
                    printf "# %s: [Auto-split from %s]\n\n%s\n\n## 状态\nREADY\n" \
                        "$CURRENT_ID" "$TASK_ID" "$CURRENT_CONTENT" > "$SUBTASK_FILE"
                    ok "生成子任务: ${SUBTASK_FILE}"
                    SUBTASK_IDS+=("$CURRENT_ID")
                fi
                CURRENT_ID="${BASH_REMATCH[1]}"
                CURRENT_CONTENT=""
            else
                CURRENT_CONTENT+="$line"$'\n'
            fi
        done <<< "$SPLIT_OUTPUT"

        # 保存最后一个块
        if [[ -n "$CURRENT_ID" && -n "$CURRENT_CONTENT" ]]; then
            SUBTASK_FILE="$PROJECT_DIR/.ai/tasks/${CURRENT_ID}.md"
            printf "# %s: [Auto-split from %s]\n\n%s\n\n## 状态\nREADY\n" \
                "$CURRENT_ID" "$TASK_ID" "$CURRENT_CONTENT" > "$SUBTASK_FILE"
            ok "生成子任务: ${SUBTASK_FILE}"
            SUBTASK_IDS+=("$CURRENT_ID")
        fi

        if [[ ${#SUBTASK_IDS[@]} -gt 0 ]]; then
            ok "共生成 ${#SUBTASK_IDS[@]} 个子任务: ${SUBTASK_IDS[*]}"
            log "并行执行子任务..."
            exec "$PROJECT_DIR/.ai/scripts/parallel-run.sh" "${SUBTASK_IDS[@]}"
        else
            warn "未能解析出有效子任务，回退到人工处理"
        fi
    fi
fi

# 人工介入提示
echo ""
echo "介入点："
echo "  查看最后一次 Review 报告： ${REVIEW_FILE}"
echo ""
echo "处理方式（三选一）："
echo "  A. 可修复：手动单次修复"
echo "     mimo run '修复全部问题' -f ${REVIEW_FILE} --dangerously-skip-permissions"
echo ""
echo "  B. 架构问题：Claude Code 重新规划"
echo "     claude -p \"以下是 Review 报告：\\n\\n\$(cat ${REVIEW_FILE})\\n\\n请分析为何反复失败，将问题拆分为 2-3 个小 Task。\" --output-format text"
echo ""
echo "  C. 自动拆分并执行（下次运行时）："
echo "     AUTO_SPLIT=1 .ai/scripts/loop-run.sh ${TASK_ID}"

