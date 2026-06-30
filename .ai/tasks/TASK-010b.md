# TASK-010b: [Auto-split from TASK-010]

目标: 创建数据模型层——三个 Eloquent 模型 + 对应数据库迁移
只允许修改:
- `src/Models/AiProvider.php`（新建）
- `src/Models/AiRequest.php`（新建）
- `src/Models/AiModelAlias.php`（新建）
- `database/migrations/` 下新增 3 个迁移文件（ai_providers、ai_requests、ai_model_aliases）
禁止: 修改其他文件、新增依赖
预估时间: 1.5 小时
依赖: TASK-010a（枚举被 Model 引用）



## 状态
READY
