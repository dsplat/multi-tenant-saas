#!/usr/bin/env python3
from __future__ import annotations
"""
任务执行器 - Python 版本

替代 loop-run.sh，提供更可靠的跨平台支持

用法:
    python3 run.py TASK-001
    AUTO_SPLIT=1 python3 run.py TASK-001
"""

import os
import sys
import subprocess
from pathlib import Path
from typing import Optional

# 添加 lib 到路径
sys.path.insert(0, str(Path(__file__).parent))

from lib import (
    CLIExecutor,
    StateManager,
    TaskSplitter,
    parse_task_file,
    log, ok, warn, fail, info,
)
from lib.executor import run_claude, run_opencode, run_mimo, TimeoutError


# 配置
MAX_LOOPS = 3
TIMEOUTS = {
    "split": 120,
    "dev": 300,
    "review": 180,
    "fix": 240,
}


def get_project_dir() -> Path:
    """获取项目根目录"""
    result = subprocess.run(
        ["git", "rev-parse", "--show-toplevel"],
        capture_output=True, text=True
    )
    if result.returncode == 0:
        return Path(result.stdout.strip())
    return Path.cwd()


def should_pre_split(task_file, auto_split: bool) -> bool:
    """判断是否需要 pre-split"""
    if task_file.is_subtask:
        return False
    if not auto_split:
        return False
    return task_file.auto_split


def run_dev(task_id: str, project_dir: Path, dev_prompt: str, task_content: str, 
            model: str = "bailian/glm-5.2", allowed_files=None) -> bool:
    """执行 DEV 步骤"""
    log(f"=== STEP 1: DEV ({task_id}) ===")
    
    prompt = f"""{dev_prompt}

---
{task_content}"""
    
    # 检查是否有修复指令（来自 guardian 的自动诊断）
    fix_file = project_dir / ".ai" / "tasks" / f"{task_id}-fix.md"
    if fix_file.exists():
        fix_content = fix_file.read_text(encoding='utf-8')
        prompt += f"""

---
## 重要：上次执行失败，请严格按以下修复指令操作

{fix_content}
"""
        warn(f"已注入修复指令: {fix_file}")
    
    try:
        output, code = run_opencode(
            prompt, 
            model=model,
            timeout=TIMEOUTS["dev"],
            cwd=project_dir,
            title=f"{task_id}-dev"
        )
        
        if code != 0:
            fail(f"DEV 步骤失败，退出码: {code}")
            return False
        
        ok("DEV 完成")
        
        # DEV 后：精确 git add（只 add 允许文件 + .ai/）
        git_add_allowed(project_dir, allowed_files)
        
        # DEV 后：范围溢出检测
        if allowed_files:
            violations = check_scope_violation(project_dir, allowed_files)
            if violations:
                warn(f"范围溢出：AI 修改了不在允许列表中的文件：")
                for f in violations:
                    warn(f"  - {f}")
        
        return True
        
    except TimeoutError as e:
        fail(str(e))
        return False
    except FileNotFoundError as e:
        fail(str(e))
        return False


def git_add_allowed(project_dir: Path, allowed_files=None) -> None:
    """DEV/FIX 后只 add 允许文件 + .ai/ 文件，避免脏工作区污染"""
    # 先清空 staging area
    subprocess.run(
        ["git", "reset", "HEAD", "--", "."],
        cwd=project_dir, capture_output=True, text=True
    )
    
    added = []
    
    # 1. add .ai/ 目录（状态管理文件）
    subprocess.run(
        ["git", "add", ".ai/"],
        cwd=project_dir, capture_output=True, text=True
    )
    added.append(".ai/")
    
    # 2. 如果有允许文件列表，只 add 这些文件
    if allowed_files:
        for f in allowed_files:
            filepath = project_dir / f
            if filepath.exists():
                subprocess.run(
                    ["git", "add", f],
                    cwd=project_dir, capture_output=True, text=True
                )
                added.append(f)
    else:
        # 没有明确列表时，add 所有 src/ 和 database/ 和 config/ 和 tests/ 下的变更
        for pattern in ["src/", "database/", "config/", "tests/", "lang/", "routes/"]:
            subprocess.run(
                ["git", "add", pattern],
                cwd=project_dir, capture_output=True, text=True
            )
            added.append(pattern)
    
    ok(f"git add 完成（精确模式）: {', '.join(added[:5])}")


