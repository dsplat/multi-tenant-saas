# TASK-019: Webhook 系统

**Sprint:** sprint-005  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现 Webhook 系统，支持事件注册、签名验证、重试机制和交付日志。

---

## 范围

**只允许修改：**
- `src/Services/WebhookService.php`（新建）
- `src/Models/Webhook.php`（新建）
- `src/Models/WebhookDelivery.php`（新建）
- `src/Jobs/ProcessWebhookDelivery.php`（新建）
- `database/migrations/` 下新增 webhooks、webhook_deliveries 迁移
- `config/tenancy.php`（追加 Webhook 配置）
- `routes/api.php`（追加 Webhook 管理路由）
- `lang/zh_CN/common.php`、`lang/en/common.php`（追加翻译 key）
- `tests/WebhookServiceTest.php`（新建）
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

### WebhookService

1. 事件类型注册（tenant.created、user.registered、payment.succeeded 等）
2. Webhook URL 注册/管理
3. 签名验证（HMAC-SHA256）
4. 重试机制（指数退避，最多 5 次）
5. 交付日志
6. 手动重发

### ProcessWebhookDelivery Job

异步发送 Webhook，HTTP POST 到目标 URL，记录响应状态码和耗时

### 数据模型

1. `webhooks` 表: 租户ID、URL、事件类型、secret、是否激活、描述
2. `webhook_deliveries` 表: Webhook ID、事件类型、请求体、响应状态码、响应体、耗时、重试次数、状态

### 预定义事件

tenant.created、tenant.suspended、tenant.deleted、user.registered、user.logged_in、payment.succeeded、payment.failed、subscription.created、subscription.renewed、subscription.cancelled、ai.request.completed

---

## 验收标准

- [ ] Webhook 注册/管理正常
- [ ] 签名验证正常（HMAC-SHA256）
- [ ] 重试机制正常（指数退避）
- [ ] 交付日志完整
- [ ] 手动重发正常
- [ ] 异步 Job 正常工作
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- Webhook 模型 use HasTenantScope
- 签名使用 HMAC-SHA256，secret 在创建时生成
- 重试使用 Laravel Queue 的 delay 和 attempts
- 与 Laravel 原生 Event 系统集成（监听事件 → 触发 Webhook）

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
