#!/bin/bash
# =============================================================================
# loop-run.sh — Loop Engineering 自循环执行脚本
# 用法: .ai/scripts/loop-run.sh TASK-0001
#       AUTO_SPLIT=1 .ai/scripts/loop-run.sh TASK-0001  # 启用拆分
#
# 流程: Pre-split(可选) → OpenCode(DEV) → Claude(REVIEW) → MimoCode(FIX) → 循环至 PASS
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
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
log()    { echo -e "${NC}[loop-run] $*"; }
ok()     { echo -e "${GREEN}[loop-run] ✓ $*${NC}"; }
warn()   { echo -e "${YELLOW}[loop-run] ⚠ $*${NC}"; }
fail()   { echo -e "${RED}[loop-run] ✗ $*${NC}"; }
info()   { echo -e "${CYAN}[loop-run] $*${NC}"; }

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
# 拆分函数
# =============================================================================

# 检测 Task 是否需要 pre-split
# 条件: AUTO_SPLIT=1 且 Task 文件包含 auto_split/Auto-split 标记 且非子任务
should_pre_split() {
    # 子任务（ID 以小写字母结尾）不再拆分
    [[ "$TASK_ID" =~ [a-z]$ ]] && return 1

    # 检查 AUTO_SPLIT 环境变量
    [[ "${AUTO_SPLIT:-0}" != "1" ]] && return 1

    # 检查 Task 文件是否标记 auto_split
    if grep -qiE "auto[._-]?split.*on|auto[._-]?split.*true" "$TASK_FILE" 2>/dev/null; then
        return 0
    fi
    return 1
}

# 解析子任务文件中的"只允许修改"文件列表
parse_subtask_files() {
    local subtask_file="$1"
    local in_section=false
    local files=()

    while IFS= read -r line; do
        if [[ "$line" =~ ^只允许修改 ]]; then
            in_section=true
            continue
        fi
        if [[ "$in_section" == "true" ]]; then
            # 遇到非列表行（不以 - 开头）则结束
            if [[ "$line" =~ ^-[[:space:]]*(.+) ]]; then
                local f="${BASH_REMATCH[1]}"
                # 去掉尾部注释和空格
                f=$(echo "$f" | sed 's/#.*//' | xargs)
                [[ -n "$f" ]] && files+=("$f")
            elif [[ "$line" =~ ^[^[:space:]-] ]]; then
                break
            fi
        fi
    done < "$subtask_file"

    printf '%s\n' "${files[@]}"
}

# 验证子任务文件是否重叠
# 返回 0=无冲突, 1=有冲突
validate_split() {
    local -n _subtask_ids=$1
    local -A file_owners=()
    local has_conflict=false

    for sid in "${_subtask_ids[@]}"; do
        local stf="$PROJECT_DIR/.ai/tasks/${sid}.md"
        [[ -f "$stf" ]] || continue

        while IFS= read -r filepath; do
            [[ -z "$filepath" ]] && continue
            if [[ -n "${file_owners[$filepath]:-}" ]]; then
                fail "文件冲突: $filepath 同时在 ${file_owners[$filepath]} 和 $sid 中"
                has_conflict=true
            else
                file_owners[$filepath]="$sid"
            fi
        done < <(parse_subtask_files "$stf")
    done

    if [[ "$has_conflict" == "true" ]]; then
        return 1
    fi
    ok "文件冲突检测通过：${#file_owners[@]} 个文件均无重叠"
    return 0
}

