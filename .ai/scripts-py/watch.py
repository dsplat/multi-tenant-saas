#!/usr/bin/env python3
from __future__ import annotations
"""
任务监控器 - 高频轮询 + 按需 LLM 分析

功能：
- 监控正在运行的任务进程
- 每 20 秒检查一次状态
- 只在检测到异常时才调用 LLM 分析（节省 token）
- 支持监控 git 变更、测试状态、进程状态

用法：
    python3 watch.py                    # 监控当前任务状态
    python3 watch.py TASK-010a          # 监控指定任务
    python3 watch.py --watch-dir        # 监控文件变更
    python3 watch.py --tail-review      # 跟踪 review 文件变化
"""

import os
import sys
import time
import subprocess
import re
from pathlib import Path
from datetime import datetime

sys.path.insert(0, str(Path(__file__).parent))

from lib import StateManager, log, ok, warn, fail, info
from lib.executor import _detect_anomalies, run_claude


# =============================================================================
# 异常分析器 - 只在需要时调用 LLM
# =============================================================================

class AnomalyAnalyzer:
    """按需 LLM 分析器"""
    
    # 自动处理的问题模式（不需要 LLM）
    AUTO_FIX_PATTERNS = {
        "command not found": "缺少 CLI 工具，请检查 PATH 或安装",
        "permission denied": "权限问题，检查文件权限",
        "connection refused": "网络连接问题",
        "rate limit": "API 限流，等待后重试",
        "out of memory": "内存不足，关闭其他进程后重试",
    }
    
    # 需要 LLM 分析的严重问题
    LLM_TRIGGER_KEYWORDS = [
        "traceback", "segfault", "fatal", "panic",
        "unexpected error", "internal error",
    ]
    
    def __init__(self, project_dir: Path):
        self.project_dir = project_dir
        self._analyzed = set()  # 避免重复分析
    
    def analyze(self, context: str, task_id: str = "") -> str:
        """
        分析异常上下文
        
        先尝试规则匹配（不调用 LLM），只有无法判断时才调用 LLM。
        
        Returns:
            分析结论
        """
        # 去重
        context_hash = hash(context[:200])
        if context_hash in self._analyzed:
            return ""
        self._analyzed.add(context_hash)
        
        context_lower = context.lower()
        
        # 1. 先尝试自动匹配（不调用 LLM）
        for pattern, suggestion in self.AUTO_FIX_PATTERNS.items():
            if pattern in context_lower:
                return f"[自动诊断] {suggestion}"
        
        # 2. 检查是否需要 LLM 分析
        needs_llm = any(kw in context_lower for kw in self.LLM_TRIGGER_KEYWORDS)
        if not needs_llm:
            return f"[自动诊断] 检测到异常关键字，但不严重，继续观察"
        
        # 3. 调用 LLM 分析（只在必要时）
        info("调用 LLM 分析异常...")
        prompt = f"""你是一个运维诊断专家。以下是任务 {task_id} 执行过程中的异常输出：

```
{context[-2000:]}
```

请用 1-3 句话分析：
1. 可能的根因
2. 建议的处理方式

不要输出代码，只给诊断结论。"""
        
        try:
            result = run_claude(prompt, timeout=60, cwd=self.project_dir)
            return f"[LLM 诊断] {result.strip()}" if result else "[LLM 诊断失败]"
        except Exception as e:
            return f"[LLM 调用失败] {e}"


# =============================================================================
# 监控模式
# =============================================================================

def watch_state(state_file: Path, task_id: str = "", interval: int = 20):
    """监控 state.json 变化"""
    state = StateManager(state_file)
    last_states = {}
    
    info(f"开始监控任务状态（每 {interval} 秒检查一次）...")
    if task_id:
        info(f"聚焦任务: {task_id}")
    
    try:
        while True:
            state._load()
            tasks = state.get_all_tasks()
            
            # 过滤
            if task_id:
                tasks = [t for t in tasks if t.id == task_id or t.id.startswith(f"{task_id}")]
            
            # 检查变化
            changed = False
            for t in tasks:
                prev = last_states.get(t.id)
                if prev != t.status:
                    now = datetime.now().strftime("%H:%M:%S")
                    if prev is None:
                        log(f"  [{now}] {t.id}: {t.status}")
                    else:
                        info(f"  [{now}] {t.id}: {prev} → {t.status}")
                    last_states[t.id] = t.status
                    changed = True
                    
                    # 检查终态
                    if t.status in ("DONE", "TEST"):
                        ok(f"  {t.id} 完成!")
                    elif t.status in ("FAILED", "DEV_FAILED", "FIX_TIMEOUT"):
                        fail(f"  {t.id} 失败: {t.status}")
            
            if not changed:
                # 无变化时静默，只打印一个进度点
                active = [t for t in tasks if t.status in ("DEV", "REVIEW", "FIX", "SPLITTING")]
                if active:
                    ids = ", ".join(t.id for t in active)
                    print(f"  ⏳ {datetime.now().strftime('%H:%M:%S')} | 运行中: {ids}", end="\r")
            
            time.sleep(interval)
            
    except KeyboardInterrupt:
        print("\n")
        ok("监控停止")


