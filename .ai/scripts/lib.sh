#!/bin/bash
# =============================================================================
# lib.sh — 共享函数库
# 用法: source "$(dirname "$0")/lib.sh"
# =============================================================================

# 解析子任务文件中的"只允许修改"文件列表
# 参数: $1 = 子任务 .md 文件路径
# 输出: 每行一个文件路径
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
            if [[ "$line" =~ ^-[[:space:]]*(.+) ]]; then
                local f="${BASH_REMATCH[1]}"
                f=$(echo "$f" | sed 's/`//g; s/（.*//; s/(.*//; s/#.*//' | xargs)
                [[ -n "$f" ]] && files+=("$f")
            elif [[ "$line" =~ ^[^[:space:]-] ]]; then
                break
            fi
        fi
    done < "$subtask_file"

    printf '%s\n' "${files[@]}"
}