def check_scope_violation(project_dir: Path, allowed_files) -> list:
    """检查 DEV 是否修改了不在允许列表中的文件"""
    result = subprocess.run(
        ["git", "diff", "--cached", "--name-only"],
        cwd=project_dir,
        capture_output=True, text=True
    )
    if result.returncode != 0:
        return []
    
    changed_files = set(result.stdout.strip().split('\n'))
    changed_files.discard('')
    
    # 允许的文件集合
    allowed_set = set(allowed_files)
    
    # 排除 .ai/ 目录下的文件（状态管理文件不算溢出）
    violations = []
    for f in sorted(changed_files):
        if f.startswith('.ai/'):
            continue
        if f not in allowed_set:
            violations.append(f)
    
    return violations


def run_review(task_id: str, project_dir: Path, review_prompt: str, 
               task_content: str, diff: str, review_file: Path) -> bool:
    """执行 REVIEW 步骤"""
    log("=== STEP 2: REVIEW ===")
    
    prompt = f"""{review_prompt}

---
## Task
{task_content}

---
## Code Changes
```diff
{diff}
```"""
    
    try:
        output = run_claude(prompt, timeout=TIMEOUTS["review"], cwd=project_dir)
        
        if not output:
            fail("REVIEW 无输出")
            return False
        
        review_file.write_text(output, encoding='utf-8')
        ok(f"REVIEW 完成，结果写入: {review_file}")
        
        # 解析 Verdict 段落判断是否通过
        passed = _parse_verdict(output)
        if not passed:
            warn("REVIEW 判定: FAIL")
        else:
            ok("REVIEW 判定: PASS")
        return passed
        
    except TimeoutError as e:
        fail(str(e))
        return False
    except FileNotFoundError as e:
        fail(str(e))
        return False


def run_fix(task_id: str, project_dir: Path, review_file: Path, allowed_files=None) -> bool:
    """执行 FIX 步骤"""
    log(f"=== STEP 3: FIX ===")
    
    prompt = """根据附件中的 Review 报告修复代码。

【要求】
- 只修复 Review 中明确列出的问题
- 禁止新增需求
- 禁止修改 Architecture
- 禁止修改无关模块"""
    
    try:
        output, code = run_mimo(
            prompt,
            review_file=review_file,
            timeout=TIMEOUTS["fix"],
            cwd=project_dir
        )
        
        if code != 0:
            fail(f"FIX 步骤失败，退出码: {code}")
            return False
        
        ok("FIX 完成")
        
        # FIX 后：精确 git add
        git_add_allowed(project_dir, allowed_files)
        
        ok("重新进入 REVIEW")
        return True
        
    except TimeoutError as e:
        fail(str(e))
        return False
    except FileNotFoundError as e:
        fail(str(e))
        return False


def get_git_diff(project_dir: Path, base: str = "HEAD") -> str:
    """获取 git diff（DEV 后已 git add，所以直接 diff --cached）"""
    result = subprocess.run(
        ["git", "diff", "--cached", base],
        cwd=project_dir,
        capture_output=True, text=True
    )
    # 如果 --cached 为空，尝试普通 diff
    if not result.stdout.strip():
        result = subprocess.run(
            ["git", "diff", base],
            cwd=project_dir,
            capture_output=True, text=True
        )
    return result.stdout if result.returncode == 0 else ""


def _parse_verdict(review_output: str) -> bool:
    """
    解析 REVIEW 输出的 Verdict 段落
    
    查找 ## Verdict 后面的 PASS/FAIL
    """
    import re
    # 查找 ## Verdict 段落
    verdict_match = re.search(
        r'##\s*Verdict\s*\n+(.*?)(?=\n##|\Z)',
        review_output,
        re.IGNORECASE | re.DOTALL
    )
    if verdict_match:
        verdict_text = verdict_match.group(1).strip().upper()
        # 检查是否包含 PASS（但非 FAIL）
        if 'FAIL' in verdict_text or 'REJECTED' in verdict_text:
            return False
        if 'PASS' in verdict_text:
            return True
    
    # 回退：检查全文的 Verdict 关键词
    # 找最后一个 PASS/FAIL 标记
    lines = review_output.strip().split('\n')
    for line in reversed(lines):
        upper = line.strip().upper()
        if upper.startswith('**FAIL**') or upper == 'FAIL':
            return False
        if upper.startswith('**PASS**') or upper == 'PASS':
            return True
    
    # 无法判断时默认失败
    return False