def watch_git(project_dir: Path, interval: int = 20):
    """监控 git 变更"""
    info(f"开始监控文件变更（每 {interval} 秒检查一次）...")
    
    last_diff_stat = ""
    
    try:
        while True:
            result = subprocess.run(
                ["git", "diff", "--stat", "HEAD"],
                cwd=project_dir,
                capture_output=True, text=True
            )
            current = result.stdout.strip()
            
            if current != last_diff_stat:
                now = datetime.now().strftime("%H:%M:%S")
                log(f"  [{now}] 文件变更:")
                for line in current.split('\n')[-10:]:  # 最近 10 行
                    if line.strip():
                        print(f"    {line}")
                last_diff_stat = current
            
            time.sleep(interval)
            
    except KeyboardInterrupt:
        print("\n")
        ok("监控停止")


def watch_process(project_dir: Path, interval: int = 20, analyzer: AnomalyAnalyzer = None):
    """监控 AI 工具进程 + 异常检测"""
    info(f"开始监控 AI 工具进程（每 {interval} 秒检查一次）...")
    
    try:
        while True:
            # 检查进程
            for tool in ["opencode", "claude", "mimo"]:
                result = subprocess.run(
                    ["pgrep", "-f", tool],
                    capture_output=True, text=True
                )
                if result.stdout.strip():
                    pids = result.stdout.strip().split('\n')
                    # 检查进程输出是否有异常（通过 /proc 或 lsof）
                    # macOS 没有 /proc，用 lsof 检查
                    for pid in pids[:3]:  # 最多检查 3 个
                        _check_process_output(pid.strip(), tool, analyzer)
            
            time.sleep(interval)
            
    except KeyboardInterrupt:
        print("\n")
        ok("监控停止")


def _check_process_output(pid: str, tool: str, analyzer: AnomalyAnalyzer = None):
    """检查进程输出中的异常（macOS 兼容）"""
    if not analyzer:
        return
    
    # macOS: 通过 log show 或直接读取进程日志
    # 这里简化为检查 .ai/reports/ 下最新的日志文件
    reports_dir = Path.cwd() / ".ai" / "reports"
    if not reports_dir.exists():
        return
    
    # 找最新的日志文件
    log_files = sorted(reports_dir.glob("*.log"), key=lambda f: f.stat().st_mtime, reverse=True)
    if not log_files:
        return
    
    latest = log_files[0]
    try:
        # 读取最后 50 行
        with open(latest, 'r', errors='ignore') as f:
            lines = f.readlines()
            recent = ''.join(lines[-50:])
        
        anomalies = _detect_anomalies(recent)
        if anomalies:
            task_id = latest.stem.replace('.log', '')
            result = analyzer.analyze(recent, task_id)
            if result:
                warn(f"[{tool} PID:{pid}] {result}")
    except Exception:
        pass


# =============================================================================
# 主入口
# =============================================================================

def main():
    import argparse
    
    parser = argparse.ArgumentParser(description="任务监控器")
    parser.add_argument("task_id", nargs="?", default="", help="监控指定任务")
    parser.add_argument("--interval", "-i", type=int, default=20,
                       help="检查间隔秒数（默认 20）")
    parser.add_argument("--watch-dir", action="store_true",
                       help="监控文件变更")
    parser.add_argument("--watch-process", action="store_true",
                       help="监控 AI 工具进程")
    parser.add_argument("--watch-all", action="store_true",
                       help="监控所有（状态 + 文件 + 进程）")
    
    args = parser.parse_args()
    
    # 获取项目目录
    result = subprocess.run(
        ["git", "rev-parse", "--show-toplevel"],
        capture_output=True, text=True
    )
    project_dir = Path(result.stdout.strip()) if result.returncode == 0 else Path.cwd()
    
    state_file = project_dir / ".ai" / "state.json"
    analyzer = AnomalyAnalyzer(project_dir)
    
    if args.watch_all:
        import threading
        threads = [
            threading.Thread(target=watch_state, args=(state_file, args.task_id, args.interval)),
            threading.Thread(target=watch_git, args=(project_dir, args.interval)),
            threading.Thread(target=watch_process, args=(project_dir, args.interval, analyzer)),
        ]
        for t in threads:
            t.daemon = True
            t.start()
        
        info("全面监控已启动（Ctrl+C 停止）")
        try:
            while True:
                time.sleep(1)
        except KeyboardInterrupt:
            print("\n")
            ok("监控停止")
        return
    
    if args.watch_dir:
        watch_git(project_dir, args.interval)
    elif args.watch_process:
        watch_process(project_dir, args.interval, analyzer)
    else:
        watch_state(state_file, args.task_id, args.interval)


if __name__ == "__main__":
    main()
