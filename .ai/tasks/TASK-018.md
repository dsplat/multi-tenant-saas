# TASK-018: GDPR 合规与数据保留

**Sprint:** sprint-004  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现 GDPR 合规工具（数据导出/擦除）、数据保留策略和同意管理。

---

## 范围

**只允许修改：**
- `src/Services/GdprService.php`（新建）
- `src/Services/RetentionService.php`（新建）
- `src/Services/ConsentService.php`（新建）
- `src/Models/Consent.php`（新建）
- `src/Models/DataRetentionPolicy.php`（新建）
- `database/migrations/` 下新增 consents、data_retention_policies 迁移
- `src/Console/Commands/ProcessDataRetention.php`（新建）
- `config/tenancy.php`（追加 GDPR 和数据保留配置）
- `lang/zh_CN/tenant.php`、`lang/en/tenant.php`（追加翻译 key）
- `tests/GdprServiceTest.php`（新建）
- `tests/RetentionServiceTest.php`（新建）
- `tests/ConsentServiceTest.php`（新建）
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

### GdprService

1. 用户数据导出（JSON，包含所有关联数据）
2. 数据擦除（软删除 + 关键字段匿名化）
3. 处理活动记录
4. 数据可移植性

### RetentionService

1. 数据保留期限配置（按数据类型）
2. 自动清理过期数据（定时任务）
3. 清理前通知
4. 豁免标记（法律/合规保留）

### ConsentService

1. Cookie 同意记录
2. 数据处理同意
3. 营销同意
4. 条款版本追踪
5. 同意撤回

### 数据模型

1. `consents` 表: 用户ID、租户ID、类型、版本、同意状态、IP、时间
2. `data_retention_policies` 表: 数据类型、保留天数、是否自动清理、清理策略(删除/匿名化)

### ProcessDataRetention 命令

每天运行，检查过期数据并清理

---

## 验收标准

- [ ] 数据导出功能正常（JSON 格式，包含关联数据）
- [ ] 数据擦除功能正常（软删除 + 匿名化）
- [ ] 数据保留策略配置正常
- [ ] 自动清理功能正常
- [ ] 同意管理功能正常
- [ ] ProcessDataRetention 命令正常执行
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- 数据擦除不物理删除，使用软删除 + 字段匿名化（GDPR 要求）
- ProcessDataRetention 命令注册在 src/Console/Commands/ 目录
- 数据导出应包含用户的所有关联数据（tenants、sessions、api_tokens 等）
- 同意记录需要法律效力，记录 IP 和时间戳
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
