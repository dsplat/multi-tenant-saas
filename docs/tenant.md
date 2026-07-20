# 租户体系

> **文档性质**: 系统现状权威描述
> **最后更新**: 2026-07-19
> **关联文档**: `docs/auth.md`（认证与权限）

---

## 一、租户模型

### 1.1 核心字段（tenants 表）

| 字段 | 类型 | 说明 |
|---|---|---|
| `tenant_id` | bigint PK | 全局唯一 ID（IdGenerator 生成） |
| `name` | string | 租户名称 |
| `slug` | string unique | URL 标识（如 `lanyantu`） |
| `domain` | string nullable | 平台分配的子域名（如 `lanyantu.scrm.com`） |
| `custom_domain` | string nullable | 自备独立域名（如 `crm.lanyantu.com`） |
| `logo` | string nullable | Logo URL |
| `branding` | json nullable | 品牌配置（颜色/样式） |
| `settings` | json nullable | 通用设置（非敏感 UI 配置） |
| `status` | enum | active / suspended / pending / cancelled |
| `is_platform_default` | boolean | 是否为平台默认租户（个人用户归属） |
| `onboarding_operator_id` | bigint nullable | 注册该租户的 Operator |
| `subscription_plan` | string | 当前套餐（free/basic/pro/enterprise） |
| `ssl_uploaded_at` | datetime nullable | SSL 证书上传时间 |

### 1.2 租户生命周期

```
注册(Operator register) → 创建租户(pending) → 配置域名/品牌
  → 域名审核(approved) → 激活(active) → 正常运营
  → [违规/过期] → 暂停(suspended) → [恢复/注销]
```

### 1.3 关联关系

```
Tenant
  ├── users()        → BelongsToMany (tenant_users: role_id, is_active, joined_at)
  ├── operators()    → BelongsToMany (operator_tenants: role_id, is_active)
  ├── settings()     → HasMany (tenant_settings: group/key/value)
  ├── branding()     → HasOne (branding_configs)
  ├── subscription() → HasOne (subscription_plans)
  ├── creditAccount()→ HasOne (credit_accounts)
  └── domains()      → 通过 DomainService 管理
```

---

## 二、域名体系（三种接入模式）

### 2.1 模式总览

| 模式 | 示例 | 适用场景 | 配置复杂度 |
|---|---|---|---|
| 平台二级域名 | `lanyantu.scrm.com` | 中小租户，快速接入 | 低（DNS CNAME 即可） |
| 自备独立域名 | `crm.lanyantu.com` | 企业租户，品牌要求高 | 中（DNS + 可选 SSL） |
| 参数/路径隔离 | `scrm.com/app?tid=1001` | 无域名需求，最简接入 | 无 |

### 2.2 平台二级域名

```
DNS: *.scrm.com → A 记录指向平台服务器（通配解析）
Nginx: map 正则 ~^.*\.scrm\.com$ 放行
IdentifyTenant: 通配子域名兜底到 default_tenant_id
```

- 个人用户/免费租户使用
- 所有 `*.scrm.com` 子域名共享默认租户的 SPA
- Cookie 绑定完整 host（`arthur.scrm.com` ≠ `bob.scrm.com`）

### 2.3 自备独立域名

```
DNS: crm.lanyantu.com → CNAME/A 指向平台
Nginx: catch-all server + allowed-domains.map 精确白名单
IdentifyTenant: tenants.custom_domain 精确匹配
SSL: 可选上传证书（ssl-map.conf 动态映射）或使用默认证书
```

域名审核状态机（`DomainService`）：
```
pending → approved（管理员审核通过）
pending → rejected（管理员拒绝，附原因）
```

域名校验规则：
- 必须是完整域名（至少两段，如 `crm.example.com`）
- 正则：`/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/`
- 唯一性：同一域名不能被多个租户绑定

### 2.4 参数/路径隔离

```
URL: scrm.com/app?tenant_id=1001 或 scrm.com/app?tid=1001
IdentifyTenant: 优先级 1（URL 参数）
```

- 无需任何域名配置
- 适合开发测试或无品牌要求的场景
- 前端通过 URL 参数传递 tenant_id

### 2.5 域名识别优先级（IdentifyTenant）

```
1. ?tenant_id= / ?tid=（URL 参数）
2. X-Tenant-ID Header
3. tenants.custom_domain 精确匹配
4. Cookie tenant_id
5. Session tenant_id
6. 认证用户关联
7. 通配子域名 → default_tenant_id；其他 → 403
```

