#!/usr/bin/env python3
"""
守护模式批量执行器 v2

无人值守特性：
- 崩溃自动重启（从断点继续）
- 进程挂起检测（超时自动 kill）
- 失败自动诊断（调 claude 分析根因）
- 自动修复策略（根据诊断结果调整 prompt 重试）
- 最终失败标记 SKIP 继续下一个任务
- 日志持久化

用法:
    python3 guardian.py              # 前台运行
    python3 guardian.py --daemon     # 后台运行
    python3 guardian.py --status     # 查看当前状态
"""
from __future__ import annotations

import subprocess
import sys
import os
import time
import json
import re
from pathlib import Path
from datetime import datetime

sys.path.insert(0, str(Path(__file__).parent))

from lib import StateManager, log, ok, warn, fail, info
from lib.executor import run_claude, CLIExecutor

# 默认任务序列
DEFAULT_SEQUENCE = [
    "TASK-010b", "TASK-010c", "TASK-010d",
    "TASK-011", "TASK-012", "TASK-013", "TASK-014",
    "TASK-015", "TASK-016", "TASK-017", "TASK-018",
]

GUARDIAN_STATE = Path(__file__).parent.parent / "guardian.json"
MAX_TASK_TIME = 1800   # 单任务 30 分钟
MAX_RETRIES = 3        # 最多重试 3 次


def load_guardian_state() -> dict:
    if GUARDIAN_STATE.exists():
        return json.loads(GUARDIAN_STATE.read_text())
    return {
        "sequence": DEFAULT_SEQUENCE,
        "completed": [],
        "skipped": [],
        "failed": None,
        "current": None,
        "retries": {},
        "diagnoses": {},
        "started_at": None,
        "updated_at": None,
    }


def save_guardian_state(state: dict):
    state["updated_at"] = datetime.now().isoformat()
    GUARDIAN_STATE.write_text(json.dumps(state, indent=2, ensure_ascii=False))


def get_task_status(state_mgr: StateManager, task_id: str) -> str:
    task = state_mgr.get_task(task_id)
    return task.status if task else "NOT_FOUND"


# =============================================================================
# 自动诊断 + 修复策略
# =============================================================================

def diagnose_failure(task_id: str, project_dir: Path) -> dict:
    """
    自动诊断失败原因
    
    收集：REVIEW 输出、git diff、任务文件
    调 claude 分析根因
    返回：{root_cause, strategy, fix_prompt}
    """
    # 收集上下文
    review_file = project_dir / ".ai" / "review" / f"{task_id}-review.md"
    task_file = project_dir / ".ai" / "tasks" / f"{task_id}.md"
    
    review_text = review_file.read_text(encoding='utf-8') if review_file.exists() else "无 REVIEW 输出"
    task_text = task_file.read_text(encoding='utf-8') if task_file.exists() else "无任务文件"
    
    # git diff（最近变更）
    result = subprocess.run(
        ["git", "diff", "--cached", "--stat", "HEAD"],
        cwd=project_dir, capture_output=True, text=True
    )
    diff_stat = result.stdout.strip() or "无变更"
    
    # 调 claude 诊断（短 prompt，低成本）
    prompt = f"""分析任务 {task_id} 失败原因，给出修复策略。

任务目标（摘要）：
{task_text[:500]}

REVIEW 反馈（摘要）：
{review_text[:1000]}

文件变更统计：
{diff_stat}

请用 JSON 格式回复（只输出 JSON，不要其他内容）：
{{
    "root_cause": "失败根因（一句话）",
    "category": "scope_overflow|incomplete_code|model_capability|review_strict|other",
    "strategy": "修复策略：rewrite_prompt|add_detail|skip|retry_same",
    "fix_instruction": "给 DEV 的补充指令（一句话，会注入到 prompt 中）"
}}"""
    
    try:
        output = run_claude(prompt, timeout=60, cwd=project_dir)
        # 提取 JSON
        json_match = re.search(r'\{[^}]+\}', output, re.DOTALL)
        if json_match:
            diagnosis = json.loads(json_match.group())
            return diagnosis
    except Exception as e:
        warn(f"诊断失败: {e}")
    
    # 默认诊断
    return {
        "root_cause": "无法自动诊断",
        "category": "other",
        "strategy": "retry_same",
        "fix_instruction": "",
    }


def apply_fix_strategy(task_id: str, diagnosis: dict, project_dir: Path):
    """
    根据诊断结果，修改 dev-prompt 注入修复指令
    
    策略：
    - rewrite_prompt: 在 dev-prompt 末尾追加修复指令
    - add_detail: 追加更详细的实现步骤
    - skip: 跳过此任务
    - retry_same: 原样重试
    """
    strategy = diagnosis.get("strategy", "retry_same")
    fix_instruction = diagnosis.get("fix_instruction", "")
    
    if strategy == "skip":
        warn(f"策略: 跳过 {task_id}")
        return "skip"
    
    if not fix_instruction:
        return "retry_same"
    
    # 写入修复指令到临时文件
    fix_file = project_dir / ".ai" / "tasks" / f"{task_id}-fix.md"
    fix_content = f"""# {task_id} 修复指令（第 {diagnosis.get('attempt', 1)} 次重试）

## 上次失败原因
{diagnosis.get('root_cause', '未知')}

## 修复要求
{fix_instruction}

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
"""
    fix_file.write_text(fix_content, encoding='utf-8')
    ok(f"修复指令已写入: {fix_file}")
    
    return strategy


