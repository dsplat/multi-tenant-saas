# TASK-028: 租户加密密钥与白标

**Sprint:** sprint-007  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现租户级加密密钥管理（AES-256、密钥轮换、BYOK）和白标定制（Logo、配色、自定义域名）。

---

## 范围

**只允许修改：**
- `src/Services/TenantKeyService.php`（新建）
- `src/Services/BrandingService.php`（新建）
- `src/Models/TenantKey.php`（新建）
- `src/Models/BrandingConfig.php`（新建）
- `database/migrations/` 下新增 tenant_keys、branding_configs 迁移
- `config/tenancy.php`（追加加密密钥和白标配置）
- `lang/zh_CN/tenant.php`、`lang/en/tenant.php`（追加翻译 key）
- `tests/TenantKeyServiceTest.php`（新建）
- `tests/BrandingServiceTest.php`（新建）
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

### TenantKeyService

1. 每个租户独立的 AES-256 加密密钥
2. 密钥轮换（re-encrypt 已有数据）
3. BYOK（Bring Your Own Key）支持
4. 密钥安全存储（使用系统主密钥加密租户密钥）

### BrandingService

1. 租户自定义 Logo（上传 + CDN）
2. 主色调/辅助色
3. 自定义域名
4. 登录页样式
5. 邮件模板品牌化
6. favicon

### 数据模型

1. `tenant_keys` 表: 租户ID、加密密钥(encrypted)、密钥类型、轮换时间、状态
2. `branding_configs` 表: 租户ID、logo_url、primary_color、secondary_color、custom_css、custom_domain

---

## 验收标准

- [ ] 租户密钥生成正常
- [ ] 密钥轮换正常（re-encrypt）
- [ ] BYOK 支持正常
- [ ] 密钥安全存储正常（系统主密钥加密）
- [ ] Logo 上传/CDN 正常
- [ ] 配色/自定义域名正常
- [ ] 邮件模板品牌化正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- TenantKey 和 BrandingConfig 模型 use HasTenantScope
- 系统主密钥从 .env 读取（APP_MASTER_KEY）
- AES-256-CBC 加密，使用 OpenSSL
- 密钥轮换是 CPU 密集型操作，使用队列异步
- Logo 上传使用现有 FileUploadService

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
