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
