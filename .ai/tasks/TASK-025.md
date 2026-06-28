# TASK-025: 错误追踪与自定义报表

**Sprint:** sprint-006  
**状态:** READY  
**依赖:** TASK-023、TASK-024  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现错误追踪聚合和租户自定义报表功能。

---

## 范围

**只允许修改：**
- `src/Services/ErrorTrackingService.php`（新建）
- `src/Services/ReportService.php`（新建）
- `src/Models/CustomReport.php`（新建）
- `database/migrations/` 下新增 custom_reports 迁移
- `config/tenancy.php`（追加错误追踪和报表配置）
- `lang/zh_CN/common.php`、`lang/en/common.php`（追加翻译 key）
- `tests/ErrorTrackingServiceTest.php`（新建）
- `tests/ReportServiceTest.php`（新建）
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

### ErrorTrackingService

1. Sentry 集成（可选，通过配置开关）
2. 错误聚合（相同错误合并）
3. 错误影响面分析（影响多少租户/用户）
4. 错误趋势图
5. 错误通知

### ReportService

1. 租户自定义报表（选择指标+维度+时间范围）
2. 定时发送（日报/周报/月报）
3. 报表模板
4. 导出格式（PDF/Excel/CSV）

### 数据模型

`custom_reports` 表: 租户ID、名称、指标配置(JSON)、时间范围、发送频率、接收人

---

## 验收标准

- [ ] 错误聚合正常
- [ ] 错误影响面分析正常
- [ ] 错误趋势正常
- [ ] 自定义报表创建正常
- [ ] 定时发送正常
- [ ] 导出格式正常（PDF/Excel/CSV）
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- CustomReport 模型 use HasTenantScope
- Sentry 集成通过 composer 引入 sentry/sentry-laravel（可选）
- 报表导出使用现有 PdfService（PDF）和 PhpSpreadsheet（Excel）
- 定时发送使用 Laravel Scheduler

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
