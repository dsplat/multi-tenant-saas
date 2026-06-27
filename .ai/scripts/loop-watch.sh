#!/bin/bash
#
# loop-watch: 多日志交替监控工具
# 用法: ./loop-watch.sh .ai/reports/TASK-004a.log .ai/reports/TASK-004b.log .ai/reports/TASK-004c.log
#
# 原理: 每秒轮询所有日志文件，输出新增行（带前缀），解决 tail -f 对批量写入日志无输出的问题

set -euo pipefail

if [ $# -eq 0 ]; then
    echo "用法: $0 <log1> [log2] [log3] ..."
    exit 1
fi

# 初始化每个文件的已读位置
declare -A OFFSETS
for log in "$@"; do
    if [ -f "$log" ]; then
        OFFSETS[$log]=$(wc -c < "$log")
    else
        OFFSETS[$log]=0
    fi
    # 提取任务名作为前缀
    name=$(basename "$log" .log)
    echo "[$name] 监控中: $log (初始位置: ${OFFSETS[$log]} 字节)"
done

echo "--- 等待新输出 ---"

while true; do
    has_new=false
    for log in "$@"; do
        if [ ! -f "$log" ]; then
            continue
        fi
        current_size=$(wc -c < "$log")
        prev_size=${OFFSETS[$log]}
        if [ "$current_size" -gt "$prev_size" ]; then
            name=$(basename "$log" .log)
            # 读取新增内容
            new_content=$(tail -c +$((prev_size + 1)) "$log" 2>/dev/null || true)
            if [ -n "$new_content" ]; then
                # 每行加前缀
                echo "$new_content" | while IFS= read -r line; do
                    echo "[$name] $line"
                done
            fi
            OFFSETS[$log]=$current_size
            has_new=true
        elif [ "$current_size" -lt "$prev_size" ]; then
            # 文件被截断/重建
            name=$(basename "$log" .log)
            echo "[$name] ⚠ 日志文件已重置"
            OFFSETS[$log]=0
            has_new=true
        fi
    done
    if [ "$has_new" = false ]; then
        sleep 1
    fi
done
