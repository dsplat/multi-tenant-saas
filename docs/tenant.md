# 租户体系

> **文档性质**: 系统现状权威描述
> **最后更新**: 2026-08-04
> **关联文档**: `docs/auth.md`（认证与权限）、`docs/zh/deployment/nginx-guide.md`（部署配置）
> **已删除旧文档**: `docs/zh/architecture/multi-domain.md`、`docs/zh/guides/domain-config.md`（内容已合并入本文及 `nginx-guide.md`）

---

## 一、租户模型

### 1.1 核心字段（tenants 表）

| 字段 | 类型 | 说明 |
|---|---|---|
| `tenant_id` | bigint PK | 全局唯一 ID（IdGenerator 生成） |
| `name` | string | 租户名称 |
| `slug` | string unique | URL 标识（如 `acme`），用于路径前缀和二级域名 |
| `slug_status` | enum nullable | active / rejected（null=未设置） |
| `domain` | string nullable unique | 租户绑定域名（二级域名或自定义域名，统一字段） |
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

# 二、域名体系（三层接入阶梯）

> 本节为域名架构权威描述。旧文档 `multi-domain.md`、`domain-config.md` 已删除，内容合并入本节。

### 2.0 设计原则

| 原则 | 说明 |
|---|---|
| **域名 >> slug** | 有域名走域名，没域名走 slug。`tenants.domain` 是租户入口的唯一判定字段 |
| 品牌域与服务域分离 | 平台品牌域（官网/后台）不与租户服务域混用 |
| 共享域名为默认 | 新租户零配置即可访问，无需 DNS/SSL |
| 独立域名是特权 | 二级域名需保证金，自定义域名需审核 |
| 框架不硬编码域名 | 一切通过 `config/domain.php` + `.env` 注入 |

**规范 URL 规则**：

优先级：`自定义域名 > 分配的二级域名 > slug 路径 > tenant_id 路径`

- **解析**：四种方式均可定位到租户（用户手动输入任何一种都能访问）
- **规范**：解析成功后，301 重定向到当前最优可用入口（逐级 fallback）

```
canonical(tenant) =
    tenant.custom_domain      // 有自定义域名 → 用它
    ?? tenant.subdomain       // 有分配的二级域名 → 用它
    ?? app_domain/{slug}/     // 有 active slug → 用它
    ?? app_domain/{tenant_id}/  // 兆底
```

示例：

```
租户 A：有自定义域名 crm.client.com + 二级域名 scrm.dsplat.com + slug=scrm
  app.dsplat.com/scrm/h5/         → 301 → crm.client.com/h5/（最优是自定义域名）
  scrm.dsplat.com/h5/             → 301 → crm.client.com/h5/
  crm.client.com/h5/              → 200

租户 B：仅有二级域名 scrm.dsplat.com + slug=scrm
  app.dsplat.com/scrm/h5/         → 301 → scrm.dsplat.com/h5/（最优是二级域名）
  scrm.dsplat.com/h5/             → 200

租户 C：仅有 slug=scrm
  app.dsplat.com/{tenant_id}/h5/  → 301 → app.dsplat.com/scrm/h5/（最优是 slug）
  app.dsplat.com/scrm/h5/         → 200

租户 D：无 slug（slug_status=rejected）
  app.dsplat.com/{tenant_id}/h5/  → 200（兑底即规范）
```

### 2.0.1 部署拓扑：SLB 层架构（锁定）

```
用户 → SLB 层（如 mt_aiserv：443 终结 / 证书落盘 / 请求分发） → 80 → 应用服务器 nginx → php-fpm
```

这是**架构事实，不是可选项**：

| 要点 | 说明 |
|---|---|
| SSL 终结位置 | 永远在 SLB 层。所有域名证书（通配证书 + 自定义域名证书）落盘在 SLB 侧 |
| 应用服务器监听 | 仅 80（回源层），不引入 443 执念 |
| 单机也保留分层 | 即使 SLB 与应用同设备部署，也保持两层——性能几乎无损，换来拓扑灵活性（随时拆机/扩容/换设备） |
| Host 透传 | SLB 必须透传真实 Host；应用侧 `IdentifyDomain` 优先读 `X-Original-Host`，回源层需覆盖防伪 |
| map 同步 | 租户白名单/证书等变更需同步到 SLB 层（或 SLB 对 SSL 直透不卸载），见 2.8 |

