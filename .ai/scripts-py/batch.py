#!/usr/bin/env python3
"""
批量任务执行器

按顺序执行多个任务，失败即停。

用法:
    python3 batch.py                          # 跑 010b-d + 011-018
    python3 batch.py TASK-012 TASK-013        # 跑指定任务
"""
from __future__ import annotations

import subprocess
import sys
import time
from pathlib import Path
from datetime import datetime

sys.path.insert(0, str(Path(__file__).parent))

from lib import StateManager, log, ok, warn, fail, info


# 默认执行序列
DEFAULT_SEQUENCE = [
    "TASK-010b", "TASK-010c", "TASK-010d",
    "TASK-011", "TASK-012", "TASK-013", "TASK-014",
    "TASK-015", "TASK-016", "TASK-017", "TASK-018",
]


def check_dependencies(task_id: str, state: StateManager) -> tuple[bool, str]:
    """检查任务依赖"""
    task_file = Path(__file__).parent.parent / "tasks" / f"{task_id}.md"
    if not task_file.exists():
        return False, f"任务文件不存在: {task_id}"
    
    content = task_file.read_text(encoding='utf-8')
    
    # 解析依赖
    import re
    dep_match = re.search(r'\*\*依赖:\*\*\s*(.+)', content)
    if not dep_match:
        return True, "无依赖"
    
    dep_str = dep_match.group(1).strip()
    if dep_str in ("无", "none", "None", ""):
        return True, "无依赖"
    
    # 检查每个依赖
    deps = [d.strip() for d in dep_str.split(",")]
    for dep in deps:
        task = state.get_task(dep)
        if task is None:
            return False, f"依赖任务 {dep} 不存在"
        if task.status != "DONE":
            return False, f"依赖 {dep} 状态为 {task.status}（未完成）"
    
    return True, f"依赖满足: {dep_str}"


def run_single_task(task_id: str, project_dir: Path) -> bool:
    """执行单个任务，返回是否成功"""
    run_script = Path(__file__).parent / "run.py"
    
    result = subprocess.run(
        [sys.executable, str(run_script), task_id],
        cwd=project_dir,
    )
    return result.returncode == 0


def main():
    import argparse
    
    parser = argparse.ArgumentParser(description="批量任务执行器")
    parser.add_argument("tasks", nargs="*", help="任务 ID 列表（留空使用默认序列）")
    parser.add_argument("--dry-run", action="store_true", help="只显示计划，不执行")
    parser.add_argument("--skip-check", action="store_true", help="跳过依赖检查")
    
    args = parser.parse_args()
    
    # 获取项目目录
    result = subprocess.run(
        ["git", "rev-parse", "--show-toplevel"],
        capture_output=True, text=True
    )
    project_dir = Path(result.stdout.strip()) if result.returncode == 0 else Path.cwd()
    
    state_file = project_dir / ".ai" / "state.json"
    state = StateManager(state_file)
    
    # 确定任务序列
    task_list = args.tasks if args.tasks else DEFAULT_SEQUENCE
    
    # 显示计划
    info("=" * 60)
    info(f"批量执行计划 ({len(task_list)} 个任务)")
    info("=" * 60)
    for i, tid in enumerate(task_list, 1):
        task = state.get_task(tid)
        status = task.status if task else "未知"
        print(f"  {i:2d}. {tid:15s} [{status}]")
    info("=" * 60)
    
    if args.dry_run:
        return
    
    # 开始执行
    start_time = time.time()
    completed = []
    failed = None
    
    for i, task_id in enumerate(task_list, 1):
        now = datetime.now().strftime("%H:%M:%S")
        log(f"\n{'='*60}")
        log(f"[{now}] 开始执行 ({i}/{len(task_list)}): {task_id}")
        log(f"{'='*60}")
        
        # 检查依赖
        if not args.skip_check:
            dep_ok, dep_msg = check_dependencies(task_id, state)
            if not dep_ok:
                fail(f"依赖检查失败: {dep_msg}")
                failed = task_id
                break
            else:
                ok(f"依赖: {dep_msg}")
        
        # 检查是否已完成
        task = state.get_task(task_id)
        if task and task.status == "DONE":
            ok(f"{task_id} 已完成，跳过")
            completed.append(task_id)
            continue
        
        # 执行任务
        success = run_single_task(task_id, project_dir)
        
        if success:
            ok(f"✓ {task_id} 完成")
            completed.append(task_id)
        else:
            fail(f"✗ {task_id} 失败，停止后续任务")
            failed = task_id
            break
    
    # 汇总
    elapsed = time.time() - start_time
    elapsed_m = int(elapsed) // 60
    elapsed_s = int(elapsed) % 60
    
    log(f"\n{'='*60}")
    info("执行汇总")
    info(f"{'='*60}")
    info(f"完成: {len(completed)}/{len(task_list)}")
    info(f"耗时: {elapsed_m}分{elapsed_s}秒")
    
    if completed:
        ok(f"已完成: {', '.join(completed)}")
    if failed:
        fail(f"失败: {failed}")
        remaining = task_list[len(completed):]
        if remaining:
            warn(f"未执行: {', '.join(remaining)}")
    
    return 0 if not failed else 1


if __name__ == "__main__":
    sys.exit(main())
