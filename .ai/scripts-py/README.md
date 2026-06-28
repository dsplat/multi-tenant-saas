# AI 任务自动化框架 - Python 版本

替代原有 bash 脚本，提供更可靠的跨平台支持。

## 优势

| 特性 | Bash 版本 | Python 版本 |
|------|-----------|-------------|
| 跨平台超时 | ❌ macOS 无 `timeout` | ✅ `subprocess.timeout` |
| JSON 处理 | ⚠️ 内嵌 python3 | ✅ 原生 `json` |
| 特殊字符 | ❌ 转义复杂 | ✅ 原生字符串 |
| 错误处理 | ⚠️ `set -euo pipefail` 陷阱多 | ✅ 完善的异常机制 |
| 扩展性 | ⚠️ 脚本越写越复杂 | ✅ 模块化设计 |

## 文件结构

```
scripts-py/
├── run.py          # 主入口，替代 loop-run.sh
├── parallel.py     # 并行执行，替代 parallel-run.sh
├── check.py        # ID 合规检查，替代 check-id-compliance.sh
└── lib/
    ├── __init__.py
    ├── executor.py # CLI 工具调用（带超时）
    ├── state.py    # state.json 管理
    ├── task.py     # Task 文件解析
    ├── splitter.py # 任务拆分
    └── utils.py    # 工具函数
```

## 使用方法

### 执行单个任务

```bash
# 基本用法
python3 .ai/scripts-py/run.py TASK-001

# 启用自动拆分
AUTO_SPLIT=1 python3 .ai/scripts-py/run.py TASK-001

# 或
python3 .ai/scripts-py/run.py TASK-001 --auto-split
```

### 并行执行子任务

```bash
python3 .ai/scripts-py/parallel.py TASK-001a TASK-001b TASK-001c

# 指定最大并行数
python3 .ai/scripts-py/parallel.py TASK-001a TASK-001b --max-workers 2
```

### ID 合规检查

```bash
# 检查所有文件
python3 .ai/scripts-py/check.py

# 只检查 git staged 文件
python3 .ai/scripts-py/check.py --staged
```

### 批量执行任务

```bash
# 顺序执行，失败即停
for i in $(seq 10 18); do
    python3 .ai/scripts-py/run.py TASK-$(printf "%03d" $i) || break
done
```

## 模块说明

### executor.py

CLI 工具执行器，核心功能：

```python
from lib.executor import CLIExecutor, run_claude, run_opencode, run_mimo

# 执行命令，带超时
executor = CLIExecutor()
output, code = executor.run(["claude", "-p", "prompt"], timeout=120)

# 便捷函数
output = run_claude("prompt", timeout=180)
output, code = run_opencode("prompt", model="bailian/glm-5.2")
output, code = run_mimo("prompt", review_file=Path("review.md"))
```

### state.py

状态管理：

```python
from lib.state import StateManager

state = StateManager(Path(".ai/state.json"))
state.update_task("TASK-001", "DEV")
task = state.get_task("TASK-001")
print(task.status)  # DEV
```

### task.py

任务文件解析：

```python
from lib.task import parse_task_file

task = parse_task_file(Path(".ai/tasks/TASK-001.md"))
print(task.title)        # 任务标题
print(task.auto_split)   # True/False
print(task.allowed_files)  # 允许修改的文件列表
```

### splitter.py

任务拆分：

```python
from lib.splitter import TaskSplitter

splitter = TaskSplitter(project_dir)
subtasks = splitter.split(task, timeout=120)
for st in subtasks:
    print(f"{st.id}: {st.title}")
```

## 迁移指南

从 bash 版本迁移：

| Bash | Python |
|------|--------|
| `.ai/scripts/loop-run.sh TASK-001` | `python3 .ai/scripts-py/run.py TASK-001` |
| `.ai/scripts/parallel-run.sh TASK-001a TASK-001b` | `python3 .ai/scripts-py/parallel.py TASK-001a TASK-001b` |
| `.ai/scripts/check-id-compliance.sh` | `python3 .ai/scripts-py/check.py` |

## 配置

超时配置在 `run.py` 中：

```python
TIMEOUTS = {
    "split": 120,   # 任务拆分
    "dev": 300,     # DEV 步骤
    "review": 180,  # REVIEW 步骤
    "fix": 240,     # FIX 步骤
}
```

## 依赖

- Python 3.10+
- CLI 工具：`opencode`, `claude`, `mimo`
