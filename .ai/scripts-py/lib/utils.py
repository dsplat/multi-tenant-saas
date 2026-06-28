"""工具函数 - 日志输出、颜色等"""

import sys
from dataclasses import dataclass


@dataclass
class Colors:
    """终端颜色代码"""
    RED = '\033[0;31m'
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    CYAN = '\033[0;36m'
    NC = '\033[0m'  # No Color


def _print(color: str, prefix: str, message: str) -> None:
    """带颜色的日志输出"""
    print(f"{color}[loop-run] {prefix} {message}{Colors.NC}", file=sys.stderr)


def log(message: str) -> None:
    """普通日志"""
    _print(Colors.NC, "", message)


def ok(message: str) -> None:
    """成功日志"""
    _print(Colors.GREEN, "✓", message)


def warn(message: str) -> None:
    """警告日志"""
    _print(Colors.YELLOW, "⚠", message)


def fail(message: str) -> None:
    """失败日志"""
    _print(Colors.RED, "✗", message)


def info(message: str) -> None:
    """信息日志"""
    _print(Colors.CYAN, "", message)


def progress_bar(current: int, total: int, width: int = 30) -> str:
    """生成进度条字符串"""
    percent = current / total if total > 0 else 0
    filled = int(width * percent)
    bar = "█" * filled + "░" * (width - filled)
    return f"[{bar}] {current}/{total} ({percent:.0%})"