### 2.1 三层阶梯总览

| 层级 | 前端（H5）访问 | 管理后台访问 | 准入条件 |
|---|---|---|---|
| **免费层** | `{app_domain}/{slug}/...` | `{console_domain}`（登录后识别） | 注册即得 |
| **二级域名层** | `{slug}.{wildcard_base}/...` | 同域 `/console/` 路径 | 保证金 |
| **自定义域名层** | `{tenant_domain}/...` | 同域 `/console/` 路径 | 审核 + ICP + SSL |

其中：
- `{app_domain}` = `config('domain.platform_domains.app')`（如 `app.example.com`）
- `{console_domain}` = `config('domain.platform_domains.console')`（如 `console.example.com`）
- `{wildcard_base}` = `config('domain.wildcard_base')`（如 `example.com`）
- `{tenant_domain}` = `tenants.domain` 字段

### 2.2 免费层：共享域名 + 路径隔离

**前端（H5）**：

```
{app_domain}/{slug}/h5/...        → 按 slug 定位租户
{app_domain}/{tenant_id}/h5/...   → 按 tenant_id 定位（slug 未设置或被打回时的降级路径）
```

- 终端用户无需登录即可访问，租户标识**必须**出现在 URL 路径第一段
- 无路径前缀 → 返回平台首页或 404

**管理后台（Console）——双入口**：

| 入口 | 租户识别方式 | 适用场景 |
|---|---|---|
| `{console_domain}`（共享） | 登录后从 operator_tenants 关联解析 | 多租户 Operator、免费租户 |
| `{tenant_domain}/console/`（自定义域名） | 域名自动识别（无需选择） | 单租户 Operator、企业员工 |

共享 Console 登录流程：
```
{console_domain}/login
  → 认证通过，查 operator_tenants (is_active=true)
  → 关联数 = 1 → 直接进入该租户 dashboard
  → 关联数 > 1 → 租户选择页 → 选择后进入
  → 后续切换：顶栏租户切换器（X-Tenant-ID header）
```

- 自定义域名识别为已有逻辑（`resolveFromCustomDomain`），零改动
- `{console_domain}` 在 `platform_domains` 中，跳过域名解析，登录后才绑定租户

### 2.3 二级域名层（保证金）

```
DNS: *.{wildcard_base} → A 记录指向平台服务器
SSL: 通配证书 *.{wildcard_base}（落盘 SLB 层）
Nginx: 统一基桩 + tenant-auth.map 白名单
IdentifyTenant: 提取子域名前缀 → 16 位纯数字按 tenant_id 直查，否则查 tenants.slug
```

- 租户 URL：`{slug}.{wildcard_base}`
- Cookie 绑定完整 host（`a.example.com` ≠ `b.example.com`）
- 需缴纳保证金（业务层控制，框架不感知费用逻辑）

### 2.4 自定义域名层（企业白标）

```
DNS: {tenant_domain} → CNAME/A 指向平台
Nginx: catch-all server + allowed-domains.map 精确白名单
IdentifyTenant: tenants.domain 精确匹配
SSL: 租户上传证书（ssl-map.conf 动态映射）或使用默认证书
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
- 保留域名黑名单校验（`assertDomainNotReserved`）

### 2.5 租户识别优先级（IdentifyTenant）

```
1. ?tenant_id= / ?tid=（URL 参数，不可信，需归属校验）
2. X-Tenant-ID Header（不可信，需归属校验）
3. tenants.domain 精确匹配（自定义域名/二级域名，可信）
4. 共享域名路径前缀（app_domain 时，从 URL 第一段提取 slug 或 tenant_id）  ← 新增
5. Cookie tenant_id（不可信，需归属校验）
6. Session tenant_id
7. 认证用户关联（Operator → operator_tenants / User → current_tenant_id）
8. 通配子域名解析（tenant_id 直查 / slug 查询，兼容兑底）
9. 默认租户（兜底）
```

### 2.6 Slug 治理（三层防护）

#### 层级一：黑名单硬拒

命中即拒绝，不可申诉。来源：
- `config('domain.reserved_slugs')` 静态配置
- `system_settings` 动态黑名单（Admin 后台管理）

```php
// config/domain.php → reserved_slugs
'reserved_slugs' => [
    // 系统保留
    'api', 'admin', 'console', 'app', 'login', 'register', 'auth',
    'assets', 'static', 'public', 'cdn', 'mail', 'www', 'webmail',
    'localhost', 'test', 'demo', 'staging', 'dev',
    // 品牌保护（从 .env 注入，框架不硬编码）
    ...array_filter([
        env('PLATFORM_BRAND_SLUG'),
    ]),
],
```

#### 层级二：AI 风险评估（软警示）

黑名单未命中时，调用 AI 评估：

| 维度 | 示例 |
|---|---|
| 商标近似 | `wechats`、`taobaoo` |
| 品牌混淆 | 与平台品牌 slug 编辑距离 ≤ 2 |
| 歧义/不雅 | 谐音、多语言敏感词 |
| 误导性 | `bank`、`gov`、`hospital` |
| typosquatting | 与已有高流量租户 slug 编辑距离 ≤ 1 |

AI 返回风险等级：
- `low` → 直接通过
- `medium` / `high` → **允许设置**，但前端显示风险警示 + 后台标记待审

#### 层级三：后台打回

管理员可在后台打回已生效的 slug：

```
打回 → slug_status = rejected
     → 路径 /{slug}/ 失效（404）
     → 二级域名 {slug}.{wildcard_base} 从白名单移除
     → 租户降级为 /{tenant_id}/ 访问
     → 通知租户重新设置
