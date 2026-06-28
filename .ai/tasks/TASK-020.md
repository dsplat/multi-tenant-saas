# TASK-020: 事件总线

**Sprint:** sprint-005  
**状态:** READY  
**依赖:** TASK-019（WebhookService 作为外部订阅目标）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现事件总线，支持事件发布/订阅、异步分发和死信队列。

---

## 范围

**只允许修改：**
- `src/Services/EventBusService.php`（新建）
- `src/Models/EventSubscription.php`（新建）
- `src/Models/DeadLetter.php`（新建）
- `src/Jobs/DispatchEventJob.php`（新建）
- `database/migrations/` 下新增 event_subscriptions、dead_letters 迁移
- `config/tenancy.php`（追加事件总线配置）
- `lang/zh_CN/common.php`、`lang/en/common.php`（追加翻译 key）
- `tests/EventBusServiceTest.php`（新建）
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

### EventBusService

1. 事件发布/订阅
2. 内部订阅（Service 监听）和外部订阅（Webhook 分发）
3. 事件路由（按事件类型分发到订阅者）
4. 异步分发（通过队列）
5. 死信队列（处理失败事件）

### DispatchEventJob

异步事件分发，支持延迟、重试、失败转死信

### 数据模型

1. `event_subscriptions` 表: 租户ID、事件类型、订阅类型(internal/webhook)、处理器/URL、状态
2. `dead_letters` 表: 事件类型、原始数据、失败原因、重试次数、创建时间

### 集成

与 Laravel 原生 Event 系统集成，兼容现有 Events（TenantCreated、UserRegistered 等）

---

## 验收标准

- [ ] 事件发布/订阅正常
- [ ] 内部和外部订阅正常
- [ ] 事件路由正常
- [ ] 异步分发正常
- [ ] 死信队列正常
- [ ] 与 Laravel Event 系统集成正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- EventBusService 注册为 singleton
- 事件分发使用 Laravel Queue
- 死信队列重试次数达到上限后存入 dead_letters 表
- 与 TASK-019 WebhookService 集成：外部订阅自动触发 Webhook

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
