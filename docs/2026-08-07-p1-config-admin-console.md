# P1: AI/邮件配置后台化 + 安全修复

**会话时间**: 2026-08-07  
**范围**: 框架 multi_tenant_saas → neihang.com 生产部署  
**状态**: ✅ 已完成并验证

---

## 目标

将 AI 配置（默认模型、provider 连接）和平台邮件配置从 env/config 静态引导升级为 **admin 后台运行时可配**，同时修复审计日志/密钥管理的安全缺陷。

**核心设计**：`AiPlatformConfigService` 实现 DB 覆盖层（`system_settings` 表）优先于 env/config 的三级解析：
- DB（运行时覆盖）→ config/env（安装引导层）→ 硬编码兜底
- 即使 env 完全未配置 url/key，后台补录后 60s 内生效

---

## 实施清单

### P1s: 安全修复 ✅
- `SystemSettingController::index()` 加密项返回掩码 `********`
- `SystemSettingController::update()` 掩码回存跳过（避免覆盖真实密钥）
- `SystemSettingController` 审计日志脱敏（old/new 加密值均掩码）
- `SystemSetting::getGroupMasked()` 新增辅助方法
- `AdminSettingsController::ENCRYPTED_KEYS` 新增 `mail => [password]`

### P1a: 平台邮件接线 ✅
- `MailerService::resolvePlatformMailer()` 新增平台 SMTP 三级链：
  租户 SMTP (`tenant_settings`) → 平台 SMTP (`system_settings mail` 组) → env `MAIL_*`
- `buildPlatformMailer()` from 回退 `config('mail.from')`
- `Platform AdminSettingsController::sendTestMail()` 测试邮件 API
- `Platform/Routes/admin.php` 新增 `POST /settings/mail/test`
- `Platform/Settings.vue` (element-plus) mail tab 新增测试邮件发送 UI

### P1b: AI 后台化 ✅
- `AiPlatformConfigService` (新建)：静态平台级覆盖层，Cache 60s TTL
  - `resolveTextDefault()` / `resolveDefaultProvider()` / `resolveProviderConfig()`
  - DB 分组约定：`group='ai'`（默认模型组），`group='ai_provider_{code}'`（base_url/api_key 加密）
- `AdminAiController` (新建) + `Ai/Routes/admin.php`：
  - 别名 CRUD (`/admin/ai/aliases`)
  - 默认模型组 (`/admin/ai/defaults`) — 空值清除回退引导层
  - 租户 AI 配置 (`/admin/ai/tenants/{id}/config`)
  - 模型目录 (`/admin/ai/catalog`) — 各 provider `cachedModels()`
  - 目录同步 (`POST /admin/ai/catalog/sync`) — 直调 `AiModelCatalogService::sync()`
  - Provider 连接测试 (`POST /admin/ai/providers/{code}/test`)
- `Ai/resources/admin/ui/element-plus/views/AiSettings.vue` (新建，326 行，5 tabs)
- `Ai/resources/admin/ui/bootstrap/views/AiSettings.vue` (新建，189 行)
- 服务接线：`AiGatewayService` / `AiTextService` / `AiConfigService` / `BailianImageProvider` 均已接线 DB 覆盖层

### P1t: 测试 ✅
- `AdminAiControllerTest.php` (新建) — 10 个测试：别名生命周期/defaults 覆盖与清除/未知键 422/租户配置 upsert/目录同步/provider 连接测试
- `AiPlatformConfigServiceTest.php` (新建) — 4 个覆盖层测试
- `MailerServiceTest.php` (扩展) — 4 个平台 Mailer 测试
- `Infrastructure/InfrastructureControllersTest.php` — 修复 Operator token 认证模式（既有回归）
- `tests/Schema/RbacModule.php` — 补 seed `compliance.view/update` 权限
- `tests/Schema/AiModule.php` — `branding_configs` 建表防重复

### P1d: 部署验证 ✅ (neihang.com)
- 框架 push → split (33 包) → scrm-platform `composer update` → `deploy.py incremental`
- admin SPA 构建 (element-plus) → `rsync public/admin/`
- 全链路验证通过：
  - `GET /admin/ai/defaults` — 默认模型组正确
  - `PUT /admin/ai/defaults` — DB 覆盖即时生效
  - `PUT /admin/ai/defaults` (空值) — 清除回退 env 引导层
  - `POST /admin/ai/catalog/sync` — bailian provider 236 模型
  - `POST /admin/ai/providers/bailian/test` — `source=env`, 236 models, 159ms
  - `GET /admin/system-settings?group=mail` — mail 组为空（随时可后台补录）
  - `chatDefault()` — qwen3.7-plus 正常回复
  - `embedDefault()` — qwen3.7-text-embedding 1024 维