```

#### Slug 状态机

```
null → active → rejected → (重新设置) → active
              ↗ (AI 标记高风险时后台可直接打回)
```

| 状态 | 可访问方式 |
|---|---|
| `null`（未设置） | 仅 `/{tenant_id}/` |
| `active` | `/{slug}/` + 二级域名（如已开通） |
| `rejected` | 仅 `/{tenant_id}/`，二级域名同步停用 |

#### 自动子域名（免费兜底，两种同质形态）

租户创建即自动获得子域名兜底访问，与付费二级域名共用同一识别链路与白名单，无需租户操作：

| 形态 | 示例 | 来源 |
|---|---|---|
| `{tenant_id}.{wildcard_base}` | `9007199254740992.dsplat.com` | 16 位雪花 ID 直查，天然存在 |
| `t-xxxxxx.{wildcard_base}` | `t-a3k9z2.dsplat.com` | 系统自动生成 slug（保留前缀） |

- 前缀 `t-` 为系统保留（`SlugService::isReservedAutoPrefix`），租户不可手动设置该前缀的 slug。
- 码长由 `auto_slug_length`（默认 6）控制，字符集 `auto_slug_alphabet` 排除易混淆字符（0/1/i/o/l）。
- 租户设置付费 slug 或绑定自定义域名生效后，自动码退役（`slug_status=rejected`，slug 字段保留）；tenant_id 形态始终存在。
- 两种自动子域名经统一基桩承接，但 SEO/GEO 禁止收录（见 2.8 / 第八节）。

### 2.7 配置项（config/domain.php）

| 配置 | 环境变量 | 说明 |
|---|---|---|
| `platform_domains.admin` | `PLATFORM_ADMIN_DOMAIN` | 平台管理后台域名 |
| `platform_domains.app` | `PLATFORM_APP_DOMAIN` | 共享前端域名 |
| `platform_domains.console` | `PLATFORM_CONSOLE_DOMAIN` | 共享管理后台域名 |
| `wildcard_base` | `PLATFORM_WILDCARD_BASE` | 二级域名通配基础（null 禁用） |
| `reserved_slugs` | — | Slug 黑名单数组 |
| `slug_min_length` | `SLUG_MIN_LENGTH` | Slug 最小长度（默认 3） |
| `slug_pattern` | — | Slug 合法字符正则 |
| `icp_check_enabled` | `DOMAIN_ICP_CHECK_ENABLED` | 备案检查开关 |
| `nginx_map_file` | `DOMAIN_NGINX_MAP_FILE` | 域名白名单文件路径 |
| `ssl_certs_path` | `DOMAIN_SSL_CERTS_PATH` | SSL 证书目录 |
| `auto_slug_length` | `AUTO_SLUG_LENGTH` | 自动子域名随机码长度（默认 6） |
| `auto_slug_alphabet` | `AUTO_SLUG_ALPHABET` | 自动码字符集（默认排除 0/1/i/o/l） |
| `seo_map_file` | `DOMAIN_SEO_MAP_FILE` | SEO/GEO 许可 map 文件路径 |
| `bot_map_file` | `DOMAIN_BOT_MAP_FILE` | AI 爬虫拦截 map 文件路径 |

### 2.8 Nginx 配置生成

所有租户域名接入层产物由 `NginxConfigService::generateDeployBundle()` 一键生成（命令 `php artisan domains:generate-nginx`），落在 `domain.nginx_deploy_path`（默认 `base_path('deploy/nginx')`）：

```
{deploy_path}/
├── dsplat-tenants.conf      顶层 include（系统 nginx 在 http{} 层仅 include 一次）
├── maps/
│   ├── tenant-auth.map      map $host $domain_allowed（白名单，default 0）
│   ├── ssl.map              map $ssl_server_name $ssl_cert_file/$ssl_key_file（SNI 动态证书）
│   ├── seo.map              map $host $seo_allowed + map $seo_allowed $x_robots_tag
│   └── bot.map              map $http_user_agent $is_ai_bot + map "$is_ai_bot:$seo_allowed" $block_ai_bot
├── stubs/
│   └── tenant-server.conf   基桩：唯一 443 default_server，catch-all 所有租户域名
└── tenants-enabled/         每域名一个软链接 → 基桩（巡检清单，不驱动路由）
```

**统一基桩模型**：自定义域名与二级域名（含 t-xxxxxx）共用同一个 default_server 基桩，不为每个自定义域名手写 vhost。平台域名（admin/app/console）有独立精确 vhost，优先级高于 default_server，不受基桩影响。基桩行为全部由 map 变量驱动：

| 变量 | 定义文件 | 键源 | 作用 |
|---|---|---|---|
| `$domain_allowed` | tenant-auth.map | `$host` | 白名单，`0` → `return 444` 断连（恶意/未配置域名）|
| `$seo_allowed` | seo.map | `$host` | 平台域名+自定义域名=`1`（可收录）；二级域名走 default `0`（禁收录）|
| `$x_robots_tag` | seo.map | `$seo_allowed` | `0`→`noindex, nofollow`；`1`→空（不下发）|
| `$is_ai_bot` | bot.map | `$http_user_agent` | 识别各厂 AI 爬虫（GPTBot/ClaudeBot/Bytespider 等 22 种）|
| `$block_ai_bot` | bot.map | `"$is_ai_bot:$seo_allowed"` | `"1:0"`→`1`：仅对非收录域名拦 AI 爬虫 `return 403`；自定义域名放行（GEO 开放）|
| `$ssl_cert_file`/`$ssl_key_file` | ssl.map | `$ssl_server_name` | SNI 动态选证书，default 为通配证书 |

**域名白名单**（`generateDomainWhitelistMap`）——二级域名精确放行已配置 slug，不做通配：

```nginx
map $host $domain_allowed {
    default 0;

    # 平台域名（始终允许）
    {admin_domain}     1;
    {app_domain}       1;
    {console_domain}   1;

    # 租户二级域名（仅已配置且 slug_status=active，精确放行）
    {slug}.{wildcard_base}  1;  # slug: {slug}

    # 内部服务
    127.0.0.1          1;
    localhost           1;

    # 企业自定义域名（自动生成）
    # AUTO_GENERATED_DOMAINS_START
    {tenant_domain}    1;  # {tenant_name} (tenant_id: {id})
    # AUTO_GENERATED_DOMAINS_END
}
```

**SSL 证书映射**（`generateSslMap`）——仅收录「domain 非空 + ssl_uploaded_at 非空 + `certs/{domain}.crt` 存在」的租户，default 指向通配证书：

```nginx
map $ssl_server_name $ssl_cert_file {
    default           {certs_path}/default.crt;
    {tenant_domain}   {certs_path}/{tenant_domain}.crt;
}
map $ssl_server_name $ssl_key_file {
    default           {certs_path}/default.key;
    {tenant_domain}   {certs_path}/{tenant_domain}.key;
}
```

**SEO/GEO 许可**（`generateSeoMap`）：

```nginx
map $host $seo_allowed {
    default 0;                 # 二级域名（含 t-xxxxxx）默认禁收录
    {platform_domain}  1;      # 平台域名可收录
    {tenant_domain}    1;      # 自定义域名可收录
}
map $seo_allowed $x_robots_tag {
    default "noindex, nofollow";
    1       "";                # 收录域名置空，不下发该头
}
```

**AI 爬虫拦截**（`generateBotMap`）：

```nginx
map $http_user_agent $is_ai_bot {
    default 0;
    ~*GPTBot          1;  # OpenAI
    ~*ClaudeBot       1;  # Anthropic
    ~*Bytespider      1;  # ByteDance
    # ... 共 22 种主流 AI 爬虫
}
# 条件拦截：仅「是 AI 爬虫 且 非收录域名」拦截，自定义域名放行（GEO 开放）
map "$is_ai_bot:$seo_allowed" $block_ai_bot {
    "1:0"    1;
    default  0;
}
```

**基桩**（`stubs/tenant-server.conf`，443 default_server）核心拦截链：

```nginx
server {
    listen 443 ssl http2 default_server;
    server_name _;
    ssl_certificate     $ssl_cert_file;
    ssl_certificate_key $ssl_key_file;

    if ($domain_allowed = 0) { return 444; }   # 恶意域名断连（零带宽）
    if ($block_ai_bot)       { return 403; }   # 非收录域名拦 AI 爬虫
    add_header X-Robots-Tag $x_robots_tag always;  # 非收录域名 noindex

    location /api/ { try_files $uri /index.php?$query_string; }  # 直连 fastcgi 9001
    location = /robots.txt { /* 按 $seo_allowed 动态 Allow/Disallow */ }
    # /h5/ /console/ SPA，各自补充 X-Robots-Tag（nginx add_header 继承规则）
}
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
| 平台默认 | `noreply@{platform_domain}` | 全局 SMTP |
| 租户配置 | `noreply@{tenant_domain}` | 租户 SMTP（tenant_settings group=mail） |

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

