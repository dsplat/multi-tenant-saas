# TASK-011 修复指令（第 4 次重试）

## 上次失败原因
SQLite 并发写锁冲突——TASK-011 的 4 个子任务被并行派发，3 个命中 'database is locked'；重试虽已生成全部文件，但未运行测试验证且未提交 git

## 修复要求
对已生成的全部文件运行 php artisan test --filter=AiText 验证测试通过，检查 OpenAiProvider/ZhipuProvider 的方法签名与 AiProviderContract 一致，确认无误后提交 git

## 注意
- 严格遵守原任务的范围约束
- 只修复 REVIEW 指出的问题
- 不要引入新变更