---

## 发现并修复的问题

| # | 问题 | 根因 | 修复 |
|---|---|---|---|
| 1 | `MailerService` 500: `SystemSetting` class not found | 缺失 import | 补 `use Models\SystemSetting` |
| 2 | `Log::logger()` 调用不存在 | Laravel 无此方法 | 改 `LogManager::channel()` |
| 3 | 平台 Mailer 无 From 头导致发送失败 | `from_address` 未配时无回退 | 加 `config('mail.from')` 回退 |
| 4 | `catalogSync` 生产 500 | `ai:models:sync` 仅 console 注册 | 改直调 `AiModelCatalogService::sync()` |
| 5 | `EsmtpTransport::getHost()` 不存在 | Symfony API 不同 | 测试改用 `__toString` 断言 |
| 6 | `tenant_users` 表无 `role` 列 | 测试 INSERT 含非法字段 | 移除（身份模型铁律：User 不拥有角色） |
| 7 | RBAC 403：Operator token 未生效 | `sanctum.provider` 未置 null | 补 `defineEnvironment()` |
| 8 | `compliance.*` 权限未 seed | RbacModule 种子遗漏 | 补 2 条权限 |
| 9 | 注释中文损坏 "兜底" → "兕底" | SearchReplace 写中文 | `perl -i -pe` 批量修复 |

---

## 关键架构决策

1. **DB 覆盖层优先**：`AiPlatformConfigService::resolveProviderConfig()` DB 优先 + 双字段变体（`url`/`base_url`、`key`/`api_key`），兼容各 Provider 读取习惯
2. **缓存策略**：Cache 60s TTL（Octane 安全，不用进程内静态数组），`forgetCached()` 保存后即时失效
3. **加密键掩码回存**：`********` 视为"未修改"自动跳过，避免覆盖真实密钥
4. **邮件三级不回退**：租户 SMTP → 平台 SMTP → env，每级独立解析，失败静默降级
5. **admin 路由认证**：平台 scope Operator（RBAC 直通）+ `defineEnvironment()` sanctum provider 置 null
6. **目录同步直调 service**：`ai:models:sync` 命令仅 console 注册，HTTP 内改直调避免 500

---

## 遗留 / P2 前置

- **`ai_providers` 多源管理表**：✅ 已在 P2 接线，见 [2026-08-07-p2-ai-providers.md](./2026-08-07-p2-ai-providers.md)
- **生产 `mail` 组为空**：平台 SMTP 未配置，可通过 `POST /admin/system-settings` 随时补录
- **admin SPA 路由**：`AiSettings.vue` 通过 `import.meta.glob` 自动发现，路由 `ai-settings`，无手动注册

---

## 涉及文件清单

### 框架 (multi_tenant_saas) — 已提交
```
A  src/Modules/Ai/Services/AiPlatformConfigService.php
A  src/Modules/Ai/Http/Controllers/AdminAiController.php
A  src/Modules/Ai/Routes/admin.php
A  src/Modules/Ai/resources/admin/ui/element-plus/views/AiSettings.vue
A  src/Modules/Ai/resources/admin/ui/bootstrap/views/AiSettings.vue
M  src/Modules/Ai/Services/AiConfigService.php
M  src/Modules/Ai/Services/AiGatewayService.php
M  src/Modules/Ai/Services/AiModelCatalogService.php
M  src/Modules/Ai/Services/AiTextService.php
M  src/Modules/Ai/Services/Ai/Providers/BailianImageProvider.php
M  src/Modules/Infrastructure/Http/Controllers/SystemSettingController.php
M  src/Modules/Infrastructure/Models/SystemSetting.php
M  src/Modules/Infrastructure/Services/MailerService.php
M  src/Modules/Platform/Http/Controllers/AdminSettingsController.php
M  src/Modules/Platform/Routes/admin.php
M  src/Modules/Platform/resources/admin/ui/element-plus/views/Settings.vue
A  tests/AdminAiControllerTest.php
A  tests/AiPlatformConfigServiceTest.php
M  tests/MailerServiceTest.php
M  tests/Infrastructure/InfrastructureControllersTest.php
M  tests/Schema/AiModule.php
M  tests/Schema/RbacModule.php
```

### 下游 (scrm-platform) — 已提交
```
M  composer.lock
```
