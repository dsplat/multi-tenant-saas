"""CLI 工具执行器 - 高频轮询 + 按需 LLM 分析"""
from __future__ import annotations

import subprocess
import time
import os
import re
from typing import Optional, Tuple, Callable
from pathlib import Path

from .utils import log, ok, warn, fail, info


class TimeoutError(Exception):
    """命令执行超时"""
    pass


# 异常关键字：检测到这些时触发 LLM 分析
ANOMALY_KEYWORDS = [
    "error", "exception", "fatal", "panic", "traceback",
    "command not found", "permission denied", "timeout",
    "killed", "oom", "out of memory", "segfault",
    "api key", "unauthorized", "rate limit", "429",
]


class CLIExecutor:
    """
    CLI 工具执行器
    
    特性：
    - 高频轮询（默认 20 秒一次）
    - 实时输出捕获
    - 异常关键字检测 → 按需 LLM 分析
    - 跨平台超时支持
    """
    
    def __init__(self, cwd: Optional[Path] = None):
        self.cwd = cwd or Path.cwd()
    
    def run(
        self,
        cmd: list[str],
        timeout: Optional[int] = None,
        poll_interval: int = 20,
        capture_output: bool = True,
        env: Optional[dict] = None,
        on_anomaly: Optional[Callable[[str], None]] = None,
        stdin_text: Optional[str] = None,
    ) -> Tuple[str, int]:
        """
        执行命令（高频轮询模式）
        
        Args:
            cmd: 命令列表
            timeout: 超时秒数
            poll_interval: 轮询间隔秒数（默认 20）
            capture_output: 是否捕获输出
            env: 环境变量
            on_anomaly: 异常检测回调，参数为异常上下文文本
            
        Returns:
            (output, return_code) 元组
        """
        full_env = os.environ.copy()
        if env:
            full_env.update(env)
        
        try:
            proc = subprocess.Popen(
                cmd,
                cwd=self.cwd,
                stdin=subprocess.PIPE if stdin_text else None,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                env=full_env,
            )
            # 写入 stdin（如果有）
            if stdin_text and proc.stdin:
                proc.stdin.write(stdin_text)
                proc.stdin.close()
        except FileNotFoundError:
            raise FileNotFoundError(f"命令不存在: {cmd[0]}")
        
        output_lines = []
        start_time = time.time()
        last_anomaly_check = start_time
        anomaly_already_reported = False
        
        while True:
            # 检查超时
            elapsed = time.time() - start_time
            if timeout and elapsed > timeout:
                proc.kill()
                proc.wait()
                raise TimeoutError(
                    f"命令超时 ({timeout}s): {' '.join(cmd[:3])}..."
                )
            
            # 读取可用输出（非阻塞）
            if proc.stdout:
                import select
                # 非阻塞读取所有可用行
                while True:
                    line = proc.stdout.readline()
                    if not line:
                        if proc.poll() is not None:
                            break
                        break
                    output_lines.append(line)
                    
                    # 实时打印（只打印关键信息，避免刷屏）
                    line_stripped = line.strip()
                    if line_stripped:
                        # 每 5 行打印一次进度点
                        if len(output_lines) % 5 == 0:
                            elapsed_m = int(elapsed) // 60
                            elapsed_s = int(elapsed) % 60
                            log(f"  ⏳ {elapsed_m}:{elapsed_s:02d} | {line_stripped[:80]}")
            
            # 检查进程是否结束
            if proc.poll() is not None:
                # 读取剩余输出
                remaining = proc.stdout.read() if proc.stdout else ""
                if remaining:
                    output_lines.append(remaining)
                break
            
            # 定期异常检测（每 poll_interval 秒一次）
            now = time.time()
            if (now - last_anomaly_check >= poll_interval 
                    and not anomaly_already_reported
                    and on_anomaly):
                recent_text = ''.join(output_lines[-50:])  # 最近 50 行
                anomalies = _detect_anomalies(recent_text)
                if anomalies:
                    warn(f"检测到异常关键字: {', '.join(anomalies[:3])}")
                    on_anomaly(recent_text)
                    anomaly_already_reported = True  # 只报告一次
                last_anomaly_check = now
            
            # 等待下一次轮询
            time.sleep(min(poll_interval, 2))  # 最多等 2 秒再检查
        
        output = ''.join(output_lines)
        return output, proc.returncode
    
    def run_with_retry(
        self,
        cmd: list[str],
        timeout: Optional[int] = None,
        retries: int = 0,
        retry_delay: float = 1.0,
        **kwargs,
    ) -> Tuple[str, int]:
        """执行命令，失败时重试"""
        for attempt in range(retries + 1):
            try:
                output, code = self.run(cmd, timeout=timeout, **kwargs)
                if code == 0:
                    return output, code
            except TimeoutError:
                if attempt >= retries:
                    raise
            except FileNotFoundError:
                raise
            
            if attempt < retries:
                time.sleep(retry_delay)
        
        return "", 1
    
    @staticmethod
    def check_tool(name: str) -> bool:
        """检查工具是否可用"""
        import shutil
        return shutil.which(name) is not None
    
    @staticmethod
    def check_tools(*names: str) -> list[str]:
        """检查多个工具，返回缺失的列表"""
        import shutil
        return [n for n in names if not shutil.which(n)]


def _detect_anomalies(text: str) -> list[str]:
    """检测文本中的异常关键字"""
    text_lower = text.lower()
    found = []
    for keyword in ANOMALY_KEYWORDS:
        if keyword in text_lower:
            found.append(keyword)
    return found


# =============================================================================
# 便捷函数
# =============================================================================

def run_claude(prompt: str, timeout: int = 180, cwd: Optional[Path] = None) -> str:
    """调用 claude CLI（长 prompt 用 stdin）"""
    executor = CLIExecutor(cwd=cwd)
    # 长 prompt 通过 stdin 传递，避免命令行参数长度限制
    if len(prompt) > 10000:
        output, code = executor.run(
            ["claude", "-p", "--output-format", "text"],
            timeout=timeout,
            stdin_text=prompt,
        )
    else:
        output, code = executor.run(
            ["claude", "-p", prompt, "--output-format", "text"],
            timeout=timeout,
        )
    return output if code == 0 else ""


def run_opencode(prompt: str, model: str = "bailian/glm-5.2", timeout: int = 300, 
                 cwd: Optional[Path] = None, title: str = "") -> Tuple[str, int]:
    """调用 opencode CLI"""
    executor = CLIExecutor(cwd=cwd)
    cmd = [
        "opencode", "run", prompt,
        "-m", model,
        "--dangerously-skip-permissions",
    ]
    if cwd:
        cmd.extend(["--dir", str(cwd)])
    if title:
        cmd.extend(["--title", title])
    
    output, code = executor.run(cmd, timeout=timeout)
    return output, code


def run_mimo(prompt: str, review_file: Optional[Path] = None, timeout: int = 240,
             cwd: Optional[Path] = None) -> Tuple[str, int]:
    """调用 mimo CLI"""
    executor = CLIExecutor(cwd=cwd)
    cmd = ["mimo", "run", prompt, "--dangerously-skip-permissions"]
    if review_file:
        cmd.extend(["-f", str(review_file)])
    if cwd:
        cmd.extend(["--dir", str(cwd)])
    
    output, code = executor.run(cmd, timeout=timeout)
    return output, code
