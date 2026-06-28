# TASK-017 修复指令（第 1 次重试）

## 上次失败原因
scope_overflow：DEV 使用 git add -A 将工作区 179 个脏文件（含前序任务产物、sprint-004 其他任务代码、.ai 脚本等）全部扫入提交，且无执行日志、无 REVIEW 输出，状态机冻结在 DEV

## 修复要求
只 git add 明确列出的 ~10 个目标文件，禁止 git add -A 或 git add .；提交前运行 git diff --cached --name-only | wc -l 验证变更数不超过 15；先清理工作区脏文件（git stash 或分批 commit 其他任务产物）再执行本任务

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