# 执行拆分（pre-split 用原始 Task 文件，post-fail 用 Review 报告）
# 参数: $1 = "pre" 或 "post"
do_split() {
    local mode="${1:-post}"
    local split_context=""
    local split_instruction=""

    if [[ "$mode" == "pre" ]]; then
        info "Pre-split 模式：使用原始 Task 定义拆分"
        split_context="以下是完整的 Task 定义：

$(cat "$TASK_FILE")"
        split_instruction="请将此 Task 拆分为 2-4 个独立的子任务，按 Phase/步骤边界拆分。"
    else
        info "Post-fail 拆分：使用 Review 报告拆分"
        split_context="以下是 Review 报告：

$(cat "${REVIEW_FILE}")

---
该 Task 循环 ${MAX_LOOPS} 次 Review 仍未通过，需要拆分。"
        split_instruction="请将剩余问题拆分为 2-3 个独立的子任务。"
    fi

    local split_prompt="你是任务拆分专家。${split_context}

---
${split_instruction}

【硬性约束】
1. 每个子任务只涉及 1-3 个文件
2. 文件范围必须互不重叠：同一文件绝对不能出现在两个子任务中
3. 每个子任务不超过 2 小时
4. 有依赖关系的子任务标注依赖（如 依赖: TASK-004a）

【文件依赖分析】
拆分前，先分析各文件之间的依赖关系：
- 哪些文件互相 import/use？
- 哪些文件共享同一个 Model 或 Service？
- 哪些文件必须一起修改才能通过测试？
→ 有强依赖的文件必须放在同一个子任务中

【冲突自检】
拆分后，列出每个子任务的文件列表，逐一检查是否有重叠。
如果发现同一文件出现在多个子任务中 → 重新调整拆分方案。

输出格式（严格按此格式，每个 SUBTASK 块之间空行分隔）：

## 文件依赖分析
[简要分析]

## 冲突自检
TASK_IDa: file1.php, file2.php
TASK_IDb: file3.php
结论: 无重叠 ✓

SUBTASK: ${TASK_ID}a
目标: [一句话目标]
只允许修改:
- [文件路径1]
- [文件路径2]
禁止: 修改其他文件、新增依赖
预估时间: [X] 小时
依赖: 无

SUBTASK: ${TASK_ID}b
目标: [一句话目标]
只允许修改:
- [文件路径]
禁止: 修改其他文件、新增依赖
预估时间: [X] 小时
依赖: 无（或 ${TASK_ID}a）"

    local split_output
    split_output=$(claude -p "$split_prompt" --output-format text 2>/dev/null || echo "")

    if [[ -z "$split_output" ]]; then
        fail "claude 未返回有效拆分结果"
        return 1
    fi

    log "claude 拆分输出："
    echo "$split_output"
    echo ""

    # 解析 SUBTASK 块，生成子任务文件
    local subtask_ids=()
    local current_id=""
    local current_content=""

    while IFS= read -r line; do
        if [[ "$line" =~ ^SUBTASK:[[:space:]]*(.+) ]]; then
            # 保存上一个块
            if [[ -n "$current_id" && -n "$current_content" ]]; then
                local stf="$PROJECT_DIR/.ai/tasks/${current_id}.md"
                printf "# %s: [Auto-split from %s]\n\n%s\n\n## 状态\nREADY\n" \
                    "$current_id" "$TASK_ID" "$current_content" > "$stf"
                ok "生成子任务: ${stf}"
                subtask_ids+=("$current_id")
            fi
            current_id="${BASH_REMATCH[1]}"
            current_content=""
        else
            # 跳过 ## 文件依赖分析 等分析块，只收集 SUBTASK 块内容
            if [[ -n "$current_id" ]]; then
                current_content+="$line"$'\n'
            fi
        fi
    done <<< "$split_output"

    # 保存最后一个块
    if [[ -n "$current_id" && -n "$current_content" ]]; then
        local stf="$PROJECT_DIR/.ai/tasks/${current_id}.md"
        printf "# %s: [Auto-split from %s]\n\n%s\n\n## 状态\nREADY\n" \
            "$current_id" "$TASK_ID" "$current_content" > "$stf"
        ok "生成子任务: ${stf}"
        subtask_ids+=("$current_id")
    fi

    if [[ ${#subtask_ids[@]} -eq 0 ]]; then
        fail "未能解析出有效子任务"
        return 1
    fi

    ok "共生成 ${#subtask_ids[@]} 个子任务: ${subtask_ids[*]}"

    # 文件冲突检测
    if ! validate_split subtask_ids; then
        fail "文件冲突检测失败，尝试重新拆分（1 次）..."
        local retry_output
        retry_output=$(claude -p "上一次拆分存在文件冲突，请重新拆分。

原始 Task:
$(cat "$TASK_FILE")

上一次拆分结果（有问题）:
$split_output

问题：某些文件同时出现在多个子任务中。
请重新拆分，确保每个文件只属于一个子任务。

输出格式同上：SUBTASK 块 + 只允许修改 + 文件列表" --output-format text 2>/dev/null || echo "")

        if [[ -n "$retry_output" ]]; then
            # 重新解析
            subtask_ids=()
            current_id=""
            current_content=""
            while IFS= read -r line; do
                if [[ "$line" =~ ^SUBTASK:[[:space:]]*(.+) ]]; then
                    if [[ -n "$current_id" && -n "$current_content" ]]; then
                        local stf="$PROJECT_DIR/.ai/tasks/${current_id}.md"
                        printf "# %s: [Auto-split from %s (retry)]\n\n%s\n\n## 状态\nREADY\n" \
                            "$current_id" "$TASK_ID" "$current_content" > "$stf"
                        subtask_ids+=("$current_id")
                    fi
                    current_id="${BASH_REMATCH[1]}"
                    current_content=""
                else
                    if [[ -n "$current_id" ]]; then
                        current_content+="$line"$'\n'
                    fi
                fi
            done <<< "$retry_output"
            if [[ -n "$current_id" && -n "$current_content" ]]; then
                local stf="$PROJECT_DIR/.ai/tasks/${current_id}.md"
                printf "# %s: [Auto-split from %s (retry)]\n\n%s\n\n## 状态\nREADY\n" \
                    "$current_id" "$TASK_ID" "$current_content" > "$stf"
                subtask_ids+=("$current_id")
            fi

            if [[ ${#subtask_ids[@]} -gt 0 ]] && validate_split subtask_ids; then
                ok "重新拆分成功，文件无冲突"
            else
                fail "重新拆分仍有冲突，回退到人工处理"
                return 1
            fi
        else
            fail "重新拆分无输出，回退到人工处理"
            return 1
        fi
    fi

    # 输出文件归属表
    echo ""
    info "文件归属表："
    for sid in "${subtask_ids[@]}"; do
        local stf="$PROJECT_DIR/.ai/tasks/${sid}.md"
        local files
        files=$(parse_subtask_files "$stf" | tr '\n' ' ')
        echo "  $sid: $files"
    done
    echo ""

    # 并行执行
    log "并行执行子任务..."
    exec "$PROJECT_DIR/.ai/scripts/parallel-run.sh" "${subtask_ids[@]}"
}

# =============================================================================
# Pre-split 检测（在 DEV 之前）
# =============================================================================

if should_pre_split; then
    warn "检测到 auto_split=ON，执行 pre-split..."
    update_state "SPLITTING"
    do_split "pre"
    exit $?
fi

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

    # Claude Code 执行 Review
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
# 子任务（ID 以小写字母结尾）不再拆分，防止级联
if [[ "${AUTO_SPLIT:-0}" == "1" ]] && ! [[ "$TASK_ID" =~ [a-z]$ ]]; then
    warn "AUTO_SPLIT 已启用，尝试 post-fail 拆分并执行..."
    do_split "post" || true
elif [[ "${AUTO_SPLIT:-0}" == "1" ]] && [[ "$TASK_ID" =~ [a-z]$ ]]; then
    warn "子任务 $TASK_ID 不再自动拆分（防止级联），需人工介入"
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
echo "     claude -p \"以下是 Review 报告：

\$(cat ${REVIEW_FILE})

请分析为何反复失败，将问题拆分为 2-3 个小 Task。\" --output-format text"
echo ""
echo "  C. 自动拆分并执行（下次运行时）："
echo "     AUTO_SPLIT=1 .ai/scripts/loop-run.sh ${TASK_ID}"
