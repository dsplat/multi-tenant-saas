# TASK-014c: AI API 层

**Sprint:** sprint-003  
**状态:** READY  
**依赖:** TASK-010、TASK-011、TASK-012、TASK-013、TASK-014、TASK-014b  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

为 AI 服务创建 RESTful API 端点，暴露文本/图片/视频/RAG 能力给 API 消费者。

---

## 范围

**只允许修改：**
- `app/Http/Controllers/Api/AiController.php`（新建）
- `app/Http/Controllers/Api/KnowledgeBaseController.php`（新建）
- `app/Http/Resources/AiChatResource.php`（新建）
- `app/Http/Resources/KnowledgeBaseResource.php`（新建）
- `routes/api.php`（追加 AI 路由）
- `lang/zh_CN/ai.php`、`lang/en/ai.php`（追加翻译 key）
- `tests/AiControllerTest.php`（新建）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `src/` 下所有文件（不修改 Service 层）
- `resources/` 前端资源
- `public/` 公共入口
- `database/` 数据库迁移

---

## 具体内容

### AiController 端点

| 方法 | 路由 | 说明 |
|------|------|------|
| POST | /api/ai/chat | 文本对话（支持流式 SSE） |
| POST | /api/ai/completion | 文本补全 |
| POST | /api/ai/image | 文生图 |
| POST | /api/ai/video | 文生视频（异步） |
| GET | /api/ai/video/{taskId} | 视频任务状态 |
| GET | /api/ai/models | 可用模型列表 |
| GET | /api/ai/usage | 用量查询 |
| PUT | /api/ai/config | 更新租户 AI 配置 |

### KnowledgeBaseController 端点

| 方法 | 路由 | 说明 |
|------|------|------|
| POST | /api/ai/knowledge-bases | 创建 KB |
| GET | /api/ai/knowledge-bases | KB 列表 |
| POST | /api/ai/knowledge-bases/{id}/documents | 上传文档 |
| POST | /api/ai/knowledge-bases/{id}/query | RAG 问答 |
| DELETE | /api/ai/knowledge-bases/{id}/documents/{docId} | 删除文档 |

### 请求验证

参数校验、模型可用性检查、租户 AI 能力开关检查、配额预检

### 响应格式

统一 JSON（ApiResponse::success/error）、错误码标准化、流式 SSE、速率限制（rate_limit_rpm）

---

## 验收标准

- [ ] 所有 API 端点可用，响应格式统一
- [ ] 流式 SSE 响应正常
- [ ] 请求验证正常（参数/模型/开关/配额）
- [ ] 速率限制正常
- [ ] API Resource 数据脱敏正常（API Key 不返回）
- [ ] phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- Controller 只调用 Service 层，不包含业务逻辑
- 所有响应必须使用 API Resource
- 流式响应使用 Laravel StreamedResponse
- 认证: Sanctum token
- 速率限制: throttle 中间件，按 rate_limit_rpm 动态配置
---

## 全局规范声明

> **⚠ 严格遵守全局约束 — 此部分适用于本任务的所有子任务（a/b/c/d...），无例外**

### 1. 禁止修改的文件

- **`.ai/scripts/` 目录下任何文件**（loop-run.sh、parallel-run.sh、loop-watch.sh、plan-task.sh、lib.sh 等）
- **`.ai/prompts/` 目录下任何文件**（dev-prompt.md、review-prompt.md、plan-prompt.md 等）
- 如 AI 在执行过程中发现需要修改上述文件，应**停止并向用户报告**，而不是自行修改

### 2. 编码规范

- 遵循 **PSR-12** 规范，使用 **Laravel 最佳实践**
- 所有 Controller 必须使用 **API Resource** 返回数据，禁止直接返回模型或数组
- 敏感字段（password/token/secret/key）**永不返回**，手机号脱敏
- 所有方法参数必须有**类型声明**，所有方法必须有**返回值类型声明**
- 使用 PHP 8.1+ 特性（枚举、只读属性等）
- 使用中文注释 + PHPDoc

### 3. 多语言规范

- 使用 `trans()` / `__()` 函数实现多语言，**禁止硬编码中文字符串**
- 新增翻译 key 必须同时添加到 `lang/zh_CN/` 和 `lang/en/` 两个目录

### 4. 数据库规范

- 迁移文件命名接续现有序号（查看 `database/migrations/` 最大序号后 +1）
- 新建模型 use `HasTenantScope` trait 实现租户隔离
- Service 类通过 `TenancyServiceProvider` 注册为 singleton

### 5. 响应格式

- 统一用 `ApiResponse::success()` 和 `ApiResponse::error()`
- 错误码标准化，HTTP 状态码正确

### 6. 测试规范

- 每个新建 Service 必须有对应的 Test 文件
- 测试继承 `tests/TestCase.php`，如需新表 schema 在 TestCase.php 中追加
- `php vendor/bin/phpunit` 全绿（预存在的失败除外，但不得新增失败）
