"""任务拆分器 - 调用 claude 进行任务拆分"""
from __future__ import annotations

import re
from pathlib import Path
from typing import Optional
from dataclasses import dataclass

from .executor import run_claude
from .task import TaskFile
from .utils import log, ok, fail, info


@dataclass
class SubTask:
    """子任务"""
    id: str
    title: str
    allowed_files: list[str]
    forbidden: str
    estimated_hours: str
    depends_on: str
    content: str


class TaskSplitter:
    """
    任务拆分器
    
    调用 claude CLI 将大任务拆分为子任务
    """
    
    def __init__(self, project_dir: Path):
        self.project_dir = project_dir
        self.tasks_dir = project_dir / ".ai" / "tasks"
    
    def split(self, task: TaskFile, timeout: int = 120) -> list[SubTask]:
        """
        拆分任务
        
        Args:
            task: 要拆分的任务
            timeout: 超时秒数
            
        Returns:
            子任务列表
        """
        prompt = self._build_split_prompt(task)
        
        log("调用 claude 进行任务拆分...")
        output = run_claude(prompt, timeout=timeout, cwd=self.project_dir)
        
        if not output:
            fail("claude 未返回有效拆分结果")
            return []
        
        subtasks = self._parse_output(output, task.id)
        
        if not subtasks:
            fail("未能解析出有效子任务")
            return []
        
        ok(f"解析出 {len(subtasks)} 个子任务: {[s.id for s in subtasks]}")
        
        # 验证文件冲突
        if not self._validate_split(subtasks):
            fail("文件冲突检测失败，尝试重新拆分...")
            subtasks = self._retry_split(task, output, timeout)
            if not subtasks:
                fail("重新拆分仍有冲突")
                return []
            ok("重新拆分成功，文件无冲突")
        
        # 生成子任务文件
        for st in subtasks:
            self._save_subtask(st, task.id)
        
        return subtasks
    
    def _build_split_prompt(self, task: TaskFile) -> str:
        """构建拆分 prompt"""
        return f"""你是任务拆分专家。以下是完整的 Task 定义：

{task.content}

---
请将此 Task 拆分为 2-4 个独立的子任务，按 Phase/步骤边界拆分。

【硬性约束】
1. 每个子任务只涉及 1-3 个文件
2. 文件范围必须互不重叠：同一文件绝对不能出现在两个子任务中
3. 每个子任务不超过 2 小时
4. 有依赖关系的子任务标注依赖（如 依赖: {task.id}a）

【文件依赖分析】
拆分前，先分析各文件之间的依赖关系：
- 哪些文件互相 import/use？
- 哪些文件共享同一个 Model 或 Service？
- 哪些文件必须一起修改才能通过测试？
→ 有强依赖的文件必须放在同一个子任务中

【冲突自检】
拆分后，列出每个子任务的文件列表，逐一检查是否有重叠。
如果发现同一文件出现在多个子任务中 → 重新调整拆分方案。

输出格式（严格按此格式，每个 SUBTASK 块之间空行分隔）：

## 文件依赖分析
[简要分析]

## 冲突自检
{task.id}a: file1.php, file2.php
{task.id}b: file3.php
结论: 无重叠 ✓

SUBTASK: {task.id}a
目标: [一句话目标]
只允许修改:
- [文件路径1]
- [文件路径2]
禁止: 修改其他文件、新增依赖
预估时间: [X] 小时
依赖: 无

SUBTASK: {task.id}b
目标: [一句话目标]
只允许修改:
- [文件路径]
禁止: 修改其他文件、新增依赖
预估时间: [X] 小时
依赖: 无（或 {task.id}a）"""
    
    def _parse_output(self, output: str, parent_id: str) -> list[SubTask]:
        """解析 claude 输出"""
        subtasks = []
        current_id = ""
        current_lines = []
        
        for line in output.split('\n'):
            # 匹配 SUBTASK: TASK-010a 或 ## SUBTASK: TASK-010a
            match = re.match(r'^[#*\s]*SUBTASK[:*\s]+(.+)', line)
            if match:
                # 保存上一个
                if current_id and current_lines:
                    st = self._parse_subtask_block(current_id, '\n'.join(current_lines), parent_id)
                    if st:
                        subtasks.append(st)
                current_id = match.group(1).strip()
                current_lines = []
            elif current_id:
                current_lines.append(line)
        
        # 保存最后一个
        if current_id and current_lines:
            st = self._parse_subtask_block(current_id, '\n'.join(current_lines), parent_id)
            if st:
                subtasks.append(st)
        
        return subtasks
    
    def _parse_subtask_block(self, subtask_id: str, content: str, parent_id: str) -> Optional[SubTask]:
        """解析单个子任务块"""
        # 提取目标
        title_match = re.search(r'目标[:：]\s*(.+)', content)
        title = title_match.group(1).strip() if title_match else ""
        
        # 提取文件列表
        files = re.findall(r'-\s*[`]?([^\s`]+)[`]?', content)
        files = [f for f in files if '/' in f or f.endswith('.php')]
        
        # 提取依赖
        dep_match = re.search(r'依赖[:：]\s*(.+)', content)
        depends_on = dep_match.group(1).strip() if dep_match else "无"
        
        # 提取预估时间
        time_match = re.search(r'预估时间[:：]\s*(.+)', content)
        estimated_hours = time_match.group(1).strip() if time_match else ""
        
        return SubTask(
            id=subtask_id,
            title=title,
            allowed_files=files,
            forbidden="修改其他文件、新增依赖",
            estimated_hours=estimated_hours,
            depends_on=depends_on,
            content=content.strip(),
        )
    
    def _validate_split(self, subtasks: list[SubTask]) -> bool:
        """验证子任务文件是否冲突"""
        seen_files: dict[str, str] = {}
        
        for st in subtasks:
            for f in st.allowed_files:
                if f in seen_files:
                    fail(f"文件冲突: {f} 出现在 {seen_files[f]} 和 {st.id}")
                    return False
                seen_files[f] = st.id
        
        ok(f"文件冲突检测通过：{len(seen_files)} 个文件均无重叠")
        return True
    
    def _retry_split(self, task: TaskFile, prev_output: str, timeout: int) -> list[SubTask]:
        """重新拆分"""
        prompt = f"""上一次拆分存在文件冲突，请重新拆分。

原始 Task:
{task.content}

上一次拆分结果（有问题）:
{prev_output}

问题：某些文件同时出现在多个子任务中。
请重新拆分，确保每个文件只属于一个子任务。

输出格式同上：SUBTASK 块 + 只允许修改 + 文件列表"""
        
        output = run_claude(prompt, timeout=timeout, cwd=self.project_dir)
        if not output:
            return []
        
        subtasks = self._parse_output(output, task.id)
        if not subtasks:
            return []
        
        if not self._validate_split(subtasks):
            return []
        
        # 保存子任务文件
        for st in subtasks:
            self._save_subtask(st, task.id, retry=True)
        
        return subtasks
    
    def _save_subtask(self, st: SubTask, parent_id: str, retry: bool = False) -> None:
        """保存子任务文件"""
        suffix = " (retry)" if retry else ""
        content = f"""# {st.id}: [Auto-split from {parent_id}{suffix}]

目标: {st.title}
只允许修改:
{chr(10).join(f'- `{f}`' for f in st.allowed_files)}
禁止: {st.forbidden}
预估时间: {st.estimated_hours}
依赖: {st.depends_on}

**具体内容:**
{st.content}

## 状态
READY
"""
        task_file = self.tasks_dir / f"{st.id}.md"
        task_file.write_text(content, encoding='utf-8')
        ok(f"生成子任务: {task_file}")
