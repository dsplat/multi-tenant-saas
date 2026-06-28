# TASK-017: IP 白名单与设备信任

**Sprint:** sprint-004  
**状态:** READY  
**依赖:** TASK-015（SessionService 提供设备指纹基座）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现租户级 IP 白名单和设备信任管理。

---

## 范围

**只允许修改：**
- `src/Services/IpWhitelistService.php`（新建）
- `src/Services/TrustedDeviceService.php`（新建）
- `src/Models/IpWhitelist.php`（新建）
- `src/Models/TrustedDevice.php`（新建）
- `src/Middleware/CheckIpWhitelist.php`（新建）
- `database/migrations/` 下新增 ip_whitelists、trusted_devices 迁移
- `config/tenancy.php`（追加 IP 白名单配置）
- `lang/zh_CN/tenant.php`、`lang/en/tenant.php`（追加翻译 key）
- `tests/IpWhitelistServiceTest.php`（新建）
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

### IpWhitelistService

1. 租户级 IP 白名单（单个 IP / CIDR / IP 范围）
2. 白名单生效范围（全站/仅API/仅管理后台）
3. 白名单开启/关闭
4. IP 审计日志

### TrustedDeviceService

1. 设备信任（记住此设备 N 天免二次验证）
2. 设备指纹（IP + User-Agent 哈希）
3. 信任设备列表管理

### CheckIpWhitelist 中间件

在 IdentifyTenant 之后执行，检查请求 IP 是否在租户白名单中，未命中返回 403

### 数据模型

1. `ip_whitelists` 表: 租户ID、IP/CIDR、描述、生效范围、状态
2. `trusted_devices` 表: 用户ID、设备指纹、设备名称、IP、信任到期时间

---

## 验收标准

- [ ] IP 白名单 CRUD 正常
- [ ] CIDR/IP 范围匹配正常
- [ ] 白名单生效范围控制正常
- [ ] CheckIpWhitelist 中间件正常拦截
- [ ] 设备信任功能正常
- [ ] 信任设备列表管理正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- CheckIpWhitelist 中间件注册在 IdentifyTenant 之后
- CIDR 匹配使用 PHP 内置函数或纯 PHP 实现
- 设备指纹使用 IP + User-Agent 的 SHA256 哈希
- 信任到期后自动要求二次验证
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
