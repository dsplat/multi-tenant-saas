# TASK-014 修复指令（第 4 次重试）

## 上次失败原因
DEV agent 执行 git add -A 而非显式指定文件路径，脏工作区中 ~152 个无关文件被一并提交，触发 scope_overflow 拒绝；实际 commit 4321b5f 仅含 13 个文件且代码正确

## 修复要求
Run `git reset HEAD` to clear staging, then `git add` only the 13 task-specified files by explicit path — never use `git add -A` or `git add .`; the code commit already exists and is correct, skip code generation and proceed to REVIEW

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
