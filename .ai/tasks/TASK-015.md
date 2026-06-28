# TASK-015: 多因素认证(MFA)与会话管理

**Sprint:** sprint-004  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现多因素认证（TOTP/Email/SMS）和会话管理（设备指纹、强制下线、异常检测）。

---

## 范围

**只允许修改：**
- `src/Services/MfaService.php`（新建）
- `src/Services/SessionService.php`（新建）
- `src/Models/MfaDevice.php`（新建）
- `src/Models/MfaRecoveryCode.php`（新建）
- `src/Models/UserSession.php`（新建）
- `database/migrations/` 下新增 mfa/session 迁移
- `app/Http/Controllers/Api/AuthController.php`（追加 MFA 端点）
- `app/Http/Controllers/Api/MfaController.php`（新建）
- `routes/api.php`（追加 MFA 路由）
- `lang/zh_CN/auth.php`、`lang/en/auth.php`（追加翻译 key）
- `tests/MfaServiceTest.php`（新建）
- `tests/SessionServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

> **⚠ 文件共享警告**: 与 TASK-016 共享 routes/api.php 和 AuthController。建议 TASK-016 串行依赖本任务。

---

## 具体内容

### MfaService

1. TOTP（Google Authenticator / 标准 TOTP）
2. Email 验证码
3. SMS 验证码
4. 恢复码生成/验证
5. MFA 设备管理（绑定/解绑/重命名）

### SessionService

1. 活跃会话列表
2. 设备指纹（User-Agent + IP）
3. 强制下线（单个/全部）
4. 异常登录检测（新设备/新IP 标记）
5. 会话超时配置

### 数据模型

1. `mfa_devices` 表: 用户ID、类型、secret、标签、是否主设备、最后使用时间
2. `mfa_recovery_codes` 表: 用户ID、恢复码(hash)、是否已使用、创建时间
3. `user_sessions` 表: 用户ID、session_id、IP、设备信息、登录时间、最后活跃时间、位置

---

## 验收标准

- [ ] TOTP 绑定/验证正常
- [ ] Email/SMS 验证码正常
- [ ] 恢复码生成/验证正常
- [ ] 活跃会话列表正常
- [ ] 强制下线正常
- [ ] 异常登录检测正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- TOTP 使用标准算法（RFC 6238），兼容 Google Authenticator
- 恢复码使用 hash 存储，明文只在生成时显示一次
- MfaController 需要认证中间件（已登录用户管理 MFA）
- AuthController 追加 MFA 验证端点（POST /api/auth/mfa/verify）
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
