"""任务文件解析"""
from __future__ import annotations

import re
from pathlib import Path
from dataclasses import dataclass, field
from typing import Optional


@dataclass
class TaskFile:
    """解析后的任务文件"""
    id: str
    title: str
    content: str
    
    # 元数据
    sprint: str = ""
    status: str = ""
    depends_on: str = ""
    auto_split: bool = False
    human_confirm: bool = False
    
    # 范围
    allowed_files: list[str] = field(default_factory=list)
    forbidden_files: list[str] = field(default_factory=list)
    
    # 验收标准
    acceptance_criteria: list[str] = field(default_factory=list)
    
    @property
    def is_subtask(self) -> bool:
        """是否是子任务（ID 以小写字母结尾）"""
        return bool(re.search(r'[a-z]$', self.id))


def parse_task_file(task_file: Path) -> Optional[TaskFile]:
    """
    解析任务文件
    
    Args:
        task_file: 任务文件路径
        
    Returns:
        TaskFile 对象，解析失败返回 None
    """
    if not task_file.exists():
        return None
    
    content = task_file.read_text(encoding='utf-8')
    lines = content.split('\n')
    
    # 提取 ID（从文件名）
    task_id = task_file.stem
    
    # 提取标题（第一个 # 开头的行）
    title = ""
    for line in lines:
        if line.startswith('# '):
            # 去掉 "# TASK-XXX: " 前缀
            title = re.sub(r'^#\s*(TASK-\d+[a-z]?)[:\s]*', '', line).strip()
            break
    
    # 解析元数据 (格式: **Key:** value)
    sprint = _extract_meta(content, r'\*\*Sprint:\*\*\s*(.+)')
    status = _extract_meta(content, r'\*\*状态:\*\*\s*(.+)')
    depends_on = _extract_meta(content, r'\*\*依赖:\*\*\s*(.+)')
    
    # Auto-split (格式: **Auto-split:** ON)
    auto_split_match = re.search(
        r'\*\*[Aa]uto[._-]?[Ss]plit:\*\*\s*(ON|TRUE|OFF|FALSE)',
        content,
        re.IGNORECASE
    )
    auto_split = auto_split_match and auto_split_match.group(1).upper() in ('ON', 'TRUE')
    
    # 人工确认
    human_confirm_match = re.search(
        r'人工确认\s*[:：]\s*(ON|TRUE|OFF|FALSE)',
        content,
        re.IGNORECASE
    )
    human_confirm = human_confirm_match and human_confirm_match.group(1).upper() in ('ON', 'TRUE')
    
    # 解析允许修改的文件
    allowed_files = _extract_file_list(content, r'\*\*只允许修改[：:]\*\*\s*\n(.*?)(?=\*\*禁止修改|\n---)', re.DOTALL)
    
    # 解析禁止修改的文件
    forbidden_files = _extract_file_list(content, r'\*\*禁止修改[：:]\*\*\s*\n(.*?)(?=\n---|\n##)', re.DOTALL)
    
    # 解析验收标准
    acceptance_criteria = []
    criteria_match = re.search(r'##\s*验收标准\s*\n(.*?)(?=\n##|\n---)', content, re.DOTALL)
    if criteria_match:
        criteria_text = criteria_match.group(1)
        acceptance_criteria = re.findall(r'-\s*\[.\]\s*(.+)', criteria_text)
    
    return TaskFile(
        id=task_id,
        title=title,
        content=content,
        sprint=sprint,
        status=status,
        depends_on=depends_on,
        auto_split=auto_split,
        human_confirm=human_confirm,
        allowed_files=allowed_files,
        forbidden_files=forbidden_files,
        acceptance_criteria=acceptance_criteria,
    )


def _extract_meta(content: str, pattern: str) -> str:
    """提取元数据字段"""
    match = re.search(pattern, content)
    if match:
        return match.group(1).strip()
    return ""


def _extract_file_list(content: str, pattern: str, flags: int = 0) -> list[str]:
    """提取文件列表"""
    files = []
    match = re.search(pattern, content, flags)
    if match:
        block = match.group(1)
        # 匹配 - `path` 或 - path
        files = re.findall(r'-\s*[`]?([^\s`]+(?:/[^\s`]+)*)[`]?', block)
    return files


def get_task_file(project_dir: Path, task_id: str) -> Optional[Path]:
    """获取任务文件路径"""
    task_file = project_dir / ".ai" / "tasks" / f"{task_id}.md"
    if task_file.exists():
        return task_file
    return None
