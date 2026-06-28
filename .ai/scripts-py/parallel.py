#!/usr/bin/env python3
from __future__ import annotations
"""
并行任务执行器

用法:
    python3 parallel.py TASK-001a TASK-001b TASK-001c
"""

import os
import sys
import subprocess
import concurrent.futures
from pathlib import Path
from typing import Optional

sys.path.insert(0, str(Path(__file__).parent))

from lib import log, ok, fail, info
from lib.state import StateManager


def get_project_dir() -> Path:
    """获取项目根目录"""
    result = subprocess.run(
        ["git", "rev-parse", "--show-toplevel"],
        capture_output=True, text=True
    )
    if result.returncode == 0:
        return Path(result.stdout.strip())
    return Path.cwd()


def run_task(task_id: str, project_dir: Path) -> tuple[str, bool]:
    """
    执行单个任务
    
    Returns:
        (task_id, success)
    """
    log(f"开始执行: {task_id}")
    
    run_script = Path(__file__).parent / "run.py"
    
    try:
        result = subprocess.run(
            [sys.executable, str(run_script), task_id],
            cwd=project_dir,
            capture_output=False,
        )
        
        if result.returncode == 0:
            ok(f"任务 {task_id} 完成")
            return task_id, True
        else:
            fail(f"任务 {task_id} 失败，退出码: {result.returncode}")
            return task_id, False
            
    except Exception as e:
        fail(f"任务 {task_id} 异常: {e}")
        return task_id, False


def main():
    import argparse
    
    parser = argparse.ArgumentParser(description="并行任务执行器")
    parser.add_argument("task_ids", nargs="+", help="任务 ID 列表")
    parser.add_argument("--max-workers", type=int, default=3,
                       help="最大并行数（默认 3）")
    
    args = parser.parse_args()
    
    if not args.task_ids:
        fail("请提供至少一个任务 ID")
        return 1
    
    project_dir = get_project_dir()
    
    log(f"并行执行 {len(args.task_ids)} 个任务: {args.task_ids}")
    info(f"最大并行数: {args.max_workers}")
    
    # 并行执行
    results = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.max_workers) as executor:
        futures = {
            executor.submit(run_task, task_id, project_dir): task_id
            for task_id in args.task_ids
        }
        
        for future in concurrent.futures.as_completed(futures):
            task_id, success = future.result()
            results[task_id] = success
    
    # 汇总
    print("\n" + "=" * 50)
    log("执行结果汇总:")
    
    success_count = 0
    fail_count = 0
    
    for task_id in args.task_ids:
        status = "✓ 成功" if results.get(task_id) else "✗ 失败"
        log(f"  {task_id}: {status}")
        if results.get(task_id):
            success_count += 1
        else:
            fail_count += 1
    
    print()
    if fail_count > 0:
        fail(f"完成: {success_count} 成功, {fail_count} 失败")
        return 1
    else:
        ok(f"全部完成: {success_count} 成功")
        return 0


if __name__ == "__main__":
    sys.exit(main())