---

## 三、品牌与白标

### 3.1 品牌配置（BrandingService + branding_configs 表）

| 配置项 | 说明 |
|---|---|
| `logo_url` | 租户 Logo（支持 PNG/JPEG/SVG/WebP，≤2MB） |
| `favicon_url` | 浏览器图标 |
| `primary_color` | 主色调（默认 #1890ff） |
| `secondary_color` | 辅助色（默认 #666666） |
| `login_page_style` | 登录页样式模板 |
| `email_template` | 邮件模板品牌化 |
| `custom_css` | 自定义 CSS 注入 |

### 3.2 品牌注入链路

```
前端加载 → GET /api/v1/tenant/resolve?domain={host}
  → 返回: tenant_id, name, logo, branding{primary_color, login_page_message}
  → 前端动态设置: <title>, Logo, 主题色, 登录页文案

邮件发送 → TenantMail Mailable
  → MailTemplateService 渲染模板
  → 注入: Logo, 主色调, 租户名称, 自定义页脚
```

### 3.3 邮件品牌化

| 层级 | 发件人 | SMTP |
|---|---|---|
| 平台默认 | `noreply@scrm.com` | 全局 SMTP |
| 租户配置 | `noreply@lanyantu.com` | 租户 SMTP（tenant_settings group=mail） |

配置 API：
```
GET  /api/v1/tenant/auth/mail/config   → 获取（密码遮罩）
PUT  /api/v1/tenant/auth/mail/config   → 更新（smtp_password 加密）
POST /api/v1/tenant/auth/mail/test     → 测试邮件
```

---

## 四、租户配置（tenant_settings）

### 4.1 数据模型

```php
tenant_settings (id, tenant_id, group, key, value, is_encrypted, timestamps)
  UNIQUE (tenant_id, group, key)
```

### 4.2 配置分组

| group | 用途 | 典型 key |
|---|---|---|
| `oauth` | 第三方登录凭证 | `wechat_work_corp_id`, `github_client_secret`(encrypted) |
| `mail` | SMTP 配置 | `smtp_host`, `smtp_password`(encrypted), `from_address` |
| `sso` | 登录策略 | `login_methods`, `allow_register`, `email_domain_restriction` |
| `domain` | 域名审核 | `domain_status`, `icp_verified`, `domain_verified_at` |
| `branding` | 品牌扩展 | 非敏感 UI 配置 |
| `residency` | 数据驻留 | `region`, `storage_disk` |

### 4.3 服务层（TenantSettingService）

```php
TenantSettingService::get(int $tenantId, string $group, string $key, $default = null)
TenantSettingService::set(int $tenantId, string $group, string $key, $value, bool $encrypted = false)
TenantSettingService::getGroup(int $tenantId, string $group): array
TenantSettingService::preload(int $tenantId): void  // 批量预加载到内存
```

特性：
- 加密字段自动 encrypt/decrypt（`is_encrypted=true`）
- 请求级内存缓存（避免重复查询）
- 支持预加载（高频访问场景）

---

## 五、模块管理

### 5.1 按套餐差异化开通

```php
// config/tenancy.php → plan_modules
'free'       → coupon:off, lottery:off, sms:off
'basic'      → coupon:on,  lottery:off, sms:on
'pro'        → coupon:on,  lottery:on,  sms:on
'enterprise' → 全部模块 + payment + api-token + ssl + domain
```

### 5.2 租户级模块开关

`tenant_module_defaults` 定义新租户默认开通状态，`plan_modules` 按套餐覆盖。

模块列表（22 个）：
```
plugin, infrastructure, event, billing, logging, auth, operator,
storage, notification, monitoring, platform, user, developer-portal,
conversation, workflow, ai, domain, coupon, form, lottery, sms, voting
```

---

## 六、订阅与配额

### 6.1 套餐配额

| 套餐 | 最大用户数 | 存储空间 |
|---|---|---|
| free | 5 | 1 GB |
| basic | 20 | 10 GB |
| pro | 100 | 50 GB |
| enterprise | 无限制 | 无限制 |

### 6.2 积分体系

```
tenants.total_credits  → 总积分
tenants.used_credits   → 已用积分
credit_accounts        → 积分账户明细
```

预警阈值：`config('tenancy.credit_warning_threshold')` = 100

---

## 七、数据隔离

### 7.1 隔离策略

