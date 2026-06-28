# TASK-016: 密码策略与 SSO/SAML

**Sprint:** sprint-004  
**状态:** READY  
**依赖:** TASK-015（共享 AuthController + routes/api.php，必须串行）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现密码策略管理和 SSO/SAML 集成，达到企业级认证标准。

---

## 范围

**只允许修改：**
- `src/Services/PasswordPolicyService.php`（新建）
- `src/Services/SsoService.php`（新建）
- `src/Models/SsoProvider.php`（新建）
- `database/migrations/` 下新增 password_history、sso_providers 迁移
- `database/migrations/` 下追加 User 表字段（password_changed_at、login_attempts、locked_until）
- `config/socialite.php`（追加 SAML 配置）
- `app/Http/Controllers/Api/AuthController.php`（追加 SSO 端点）
- `routes/api.php`（追加 SSO 路由）
- `lang/zh_CN/auth.php`、`lang/en/auth.php`（追加翻译 key）
- `tests/PasswordPolicyServiceTest.php`（新建）
- `tests/SsoServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表/字段 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### PasswordPolicyService

1. 最小长度、复杂度（大写/小写/数字/特殊字符）
2. 过期天数
3. 历史禁止重复（最近 N 次）
4. 暴力破解锁定（N 次失败锁定 M 分钟）
5. 密码强度评分

### SsoService

1. SAML 2.0 Service Provider
2. OIDC 集成
3. IdP 元数据管理
4. 租户级 SSO 配置（每个租户可配置自己的 IdP）
5. Just-In-Time 用户创建
6. 属性映射

### 数据模型

1. `password_histories` 表: 用户ID、密码 hash、创建时间
2. `sso_providers` 表: 租户ID、类型(saml/oidc)、名称、元数据URL、证书、属性映射(JSON)、状态
3. User 模型追加: password_changed_at、login_attempts、locked_until

---

## 验收标准

- [ ] 密码策略校验正常（长度/复杂度/历史/过期）
- [ ] 暴力破解锁定正常
- [ ] 密码强度评分正常
- [ ] SAML 2.0 SP 功能正常
- [ ] OIDC 集成正常
- [ ] 租户级 SSO 配置正常
- [ ] Just-In-Time 用户创建正常
- [ ] TestCase 追加新表/字段，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- SAML 可使用 lightsaml/lightsaml 库
- 密码历史存储 hash，不存储明文
- SSO 路由不需要认证中间件（回调在登录前）
- AuthController 只追加 SSO 端点，不修改已有方法
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
