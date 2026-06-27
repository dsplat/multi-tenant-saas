#!/bin/bash
# =============================================================================
# plan-task.sh — 用 Claude Code 拆分需求为 Sprint + Task 列表
# 用法: .ai/scripts/plan-task.sh "完成 Auth 模块登录功能" [sprint-001]
# =============================================================================

set -euo pipefail

REQUIREMENT="${1:?用法: $0 '需求描述' [sprint-id]}"
SPRINT_ID="${2:-sprint-$(date +%Y%m%d)}"
PROJECT_DIR="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
SPRINT_FILE="$PROJECT_DIR/.ai/sprints/${SPRINT_ID}.md"
PLAN_PROMPT="$PROJECT_DIR/.ai/prompts/plan-prompt.md"

GREEN='\033[0;32m'; NC='\033[0m'
log() { echo -e "${NC}[plan-task] $*"; }
ok()  { echo -e "${GREEN}[plan-task] ✓ $*${NC}"; }

[[ -f "$PLAN_PROMPT" ]] || { echo "plan-prompt.md 不存在: $PLAN_PROMPT"; exit 1; }

log "=== 使用 Claude Code 规划: $REQUIREMENT ==="

claude -p "$(cat "$PLAN_PROMPT")

## 需求
$REQUIREMENT" \
    --output-format text > "$SPRINT_FILE"

ok "Sprint 规划写入: $SPRINT_FILE"
echo ""
cat "$SPRINT_FILE"
echo ""
log "接下来：查看 $SPRINT_FILE，选择 Task，运行 loop-run.sh TASK-XXXX"
