"""状态管理 - state.json 读写"""
from __future__ import annotations

import json
import datetime
from pathlib import Path
from typing import Optional, Any
from dataclasses import dataclass, field


@dataclass
class TaskState:
    """任务状态"""
    id: str
    status: str
    updated: str = ""
    title: str = ""
    sprint: str = ""
    note: str = ""
    
    def to_dict(self) -> dict:
        d = {"id": self.id, "status": self.status, "updated": self.updated}
        if self.title:
            d["title"] = self.title
        if self.sprint:
            d["sprint"] = self.sprint
        if self.note:
            d["note"] = self.note
        return d


class StateManager:
    """
    状态管理器
    
    管理 .ai/state.json 文件
    """
    
    def __init__(self, state_file: Path):
        self.state_file = state_file
        self._data: dict = {}
        self._load()
    
    def _load(self) -> None:
        """加载状态文件"""
        if self.state_file.exists():
            with open(self.state_file, 'r', encoding='utf-8') as f:
                self._data = json.load(f)
        else:
            self._data = {"version": "2.0", "tasks": []}
    
    def save(self) -> None:
        """保存状态文件"""
        self._data["updated"] = datetime.date.today().isoformat()
        with open(self.state_file, 'w', encoding='utf-8') as f:
            json.dump(self._data, f, indent=2, ensure_ascii=False)
            f.write('\n')
    
    def get_task(self, task_id: str) -> Optional[TaskState]:
        """获取任务状态"""
        for t in self._data.get("tasks", []):
            if t.get("id") == task_id:
                return TaskState(
                    id=t["id"],
                    status=t.get("status", ""),
                    updated=t.get("updated", ""),
                    title=t.get("title", ""),
                    sprint=t.get("sprint", ""),
                    note=t.get("note", ""),
                )
        return None
    
    def update_task(self, task_id: str, status: str, **kwargs) -> None:
        """更新任务状态"""
        now = datetime.datetime.now().isoformat()
        
        tasks = self._data.get("tasks", [])
        for t in tasks:
            if t.get("id") == task_id:
                t["status"] = status
                t["updated"] = now
                for k, v in kwargs.items():
                    if v is not None:
                        t[k] = v
                self.save()
                return
        
        # 任务不存在，新增
        new_task = {"id": task_id, "status": status, "updated": now}
        for k, v in kwargs.items():
            if v is not None:
                new_task[k] = v
        tasks.append(new_task)
        self._data["tasks"] = tasks
        self.save()
    
    def get_all_tasks(self) -> list[TaskState]:
        """获取所有任务"""
        return [
            TaskState(
                id=t["id"],
                status=t.get("status", ""),
                updated=t.get("updated", ""),
                title=t.get("title", ""),
                sprint=t.get("sprint", ""),
                note=t.get("note", ""),
            )
            for t in self._data.get("tasks", [])
        ]
    
    def is_done(self, task_id: str) -> bool:
        """检查任务是否完成"""
        task = self.get_task(task_id)
        return task is not None and task.status == "DONE"
    
    def check_dependency(self, depends_on: str) -> tuple[bool, str]:
        """
        检查依赖是否满足
        
        Returns:
            (是否满足, 状态描述)
        """
        if not depends_on or depends_on == "无":
            return True, "无依赖"
        
        task = self.get_task(depends_on)
        if task is None:
            return False, f"依赖任务 {depends_on} 不存在"
        if task.status == "DONE":
            return True, f"依赖 {depends_on} 已完成"
        return False, f"依赖 {depends_on} 状态为 {task.status}"