| 策略 | 实现 | 适用 |
|---|---|---|
| 共享表 + tenant_id | `BelongsToTenant` trait + `TenantScope` 全局 Scope | 默认（所有模块） |
| 独立数据库 | `TenantContext::getDatabaseName()` | enterprise 套餐（可选） |
| 独立 Schema | `TenantContext::getSchemaName()` | enterprise 套餐（可选） |

### 7.2 TenantScope

```php
// 所有使用 BelongsToTenant trait 的模型自动添加:
Model::query() → WHERE tenant_id = {current_tenant_id}
```

绕过场景：
- 平台管理员（scope=platform）
- 跨租户查询（显式 `withoutGlobalScope(TenantScope::class)`）
- 中间表（operator_tenants, tenant_users）使用专用监听器

---

## 八、Nginx 配置

### 8.1 域名白名单（NginxConfigService::generateDomainWhitelistMap）

```nginx
map $host $domain_allowed {
    default 0;  # 默认拒绝

    # 平台域名
    admin.scrm.com    1;
    app.scrm.com      1;

    # 个人用户子域名（通配）
    ~^.*\.scrm\.com$  1;

    # 内部服务
    127.0.0.1         1;
    localhost         1;

    # 企业自定义域名（自动生成）
    crm.lanyantu.com  1;  # 蓝途 SCRM (tenant_id: 1001)
    app.foo.com       1;  # Foo Inc (tenant_id: 1002)
}
```

### 8.2 SSL 证书映射（NginxConfigService::generateSslMap）

```nginx
map $ssl_server_name $ssl_cert_file {
    default  /etc/nginx/ssl/default.crt;
    crm.lanyantu.com  /etc/nginx/ssl/crm.lanyantu.com.crt;
}
map $ssl_server_name $ssl_key_file {
    default  /etc/nginx/ssl/default.key;
    crm.lanyantu.com  /etc/nginx/ssl/crm.lanyantu.com.key;
}
```

### 8.3 配置项（config/domain.php）

| 配置 | 默认值 | 说明 |
|---|---|---|
| `platform_domains.admin` | `admin.example.com` | 管理后台域名 |
| `platform_domains.app` | `app.example.com` | 平台应用域名 |
| `wildcard_base` | `scrm.com` | 通配子域名基础 |
| `nginx_map_file` | `/etc/nginx/conf.d/allowed-domains.map` | 白名单文件路径 |
| `ssl_certs_path` | `/etc/nginx/ssl` | SSL 证书目录 |

---

## 九、合规与治理

### 9.1 GDPR 合规

- 数据导出：支持用户数据全量导出（14 种数据类型）
- 数据擦除：匿名化处理（`deleted.local` 邮箱后缀）
- 条款版本：`gdpr.terms_version` 追踪用户同意

### 9.2 数据保留策略

| 数据类型 | 保留天数 | 清理策略 |
|---|---|---|
| user_sessions | 90 | delete |
| audit_logs | 365 | anonymize |
| ai_requests | 180 | anonymize |
| password_histories | 365 | delete |
| consents | 1095 | anonymize |

### 9.3 数据驻留

支持按租户配置数据存储区域（CN/US/EU/APAC），套餐限制可访问区域。

---

## 十、代码索引

| 文件 | 职责 |
|---|---|
| `src/Modules/Infrastructure/Models/Tenant.php` | 租户模型 |
| `src/Modules/Infrastructure/Models/TenantSetting.php` | 租户配置模型 |
| `src/Modules/Infrastructure/Services/TenantSettingService.php` | 配置读写服务 |
| `src/Modules/Infrastructure/Services/BrandingService.php` | 品牌白标服务 |
| `src/Modules/Domain/Services/DomainService.php` | 域名审核状态机 |
| `src/Modules/Domain/Services/NginxConfigService.php` | Nginx 配置生成 |
| `src/Modules/Domain/Http/Controllers/TenantResolveController.php` | 公开租户发现 API |
| `src/Modules/Infrastructure/Http/Middleware/IdentifyTenant.php` | 租户识别中间件 |
| `src/Context/TenantContext.php` | 租户上下文（Request 级） |
| `src/Scopes/TenantScope.php` | 数据隔离全局 Scope |
| `src/Concerns/BelongsToTenant.php` | 模型租户归属 trait |
| `config/tenancy.php` | 租户全局配置 |
| `src/Modules/Domain/Config/domain.php` | 域名配置 |