模块列表（32 个）：
```
ai, ai-streaming, api-token, auth, billing, campaign, commerce, contracts,
conversation, coupon, developer-portal, domain, event, form, ibot,
infrastructure, knowledge, logging, lottery, monitoring, notification,
operator, payment, platform, plugin, sms, ssl, storage, ticket, user, voting, workflow
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

租户域名接入层由 `NginxConfigService` 自动生成（命令 `php artisan domains:generate-nginx`），系统 nginx 仅需在 http{} 层 include 一次顶层文件 `dsplat-tenants.conf`。产物结构、map 变量与基桩模型详见第二节 2.8；配置项见 2.7。

### 8.1 SEO/GEO 分层模型

平台无法控制租户内容，故按域名层级差异化收录策略：

| 层级 | 形态 | seo_allowed | SEO/GEO | AI 爬虫 |
|---|---|---|---|---|
| 免费兑底（自动）| `{tenant_id}.{wildcard_base}` / `t-xxxxxx.{wildcard_base}` | 0 | 禁止（noindex + Disallow robots）| 403 |
| 付费二级域名 | `{slug}.{wildcard_base}` | 0 | 禁止 | 403 |
| 自定义域名 | `{tenant_domain}` | 1 | 开放 | 放行 |
| 平台域名 | admin/app/console | 1 | 开放 | 放行 |

policy 由单一 `$seo_allowed` 变量驱动：基桩同一条 `if ($block_ai_bot) return 403` 据此对子域名禁 GEO、对自定义域名开放 GEO。

### 8.2 自定义域名接入基桩

自定义域名经统一基桩承接（无需独立 vhost），SNI 证书须先纳入 ssl.map：

1. 证书软链接进受管目录（命名匹配 `{domain}.crt/.key`）：`certs/{domain}.crt → 实际证书`（软链接避免副本，续签原地生效）。
2. 置租户 `ssl_uploaded_at`（`generateSslMap` 仅收录 domain 非空 + ssl_uploaded_at 非空 + 证书文件存在的租户）。
3. `domains:generate-nginx` 重生成 → 确认 ssl.map 含该域名证书映射 → `nginx -t && nginx -s reload`。
4. 验证：SNI 返回该域名证书；AI 爬虫 UA 返回 200（GEO 开放）；无 noindex 头；robots.txt 为 `Allow: /`。

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
| `src/Modules/Domain/Services/SlugService.php` | Slug 治理（黑名单 + AI 评估 + 打回） |
| `src/Modules/Domain/Services/NginxConfigService.php` | Nginx 配置生成 |
| `src/Modules/Domain/Http/Controllers/TenantResolveController.php` | 公开租户发现 API |
| `src/Modules/Domain/Http/Controllers/SlugController.php` | Slug 设置/打回 API |
| `src/Modules/Infrastructure/Http/Middleware/IdentifyTenant.php` | 租户识别中间件 |
| `src/Modules/Infrastructure/Http/Middleware/IdentifyDomain.php` | 域名类型识别中间件 |
| `src/Context/TenantContext.php` | 租户上下文（Request 级） |
| `src/Scopes/TenantScope.php` | 数据隔离全局 Scope |
| `src/Concerns/BelongsToTenant.php` | 模型租户归属 trait |
| `config/tenancy.php` | 租户全局配置 |
| `src/Modules/Domain/Config/domain.php` | 域名配置 |
| `src/Modules/Commerce/` | Commerce 模块（SKU/订单/履约/授权/权益/内容库） |
| `src/Services/Channel/` | Channel 抽象层（ChannelManager/MessageRouter/ConversationRouter/驱动） |
| `src/Contracts/ChannelContract.php` | 渠道驱动契约 |
| `src/Contracts/CommerceFulfillmentHandler.php` | 履约 Handler 契约 |
| `src/Contracts/SupplyProvisionerContract.php` | 供给落地器契约 |

---

## 十一、域名体系重构——代码修改范围

> 本节记录从旧模型（通配子域名为主）迁移到新模型（共享域名 + 路径前缀为主）的代码变更清单。

### 11.1 修改文件

| 文件 | 变更类型 | 说明 |
|---|---|---|
| `src/Modules/Infrastructure/Http/Middleware/IdentifyTenant.php` | 修改 | 新增 `resolveFromPathPrefix()` 方法；调整优先级顺序 |
| `src/Modules/Infrastructure/Http/Middleware/IdentifyDomain.php` | 修改 | 新增 `console` 域名精确匹配（`PLATFORM_CONSOLE_DOMAIN`） |
| `src/Modules/Domain/Config/domain.php` | 修改 | 新增 `platform_domains.console`、`reserved_slugs`、`slug_min_length`、`slug_pattern` |
| `src/Modules/Domain/Services/DomainService.php` | 修改 | 打回时同步清理 slug 状态 |
| `src/Modules/Domain/Services/NginxConfigService.php` | 修改 | 白名单生成加入 console 域名 |
| `src/Modules/Infrastructure/Services/TenantOnboardingService.php` | 修改 | 创建租户时不再自动生成 `{slug}.{wildcard_base}` 为 domain；默认 domain=null |
| `config/tenancy.php` | 修改 | `platform_domains` 数组加入 `PLATFORM_CONSOLE_DOMAIN` |
| `.env.example` | 修改 | 新增 `PLATFORM_CONSOLE_DOMAIN`、`SLUG_MIN_LENGTH` |

### 11.2 新增文件

| 文件 | 职责 |
|---|---|
| `src/Modules/Domain/Services/SlugService.php` | Slug 治理服务：黑名单校验、AI 风险评估、打回/恢复、状态机 |
| `src/Modules/Domain/Http/Controllers/SlugController.php` | Slug 设置/打回 API（admin + console） |
| `database/migrations/xxxx_add_slug_status_to_tenants.php` | tenants 表新增 `slug_status` 字段 |
| `tests/SlugServiceTest.php` | Slug 治理单元测试 |
| `tests/IdentifyTenantPathPrefixTest.php` | 路径前缀解析测试 |

### 11.3 关键设计决策

| 决策 | 理由 |
|---|---|
| `slug_status` 加在 tenants 表而非 tenant_settings | 高频读取（每次请求），避免 settings 表查询开销 |
| AI 评估为同步调用（非队列） | slug 设置是低频操作，同步体验更好 |
| 路径解析仅在 `app_domain` 触发 | 避免对自定义域名/二级域名的请求误触发路径解析 |
| 通配子域名解析保留但优先级降低 | 向后兼容已有租户，新租户默认走共享域名 |
| 框架不硬编码任何具体域名 | 所有域名通过 env 注入，避免泄露部署信息 |

### 11.4 废弃文档

| 文档 | 处理 |
|---|---|
| `docs/zh/architecture/multi-domain.md` | 已删除，内容合并入本文第二节 |
| `docs/zh/guides/domain-config.md` | 已删除，操作指南以 [`docs/zh/deployment/nginx-guide.md`](zh/deployment/nginx-guide.md) 为准 |