def main():
    """主入口"""
    import argparse
    
    parser = argparse.ArgumentParser(description="任务执行器")
    parser.add_argument("task_id", help="任务 ID，如 TASK-001")
    parser.add_argument("--model", default="bailian/glm-5.2", help="DEV 使用的模型")
    parser.add_argument("--auto-split", action="store_true", 
                       help="启用自动拆分（或设置 AUTO_SPLIT=1）")
    
    args = parser.parse_args()
    
    # 检查环境变量
    auto_split = args.auto_split or os.environ.get("AUTO_SPLIT") == "1"
    
    # 获取项目目录
    project_dir = get_project_dir()
    
    # 初始化
    state_file = project_dir / ".ai" / "state.json"
    state = StateManager(state_file)
    
    task_file_path = project_dir / ".ai" / "tasks" / f"{args.task_id}.md"
    review_file = project_dir / ".ai" / "review" / f"{args.task_id}-review.md"
    dev_prompt_file = project_dir / ".ai" / "prompts" / "dev-prompt.md"
    review_prompt_file = project_dir / ".ai" / "prompts" / "review-prompt.md"
    
    # 前置检查
    if not task_file_path.exists():
        fail(f"任务文件不存在: {task_file_path}")
        return 1
    
    if not dev_prompt_file.exists():
        fail(f"DEV prompt 不存在: {dev_prompt_file}")
        return 1
    
    if not review_prompt_file.exists():
        fail(f"REVIEW prompt 不存在: {review_prompt_file}")
        return 1
    
    # 检查 CLI 工具
    missing = CLIExecutor.check_tools("opencode", "claude", "mimo")
    if missing:
        fail(f"缺少 CLI 工具: {', '.join(missing)}")
        return 1
    
    # 解析任务文件
    task = parse_task_file(task_file_path)
    if not task:
        fail(f"无法解析任务文件: {task_file_path}")
        return 1
    
    # Pre-split 检查
    if should_pre_split(task, auto_split):
        warn("检测到 auto_split=ON，执行 pre-split...")
        state.update_task(args.task_id, "SPLITTING")
        
        splitter = TaskSplitter(project_dir)
        subtasks = splitter.split(task, timeout=TIMEOUTS["split"])
        
        if not subtasks:
            fail("任务拆分失败")
            return 1
        
        # 执行子任务（串行，递归调用）
        ok(f"拆分完成，开始串行执行 {len(subtasks)} 个子任务...")
        for st in subtasks:
            log(f"执行子任务: {st.id}")
            ret = subprocess.run(
                [sys.executable, str(Path(__file__).resolve()), st.id],
                cwd=project_dir,
            )
            if ret.returncode != 0:
                fail(f"子任务 {st.id} 失败，停止后续子任务")
                return 1
        
        ok(f"所有子任务执行完成")
        return 0
    
    # 主流程
    log(f"=== 开始执行: {args.task_id} ===")
    state.update_task(args.task_id, "DEV")
    
    # 获取 DEV 前的 commit
    result = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=project_dir, capture_output=True, text=True
    )
    git_base = result.stdout.strip() if result.returncode == 0 else ""
    
    # DEV
    dev_prompt = dev_prompt_file.read_text(encoding='utf-8')
    if not run_dev(args.task_id, project_dir, dev_prompt, task.content, args.model,
                   allowed_files=task.allowed_files):
        state.update_task(args.task_id, "DEV_FAILED")
        return 1
    
    state.update_task(args.task_id, "REVIEW")
    
    # REVIEW ↔ FIX 循环
    review_prompt = review_prompt_file.read_text(encoding='utf-8')
    
    for loop in range(MAX_LOOPS):
        log(f"=== STEP 2: REVIEW 第 {loop + 1} 轮 ===")
        
        diff = get_git_diff(project_dir, git_base)
        if not diff:
            warn("git diff 为空，代码可能未发生变更")
        
        if run_review(args.task_id, project_dir, review_prompt, task.content, diff, review_file):
            state.update_task(args.task_id, "TEST")
            ok(f"任务 {args.task_id} REVIEW 通过！")
            return 0
        
        warn(f"REVIEW FAIL — 进入第 {loop + 1} 次修复")
        state.update_task(args.task_id, "FIX_REQUESTED")
        
        if not run_fix(args.task_id, project_dir, review_file, allowed_files=task.allowed_files):
            state.update_task(args.task_id, "FIX_FAILED")
            return 1
    
    # 循环结束仍未通过
    fail(f"任务 {args.task_id} 经过 {MAX_LOOPS} 轮仍未通过 REVIEW")
    state.update_task(args.task_id, "FAILED")
    
    print("""
处理方式（三选一）：
  A. 手动修复后重新运行
  B. 使用 claude 重新规划
  C. 拆分为子任务：AUTO_SPLIT=1 python3 run.py TASK-XXX
""")
    
    return 1


if __name__ == "__main__":
    sys.exit(main())
