# TASK-016 修复指令（第 1 次重试）

## 上次失败原因
TASK-016 从未完成执行——guardian 循环在处理前序任务(TASK-010b~015)的 scope_overflow 重试中耗尽预算，仅写入了源文件但未提交，状态卡在 DEV；181 文件 diff 是整个 sprint 累积的脏工作区，不是 TASK-016 的产物

## 修复要求
先 git reset 工作区（git checkout -- . && git clean -fd），将 TASK-016 状态重置为 READY，重新执行时在 prompt 中明确禁止 git add -A，必须使用精确文件路径 add 且只允许 add 本次任务范围内的 6+3 个文件

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
