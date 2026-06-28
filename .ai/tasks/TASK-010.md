# TASK-010: AI 模型网关

**Sprint:** sprint-003  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现统一的 AI 模型网关，对上层屏蔽不同 AI 提供商的 API 差异，支持多提供商注册、模型路由、自动故障转移、负载均衡和流式输出。

---

## 范围

**只允许修改：**
- `src/Services/AiGatewayService.php`（新建）
- `src/Contracts/AiProviderContract.php`（新建）
- `src/Enums/AiModelEnum.php`（新建）
- `src/Models/AiProvider.php`（新建）
- `src/Models/AiRequest.php`（新建）
- `src/Models/AiModelAlias.php`（新建）
- `database/migrations/` 下新增 ai_providers、ai_requests、ai_model_aliases 迁移
- `config/ai.php`（新建 AI 配置文件）
- `src/TenancyServiceProvider.php`（注册 AiGatewayService singleton）
- `lang/zh_CN/ai.php`、`lang/en/ai.php`（新建翻译文件）
- `tests/AiGatewayServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### AiProviderContract

统一接口：`completion($model, $messages, $options): array`、`stream($model, $messages, $options): \Generator`、`getModels(): array`、`getPricing(): array`

### AiGatewayService

1. 提供商注册：动态注册多个 AI 提供商
2. 模型路由：根据模型名自动路由到对应提供商
3. 自动故障转移：主提供商失败 → 切换到备选
4. 负载均衡：多同类提供商间均衡请求
5. 流式输出(SSE)：Server-Sent Events
6. 请求/响应日志：记录到 ai_requests 表
7. 模型别名：通过 ai_model_aliases 表映射

### AiModelEnum

枚举所有模型（GPT-4o/4o-mini、Claude-3.5-Sonnet、GLM-4/4-Flash、DeepSeek-V3、DALL-E-3、SDXL、Runway-Gen3、Kling），标注类型/提供商/默认max_tokens/废弃标记

### 数据模型

1. `ai_providers` 表: 提供商名称、API基地址、默认API Key、状态、优先级
2. `ai_requests` 表: 租户ID、用户ID、模型名、提供商、请求内容摘要、Token用量(input/output)、响应时间、费用、状态
3. `ai_model_aliases` 表: 别名、实际模型名、提供商、类型(text/image/video)、是否激活、废弃标记

### 配置

`config/ai.php`: 默认提供商、默认模型、超时、重试策略、速率限制、流式开关、API版本

---

## 验收标准

- [ ] AiProviderContract 接口定义完整
- [ ] AiGatewayService 提供商注册/模型路由/故障转移正常
- [ ] 流式输出(SSE) 功能正常
- [ ] 模型别名映射工作正常
- [ ] ai_requests 日志记录完整
- [ ] config/ai.php 配置完整
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- AiGatewayService 注册为 singleton（TenancyServiceProvider）
- AiProvider 模型 use HasTenantScope（系统级配置 tenant_id 为 null）
- ai_requests 表必须有租户ID和用户ID，实现租户隔离
- 迁移文件命名接续现有序号
- config/ai.php 参考 config/pay.php 结构
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
