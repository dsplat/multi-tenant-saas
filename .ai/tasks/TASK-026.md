# TASK-026: 通知中心

**Sprint:** sprint-006  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现站内通知系统和实时广播推送能力。

---

## 范围

**只允许修改：**
- `src/Services/InAppNotificationService.php`（新建）
- `src/Services/BroadcastingService.php`（新建）
- `src/Models/InAppNotification.php`（新建）
- `src/Models/BroadcastEvent.php`（新建）
- `src/Notifications/GeneralNotification.php`（追加渠道）
- `database/migrations/` 下新增 in_app_notifications、broadcast_events 迁移
- `routes/api.php`（追加通知中心路由）
- `routes/channels.php`（追加广播频道定义）
- `lang/zh_CN/notification.php`、`lang/en/notification.php`（追加翻译 key）
- `tests/InAppNotificationServiceTest.php`（新建）
- `tests/BroadcastingServiceTest.php`（新建）
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

### InAppNotificationService

1. 站内通知列表
2. 已读/未读状态
3. 通知分类（系统/账单/AI/安全）
4. 批量标记已读
5. 通知偏好

### BroadcastingService

1. 基于 Laravel Reverb（或 Pusher/Soketi）实时推送
2. 租户级频道订阅
3. AI 视频生成完成通知
4. 系统公告实时推送
5. 在线状态广播

### 数据模型

1. `in_app_notifications` 表: 租户ID、用户ID、类型、标题、内容、链接、是否已读、已读时间
2. `broadcast_events` 表: 租户ID、事件类型、频道名称、负载数据、是否已发送

### 扩展 GeneralNotification

追加多渠道：邮件 + 站内 + 短信（已有），为后续 Push 和 WebSocket 预留接口

---

## 验收标准

- [ ] 站内通知 CRUD 正常
- [ ] 已读/未读状态正常
- [ ] 批量标记已读正常
- [ ] 通知分类正常
- [ ] 实时广播推送正常
- [ ] 租户级频道正常
- [ ] GeneralNotification 多渠道正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- InAppNotification 模型 use HasTenantScope
- BroadcastingService 注册为 singleton
- 广播频道命名: private-tenant.{tenantId}.{userId}
- Reverb 配置在 config/ 中，如不可用降级为轮询
- GeneralNotification 只追加渠道，不修改已有逻辑

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
