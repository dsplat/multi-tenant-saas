# TASK-021: PHP SDK 与开发者门户

**Sprint:** sprint-005  
**状态:** READY  
**依赖:** TASK-019、TASK-020  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现 PHP SDK 封装 API 调用，和开发者门户服务（API Key 管理、沙箱环境）。

---

## 范围

**只允许修改：**
- `src/SDK/Client.php`（新建）
- `src/SDK/Resources/TenantResource.php`（新建）
- `src/SDK/Resources/PaymentResource.php`（新建）
- `src/SDK/Resources/AiResource.php`（新建）
- `src/SDK/Exceptions/SdkException.php`（新建）
- `src/Services/DeveloperPortalService.php`（新建）
- `src/Services/SandboxService.php`（新建）
- `src/Models/SandboxEnvironment.php`（新建）
- `database/migrations/` 下新增 sandbox_environments 迁移
- `routes/api.php`（追加开发者门户路由）
- `lang/zh_CN/common.php`、`lang/en/common.php`（追加翻译 key）
- `tests/SdkTest.php`（新建）
- `tests/DeveloperPortalServiceTest.php`（新建）
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

### PHP SDK

1. 封装租户管理、支付、用户、AI 的 API 调用
2. API Key 鉴权
3. 链式调用接口
4. 类型安全的响应对象
5. 错误处理和重试

### DeveloperPortalService

1. API Key 管理（创建/吊销/权限范围）
2. API 使用统计
3. 文档集成

### SandboxService

1. 独立沙箱环境（sandbox_tenant_id 隔离）
2. 测试 API Key
3. 沙箱数据自动清理（24小时 TTL）

### 数据模型

`sandbox_environments` 表: 开发者ID、沙箱租户ID、API Key、创建时间、过期时间

---

## 验收标准

- [ ] SDK 链式调用正常
- [ ] API Key 鉴权正常
- [ ] 错误处理和重试正常
- [ ] API Key 管理（创建/吊销）正常
- [ ] 沙箱环境正常
- [ ] 沙箱数据自动清理正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- SDK 放在 src/SDK/ 目录
- SDK 不依赖 Laravel 框架，可独立使用（仅依赖 PHP 8.2+ 和 ext-curl）
- SandboxService 使用现有的 TenantContext 切换沙箱租户
- 沙箱数据清理使用队列延迟任务

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
