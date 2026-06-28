# TASK-010b 修复指令（第 4 次重试）

## 上次失败原因
DEV agent 在脏工作区执行了 git add ./git add -A，将其他任务的 152 个未提交文件一并混入 commit，违反了'只允许修改 6 个文件'的硬约束

## 修复要求
写完 6 个文件后，必须使用 git add <精确路径> 逐个添加这 6 个文件，禁止 git add . 或 git add -A；commit 前用 git diff --cached --name-only 验证暂存区只有这 6 个文件

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
