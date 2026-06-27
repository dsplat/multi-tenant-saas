#!/bin/bash
# =============================================================================
# parallel-run.sh — 并行执行多个 Task，各自输出到独立日志
# 用法: .ai/scripts/parallel-run.sh TASK-001a TASK-001b [TASK-001c...]
#
# 改进：
#   1. 启动前强制文件冲突检测，有重叠直接拒绝执行
#   2. 并行启动后自动启动 loop-watch.sh 交替监控日志
# =============================================================================

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
REPORTS_DIR="$PROJECT_DIR/.ai/reports"

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()   { echo -e "${GREEN}✓ $*${NC}"; }
fail() { echo -e "${RED}✗ $*${NC}"; }
info() { echo -e "${CYAN}[parallel] $*${NC}"; }
warn() { echo -e "${YELLOW}⚠ $*${NC}"; }

[[ $# -eq 0 ]] && { echo "用法: $0 TASK-001a TASK-001b [...]"; exit 1; }

mkdir -p "$REPORTS_DIR"

TASKS=("$@")

# =============================================================================
# 文件冲突检测（强制，不可跳过）
# =============================================================================

# 复用 loop-run.sh 中的 parse_subtask_files 逻辑
parse_files() {
    local subtask_file="$1"
    local in_section=false
    local files=()

    while IFS= read -r line; do
        if [[ "$line" =~ ^只允许修改 ]]; then
            in_section=true
            continue
        fi
        if [[ "$in_section" == "true" ]]; then
            if [[ "$line" =~ ^-[[:space:]]*(.+) ]]; then
                local f="${BASH_REMATCH[1]}"
                f=$(echo "$f" | sed 's/#.*//' | xargs)
                [[ -n "$f" ]] && files+=("$f")
            elif [[ "$line" =~ ^[^[:space:]-] ]]; then
                break
            fi
        fi
    done < "$subtask_file"

    printf '%s\n' "${files[@]}"
}

declare -A file_map=()
has_conflict=false

info "文件冲突检测："
for TASK in "${TASKS[@]}"; do
    TASK_MD="$PROJECT_DIR/.ai/tasks/${TASK}.md"
    if [[ ! -f "$TASK_MD" ]]; then
        warn "${TASK}: 任务文件不存在，跳过"
        continue
    fi

    task_files=$(parse_files "$TASK_MD")
    file_list=$(echo "$task_files" | tr '\n' ' ')
    echo "  $TASK: $file_list"

    while IFS= read -r filepath; do
        [[ -z "$filepath" ]] && continue
        if [[ -n "${file_map[$filepath]:-}" ]]; then
            fail "冲突: $filepath 同时属于 ${file_map[$filepath]} 和 $TASK"
            has_conflict=true
        else
            file_map[$filepath]="$TASK"
        fi
    done <<< "$task_files"
done

echo ""

if [[ "$has_conflict" == "true" ]]; then
    fail "文件冲突检测失败！拒绝执行并行任务。"
    echo ""
    echo "解决方案："
    echo "  1. 手动修改子任务文件，确保文件不重叠"
    echo "  2. 重新运行拆分：AUTO_SPLIT=1 .ai/scripts/loop-run.sh <父任务ID>"
    echo "  3. 或串行执行有冲突的任务（一个完成后再运行下一个）"
    exit 2
fi

ok "文件冲突检测通过：${#file_map[@]} 个文件均无重叠"
echo ""

# =============================================================================
# 并行启动
# =============================================================================

PIDS=()
LOGS=()

for TASK in "${TASKS[@]}"; do
    LOG="$REPORTS_DIR/${TASK}.log"
    LOGS+=("$LOG")

    info "启动 ${TASK} → 日志: .ai/reports/${TASK}.log"
    "$SCRIPT_DIR/loop-run.sh" "$TASK" > "$LOG" 2>&1 &
    PIDS+=($!)
done

echo ""
info "${#TASKS[@]} 个任务并行运行中。"

# =============================================================================
# 自动启动 loop-watch 交替监控
# =============================================================================

WATCH_SCRIPT="$SCRIPT_DIR/loop-watch.sh"
if [[ -x "$WATCH_SCRIPT" ]]; then
    echo ""
    info "启动日志交替监控 (loop-watch.sh)..."
    echo "  按 Ctrl+C 退出监控（子任务继续运行）"
    echo ""

    # loop-watch 在前台运行，直到用户 Ctrl+C
    # 子任务在后台继续运行不受影响
    LOG_ARGS=""
    for TASK in "${TASKS[@]}"; do
        LOG_ARGS="$LOG_ARGS $REPORTS_DIR/${TASK}.log"
    done

    # trap Ctrl+C：只杀 loop-watch，不杀子任务
    "$WATCH_SCRIPT" $LOG_ARGS &
    WATCH_PID=$!

    # 等待子任务完成的同时，loop-watch 在前台输出
    PASS_COUNT=0
    FAIL_COUNT=0
    RESULTS=()

    for i in "${!PIDS[@]}"; do
        TASK="${TASKS[$i]}"
        PID="${PIDS[$i]}"
        LOG="${LOGS[$i]}"

        wait "$PID"
        EXIT_CODE=$?

        if [[ $EXIT_CODE -eq 0 ]]; then
            RESULTS+=("${GREEN}✓ PASS${NC}  ${TASK}")
            PASS_COUNT=$((PASS_COUNT + 1))
        else
            if grep -q "ESCALATE" "$LOG" 2>/dev/null; then
                RESULTS+=("${RED}✗ ESCALATE${NC}  ${TASK}  → 查看 ${LOG}")
            else
                RESULTS+=("${RED}✗ FAIL${NC}  ${TASK}  → 查看 ${LOG}")
            fi
            FAIL_COUNT=$((FAIL_COUNT + 1))
        fi
    done

    # 子任务都完成了，杀掉 loop-watch
    kill "$WATCH_PID" 2>/dev/null || true
    wait "$WATCH_PID" 2>/dev/null || true
else
    # loop-watch 不存在，回退到旧的 tail 提示
    warn "loop-watch.sh 不存在，手动监控："
    for TASK in "${TASKS[@]}"; do
        echo "  tail -f .ai/reports/${TASK}.log"
    done
    echo ""

    PASS_COUNT=0
    FAIL_COUNT=0
    RESULTS=()

    for i in "${!PIDS[@]}"; do
        TASK="${TASKS[$i]}"
        PID="${PIDS[$i]}"
        LOG="${LOGS[$i]}"

        wait "$PID"
        EXIT_CODE=$?

        if [[ $EXIT_CODE -eq 0 ]]; then
            RESULTS+=("${GREEN}✓ PASS${NC}  ${TASK}")
            PASS_COUNT=$((PASS_COUNT + 1))
        else
            if grep -q "ESCALATE" "$LOG" 2>/dev/null; then
                RESULTS+=("${RED}✗ ESCALATE${NC}  ${TASK}  → 查看 ${LOG}")
            else
                RESULTS+=("${RED}✗ FAIL${NC}  ${TASK}  → 查看 ${LOG}")
            fi
            FAIL_COUNT=$((FAIL_COUNT + 1))
        fi
    done
fi

# 汇总报告
echo ""
echo "============================== 运行结果 =============================="
for RESULT in "${RESULTS[@]}"; do
    echo -e "  ${RESULT}"
done
echo ""
echo -e "  ${GREEN}PASS: ${PASS_COUNT}${NC}  |  ${RED}FAIL/ESCALATE: ${FAIL_COUNT}${NC}  |  共 ${#TASKS[@]} 个"
echo "====================================================================="

[[ $FAIL_COUNT -eq 0 ]] && exit 0 || exit 1
#!/bin/bash
# =============================================================================
# parallel-run.sh — 并行执行多个 Task，各自输出到独立日志
# 用法: .ai/scripts/parallel-run.sh TASK-001a TASK-001b [TASK-001c...]
#
# 注意: 并行运行的 Task 必须修改不同的文件，否则会产生 git 冲突
# =============================================================================

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
REPORTS_DIR="$PROJECT_DIR/.ai/reports"

GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()   { echo -e "${GREEN}✓ $*${NC}"; }
fail() { echo -e "${RED}✗ $*${NC}"; }
info() { echo -e "${CYAN}[parallel] $*${NC}"; }
warn() { echo -e "${YELLOW}⚠ $*${NC}"; }

[[ $# -eq 0 ]] && { echo "用法: $0 TASK-001a TASK-001b [...]"; exit 1; }

mkdir -p "$REPORTS_DIR"

TASKS=("$@")
PIDS=()
LOGS=()

# 警告：并行任务的文件范围必须不重叠
warn "确保以下 Task 修改的文件范围不重叠，否则会产生 git 冲突："
for TASK in "${TASKS[@]}"; do
    echo "  - ${TASK}: $(grep '只允许修改' "$PROJECT_DIR/.ai/tasks/${TASK}.md" -A5 2>/dev/null | grep '^\s*-' | head -3 | tr '\n' ' ' || echo '(范围未读取)')"
done
echo ""

# 并行启动所有 Task
for TASK in "${TASKS[@]}"; do
    LOG="$REPORTS_DIR/${TASK}.log"
    LOGS+=("$LOG")

    info "启动 ${TASK} → 日志: .ai/reports/${TASK}.log"
    "$SCRIPT_DIR/loop-run.sh" "$TASK" > "$LOG" 2>&1 &
    PIDS+=($!)
done

echo ""
info "${#TASKS[@]} 个任务并行运行中。实时监控："
for TASK in "${TASKS[@]}"; do
    echo "  tail -f .ai/reports/${TASK}.log"
done
echo ""

# 等待所有任务完成，收集结果
PASS_COUNT=0
FAIL_COUNT=0
RESULTS=()

for i in "${!PIDS[@]}"; do
    TASK="${TASKS[$i]}"
    PID="${PIDS[$i]}"
    LOG="${LOGS[$i]}"

    wait "$PID"
    EXIT_CODE=$?

    if [[ $EXIT_CODE -eq 0 ]]; then
        RESULTS+=("${GREEN}✓ PASS${NC}  ${TASK}")
        PASS_COUNT=$((PASS_COUNT + 1))
    else
        # 区分 ESCALATE 还是其他错误
        if grep -q "ESCALATE" "$LOG" 2>/dev/null; then
            RESULTS+=("${RED}✗ ESCALATE${NC}  ${TASK}  → 查看 ${LOG}")
        else
            RESULTS+=("${RED}✗ FAIL${NC}  ${TASK}  → 查看 ${LOG}")
        fi
        FAIL_COUNT=$((FAIL_COUNT + 1))
    fi
done

# 汇总报告
echo ""
echo "============================== 运行结果 =============================="
for RESULT in "${RESULTS[@]}"; do
    echo -e "  ${RESULT}"
done
echo ""
echo -e "  ${GREEN}PASS: ${PASS_COUNT}${NC}  |  ${RED}FAIL/ESCALATE: ${FAIL_COUNT}${NC}  |  共 ${#TASKS[@]} 个"
echo "====================================================================="

[[ $FAIL_COUNT -eq 0 ]] && exit 0 || exit 1