def run_task_with_guard(task_id: str, project_dir: Path, state_mgr: StateManager,
                        attempt: int = 0) -> bool:
    """
    带守护的单任务执行
    
    - 如果有修复指令，注入到 run.py
    - 启动 run.py 子进程
    - 监控进程状态
    - 超时自动 kill
    """
    run_script = Path(__file__).parent / "run.py"
    
    cmd = [sys.executable, str(run_script), task_id]
    
    # 如果有修复指令，run.py 会自动读取 {task_id}-fix.md
    fix_file = project_dir / ".ai" / "tasks" / f"{task_id}-fix.md"
    if fix_file.exists() and attempt > 0:
        info(f"检测到修复指令，将注入到 prompt")
    
    proc = subprocess.Popen(
        cmd,
        cwd=project_dir,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    
    start_time = time.time()
    
    while True:
        elapsed = time.time() - start_time
        
        # 超时检测
        if elapsed > MAX_TASK_TIME:
            warn(f"{task_id} 运行超过 {MAX_TASK_TIME}s，强制终止")
            proc.kill()
            proc.wait()
            return False
        
        # 进程结束检测
        ret = proc.poll()
        if ret is not None:
            return ret == 0
        
        time.sleep(10)


def guardian_main():
    """守护主循环"""
    project_dir = Path(__file__).parent.parent.parent
    state_file = project_dir / ".ai" / "state.json"
    state_mgr = StateManager(state_file)
    
    gstate = load_guardian_state()
    
    if not gstate["started_at"]:
        gstate["started_at"] = datetime.now().isoformat()
    
    info("=" * 60)
    info("守护模式 v2 启动（自动诊断 + 修复）")
    info("=" * 60)
    
    # 恢复断点
    completed = set(gstate["completed"])
    skipped = set(gstate.get("skipped", []))
    remaining = [t for t in gstate["sequence"] if t not in completed and t not in skipped]
    
    info(f"已完成: {len(completed)}, 已跳过: {len(skipped)}, 剩余: {len(remaining)}")
    if remaining:
        info(f"从 {remaining[0]} 继续")
    
    # 断点恢复检查
    if gstate["current"]:
        status = get_task_status(state_mgr, gstate["current"])
        if status == "DONE":
            ok(f"{gstate['current']} 实际已完成")
            completed.add(gstate["current"])
            gstate["completed"] = list(completed)
            remaining = [t for t in gstate["sequence"] if t not in completed and t not in skipped]
    
    for task_id in remaining:
        gstate["current"] = task_id
        save_guardian_state(gstate)
        
        # 已完成则跳过
        status = get_task_status(state_mgr, task_id)
        if status == "DONE":
            ok(f"{task_id} 已完成，跳过")
            completed.add(task_id)
            gstate["completed"] = list(completed)
            save_guardian_state(gstate)
            continue
        
        # === 每个任务开始前：清理工作区 ===
        info(f"清理工作区（防止前序任务脏文件污染）")
        subprocess.run(["git", "reset", "HEAD", "--", "."], cwd=project_dir, capture_output=True)
        subprocess.run(["git", "checkout", "--", "."], cwd=project_dir, capture_output=True)
        # 清理 untracked 文件，但保留 .ai/ 和 vendor/
        subprocess.run(["git", "clean", "-fd", "-e", ".ai", "-e", "vendor"], cwd=project_dir, capture_output=True)
        ok("工作区已清理")
        
        # 重置任务状态
        state_mgr.update_task(task_id, "READY")
        
        # 重试循环
        success = False
        
        for attempt in range(MAX_RETRIES + 1):
            now = datetime.now().strftime("%H:%M:%S")
            log(f"\n[{now}] 执行 {task_id} (尝试 {attempt + 1}/{MAX_RETRIES + 1})")
            
            try:
                success = run_task_with_guard(task_id, project_dir, state_mgr, attempt)
            except Exception as e:
                warn(f"{task_id} 异常: {e}")
                success = False
            
            if success:
                break
            
            # === 失败后：自动诊断 + 修复 ===
            warn(f"{task_id} 第 {attempt + 1} 次失败，开始自动诊断...")
            
            # 重置任务状态
            state_mgr.update_task(task_id, "READY")
            
            # 清理 git 状态（撤销失败的变更）
            subprocess.run(
                ["git", "checkout", "--", "."],
                cwd=project_dir, capture_output=True
            )
            subprocess.run(
                ["git", "clean", "-fd", "--exclude=.ai"],
                cwd=project_dir, capture_output=True
            )
            
            # 诊断（调 claude，低成本）
            diagnosis = diagnose_failure(task_id, project_dir)
            diagnosis["attempt"] = attempt + 1
            gstate["diagnoses"][task_id] = diagnosis
            save_guardian_state(gstate)
            
            info(f"诊断结果: {diagnosis.get('root_cause', '未知')}")
            info(f"修复策略: {diagnosis.get('strategy', 'retry_same')}")
            
            # 应用修复策略
            strategy = apply_fix_strategy(task_id, diagnosis, project_dir)
            
            if strategy == "skip":
                warn(f"跳过 {task_id}")
                break
            
            # 等待后重试
            time.sleep(5)
        
        if success:
            ok(f"✓ {task_id} 完成")
            
            # === 成功后：提交变更（防止泄漏到下一个任务） ===
            commit_result = subprocess.run(
                ["git", "commit", "-m", f"TASK: {task_id} (guardian auto)"],
                cwd=project_dir, capture_output=True, text=True
            )
            if commit_result.returncode == 0:
                ok(f"已提交: {task_id}")
            else:
                warn(f"提交跳过（可能无变更）: {commit_result.stderr.strip()[:100]}")
            
            completed.add(task_id)
            gstate["completed"] = list(completed)
            gstate["retries"].pop(task_id, None)
            gstate["diagnoses"].pop(task_id, None)
            # 清理修复文件
            fix_file = project_dir / ".ai" / "tasks" / f"{task_id}-fix.md"
            if fix_file.exists():
                fix_file.unlink()
            save_guardian_state(gstate)
        else:
            # 最终失败：标记 SKIP，继续下一个
            fail(f"✗ {task_id} 重试 {MAX_RETRIES + 1} 次后仍失败，标记 SKIP 继续")
            skipped.add(task_id)
            gstate["skipped"] = list(skipped)
            state_mgr.update_task(task_id, "SKIPPED")
            gstate["current"] = None
            save_guardian_state(gstate)
    
    # 全部完成
    gstate["current"] = None
    save_guardian_state(gstate)
    
    elapsed = time.time() - datetime.fromisoformat(gstate["started_at"]).timestamp()
    elapsed_m = int(elapsed) // 60
    
    info("=" * 60)
    ok(f"全部完成！耗时 {elapsed_m} 分钟")
    ok(f"完成: {', '.join(gstate['completed'])}")
    if gstate.get("skipped"):
        warn(f"跳过: {', '.join(gstate['skipped'])}")
    info("=" * 60)
    return 0


def show_status():
    """显示守护状态"""
    gstate = load_guardian_state()
    
    print(f"守护状态: {GUARDIAN_STATE}")
    print(f"启动时间: {gstate.get('started_at', '未启动')}")
    print(f"更新时间: {gstate.get('updated_at', '未更新')}")
    print(f"当前任务: {gstate.get('current', '无')}")
    print(f"已完成: {', '.join(gstate.get('completed', []))}")
    
    remaining = [t for t in gstate.get('sequence', []) 
                 if t not in gstate.get('completed', []) and t not in gstate.get('skipped', [])]
    print(f"剩余: {', '.join(remaining)}")
    
    if gstate.get('skipped'):
        print(f"已跳过: {', '.join(gstate['skipped'])}")
    if gstate.get('diagnoses'):
        print(f"\n诊断记录:")
        for tid, diag in gstate['diagnoses'].items():
            print(f"  {tid}: {diag.get('root_cause', '?')} → {diag.get('strategy', '?')}")


def daemonize():
    """后台运行"""
    log_file = Path(__file__).parent.parent / "guardian.log"
    
    pid = os.fork()
    if pid > 0:
        print(f"守护进程已启动 (PID: {pid})")
        print(f"日志: {log_file}")
        print(f"查看状态: python3 guardian.py --status")
        return
    
    sys.stdin.close()
    with open(log_file, 'w') as f:
        sys.stdout = f
        sys.stderr = f
        print(f"[{datetime.now()}] 守护进程 v2 启动 (PID: {os.getpid()})")
        f.flush()
        ret = guardian_main()
        print(f"[{datetime.now()}] 守护进程结束 (退出码: {ret})")
    
    sys.exit(ret)


def main():
    import argparse
    parser = argparse.ArgumentParser(description="守护模式批量执行器 v2")
    parser.add_argument("--daemon", "-d", action="store_true", help="后台运行")
    parser.add_argument("--status", "-s", action="store_true", help="查看状态")
    parser.add_argument("--reset", action="store_true", help="重置状态重新开始")
    args = parser.parse_args()
    
    if args.status:
        show_status()
        return
    
    if args.reset:
        if GUARDIAN_STATE.exists():
            GUARDIAN_STATE.unlink()
        ok("状态已重置")
        return
    
    if args.daemon:
        daemonize()
    else:
        sys.exit(guardian_main())


if __name__ == "__main__":
    main()
