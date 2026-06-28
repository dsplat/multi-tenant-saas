# TASK-011: 文本 AI 服务

**Sprint:** sprint-003  
**状态:** READY  
**依赖:** TASK-010（AiGatewayService）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现 LLM 文本 AI 能力，至少接入 OpenAI 和智谱 GLM 两家提供商，支持聊天补全、文本补全、嵌入向量生成、JSON 模式输出和流式输出。

---

## 范围

**只允许修改：**
- `src/Services/AiTextService.php`（新建）
- `src/Services/Ai/OpenAiProvider.php`（新建）
- `src/Services/Ai/ZhipuProvider.php`（新建）
- `src/Models/AiPrompt.php`（新建）
- `database/migrations/` 下新增 ai_prompts 迁移
- `config/ai.php`（追加文本 AI 配置）
- `lang/zh_CN/ai.php`、`lang/en/ai.php`（追加翻译 key）
- `tests/AiTextServiceTest.php`（新建）
- `tests/TestCase.php`（追加 ai_prompts 表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### AiTextService

1. 聊天补全（单轮/多轮，system/user/assistant 角色）
2. 文本补全
3. 嵌入向量生成（支持批量）
4. JSON 模式输出
5. 流式输出（SSE）
6. 提示词管理：模板 CRUD、变量占位符、分类、版本管理、租户自定义

### OpenAiProvider

实现 AiProviderContract：GPT-4o/4o-mini/4-turbo，API Key 鉴权(Bearer)，请求格式适配，错误处理，流式响应解析

### ZhipuProvider

实现 AiProviderContract：GLM-4/4-Flash，JWT 鉴权，请求格式适配，错误处理

### 数据模型

`ai_prompts` 表: 租户ID(null=系统级)、名称、分类、系统提示词、用户提示词模板、变量定义(JSON)、版本号、状态

预置 4 个系统级模板：通用助手、客服助手、代码助手、翻译助手

> Function Calling/Tool Use 暂不实现，初期聚焦补全/嵌入/流式

> **⚠ 文件共享警告**: TASK-011/012/013/014b 共享 config/ai.php 和 lang 文件。建议串行执行。

---

## 验收标准

- [ ] AiTextService 聊天补全/文本补全/嵌入向量/JSON模式/流式输出均可用
- [ ] OpenAiProvider 和 ZhipuProvider 均实现 AiProviderContract
- [ ] 提示词模板 CRUD 正常，变量替换工作
- [ ] 4 个预置系统模板已创建
- [ ] 所有 AI 请求通过 AiGatewayService 记录
- [ ] TestCase 追加 ai_prompts schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- Provider 放在 src/Services/Ai/ 目录
- AiTextService 通过 AiGatewayService 调用提供商（不直接 HTTP）
- AiPrompt 模型 use HasTenantScope（系统级 tenant_id 为 null）
- 嵌入向量维度取决于模型，存储为 JSON 数组
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
