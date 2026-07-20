# 认证体系改进计划

> **文档性质**: 基于 `docs/auth.md` 理论设计的落地改进计划
> **最后更新**: 2026-07-19
> **状态**: ✅ **Phase 1-4 全部完成**
> **前置文档**: `docs/auth.md`(理论设计权威版本)
> **核心原则**: 在现有优秀体系上增量优化,不推翻重来;必有取舍,给出理由

---

## 第一部分:现状精确评估(代码审查修正)

### 1.1 auth.md 中需要修正的认知偏差

在深度代码审查后,发现 `docs/auth.md` 的部分缺口描述与代码实际不完全一致。**改进计划以代码事实为准**:

| auth.md 描述 | 代码实际 | 修正 |
|---|---|---|
| "SsoService 只支持 SAML/OIDC,不支持 wechat_work/feishu/dingtalk" | `SocialiteService` 已实现 wechat/dingtalk/feishu/github/google/alipay 的**租户级** OAuth,配置存 `tenant_settings` 表 `group='oauth'` | SocialiteService 与 SsoService 是两个独立服务,前者已覆盖主流 OAuth |
| "租户级 OAuth 凭证存储 — 当前 SsoProvider 模型字段面向 SAML/OIDC,不适合企业微信" | `SocialiteService::getOAuthConfig()` 已从 `tenant_settings` 读取 `{provider}_client_id`/`{provider}_client_secret`/`{provider}_redirect` | 凭证存储已解决,但企业微信(wechat_work)需要 corp_id/agent_id 模式,与标准 Socialite 驱动不兼容 |
| "TenantSettingService(若当前不存在)" | `TenantSettingService` 已存在,支持 group/key/value + 加密 + 内存缓存 + 预加载 | 无需新建,直接使用 |
| "新增 TenantOAuthController 完整 CRUD" | `TenantOAuthController` 已有 getOAuthConfig/updateOAuthConfig/redirect/callback | 需扩展而非新建 |

### 1.2 已具备的能力(不动)

| 能力 | 实现位置 | 成熟度 |
|---|---|---|
| Operator/User 双体系 + 三通道认证 | `AuthController` + `OperatorAuthController` + 路由分离 | 生产就绪 |
| 域名类型识别(admin/console/api/app) | `IdentifyDomain` 中间件 | 生产就绪 |
| 7 级优先级租户解析 | `IdentifyTenant` 中间件 | 生产就绪 |
| 租户级 OAuth(wechat/dingtalk/feishu/github/google/alipay) | `SocialiteService` + `TenantOAuthController` | 生产就绪 |
| SAML 2.0 + OIDC SSO | `SsoService`(726 行,含签名校验/JIT/属性映射) | 生产就绪 |
| 支付宝独立 OAuth(RSA2 签名) | `AlipayOAuthService` | 生产就绪 |
| 租户配置结构化存储 | `TenantSettingService` + `TenantSetting` 模型 | 生产就绪 |
| Nginx 白名单 + SSL 动态生成 | `NginxConfigService` | 生产就绪 |
| 域名审核状态机 | `DomainService` | 生产就绪 |
| 品牌化(Logo/颜色/CSS/邮件模板) | `BrandingService` | 生产就绪 |
| 统一邮件发送(模板驱动) | `MailerService` + `TenantMail` | 生产就绪 |
| MFA(TOTP/Email/SMS + 可信设备 + 恢复码) | `MfaService` + `MfaController` | 生产就绪 |
| RBAC 角色权限 | `RbacService` + `CheckRbacPermission` | 生产就绪 |
| 登录日志 | `LoginLogService` | 生产就绪 |
| 密码策略 + 历史 | `PasswordPolicyService` + `PasswordHistory` | 生产就绪 |
| 会话管理 | `SessionService` + `UserSession` | 生产就绪 |

### 1.3 真实缺口(经代码验证)

| # | 缺口 | 影响 | 紧迫度 |
|---|---|---|---|
| G1 | 公开租户发现 API(`/api/v1/tenant/resolve`) | 前端登录前无法预知 tenant_id 和品牌信息 | 高 |
| G2 | 公开 SSO/OAuth 配置 API(`/api/v1/tenant/sso-config`) | 前端无法动态渲染登录按钮 | 高 |
| G3 | `oauth_accounts.provider` 未命名空间化 | 同 corp_id 跨租户可能串扰 | 高 |
| G4 | 企业微信(wechat_work)独立 API 模式 | 企业微信不走标准 Socialite,需 corp_id/agent_id/secret | 中 |
| G5 | Session Cookie 域名动态绑定 | 多租户共享域名时 session 可能串扰 | 高 |
| G6 | 租户级 SMTP 动态切换 | 所有邮件用全局 SMTP 发送,无法按租户定制发件人 | 中 |
| G7 | Nginx 白名单不支持通配(`*.scrm.com`) | 个人用户子域名被拒绝 | 低 |
| G8 | `IdentifyTenant` 对未识别域名无 403 拒绝 | 未注册域名兜底到默认租户,存在安全隐患 | 中 |
| G9 | 前端 public 端缺少注册/验证/申请进度页面 | 后端 API 完备但无前端入口 | 中 |
| G10 | OAuth 回调缺少 state 中编码 tenant_id | 多租户并发回调时可能丢失租户上下文 | 高 |

---

## 第二部分:改进项详细设计

### 改进 1:公开租户发现 API(G1 + G2)

**当前状态**: 无公开端点,前端必须硬编码 tenant_id 或依赖 X-Tenant-ID Header。

**目标**: 前端在登录页加载时,通过当前域名自动获取租户信息和可用登录方式。

**新增路由**(在 `src/Modules/Infrastructure/Routes/api.php` 或新建 `src/Modules/Domain/Routes/public.php`):

```
GET /api/v1/tenant/resolve?domain={host}
  → 公开,无需认证,无需 X-Tenant-ID
  → 返回: { tenant_id, name, logo_url, branding, status }
  → 未找到: { success: false, message: 'tenant_not_found' }

GET /api/v1/tenant/login-config
  → 公开,需 X-Tenant-ID Header(或 ?domain= 参数)
  → 返回: { login_methods: ['email','wechat','dingtalk'], oauth_providers: [...], allow_register: bool, email_domain_restriction: string|null }
```

**实施步骤**:

1. 在 `src/Modules/Domain/Http/Controllers/` 新建 `TenantResolveController.php`
2. `resolve()` 方法:按 `domain` 参数查 `tenants.custom_domain`,返回公开字段(不泄露 settings)
3. `loginConfig()` 方法:聚合 `SocialiteService::getOAuthConfigForDisplay()` + `SsoService::listProviders()` + `TenantSettingService::get(tenantId, 'sso', 'login_methods')`
4. 路由注册到 public 路由(无需 auth 中间件)
5. 添加 throttle 限流(`throttle:30,1`)

**取舍**:
- **选择**:在 Domain 模块而非 Auth 模块实现。**理由**:租户发现是域名/租户层面的能力,不属于认证逻辑;Domain 模块已有 `TenantDomainController`,职责内聚。
- **选择**:login-config 需要 X-Tenant-ID 而非完全公开。**理由**:避免枚举攻击(攻击者遍历 tenant_id 探测哪些租户开启了哪些 OAuth),但 resolve 接口通过域名查询是安全的(域名本身是公开信息)。
- **放弃**:不做 IP 地理定位自动选择租户。**理由**:增加复杂度,且与"域名决定路由"原则冲突。

**涉及文件**:
- 新建: `src/Modules/Domain/Http/Controllers/TenantResolveController.php`
- 新建: `src/Modules/Domain/Routes/public.php`
- 修改: `src/Modules/Domain/DomainServiceProvider.php`(注册 public 路由)

**预估**: 2-3 天

---

### 改进 2:oauth_accounts.provider 命名空间化(G3 + G10)

**当前状态**: `SocialiteService::findOrCreateUser()` 中 `OauthAccount::where('provider', $provider)` 使用裸 provider 名(如 `wechat`),不带 tenant_id。`recordOAuthAccount()` 同样用裸 provider。

**风险**: 若两个租户配置了相同的 OAuth 应用(同一 client_id),同一外部用户会在两个租户间串扰。

**目标**: provider 字段格式化为 `{provider}:tenant:{tenantId}`,确保跨租户隔离。

**实施步骤**:

1. 在 `SocialiteService` 中新增 `namespacedProvider(string $provider, int $tenantId): string` 方法:
   ```php
   return "{$provider}:tenant:{$tenantId}";
   ```
2. 修改 `findOrCreateUser()`:查询条件改为 `where('provider', self::namespacedProvider($provider, $tenantId))`
3. 修改 `recordOAuthAccount()`:存储时使用命名空间化 provider
4. **数据迁移**:编写迁移脚本,将现有 `oauth_accounts` 记录的 provider 字段从 `wechat` 更新为 `wechat:tenant:{tenant_id}`(利用已有的 `tenant_id` 列)
5. 修改 `SsoService` 中 SAML/OIDC 的 provider 命名(当前已用 `{type}:{name}` 格式,需确认是否已含 tenant_id)

**取舍**:
- **选择**:格式 `{provider}:tenant:{tenantId}` 而非 `{provider}:tenant_id:{tenantId}`。**理由**:更简洁,且与 SsoService 现有的 `saml:{name}` / `oidc:{name}` 格式风格一致。
- **选择**:写数据迁移脚本而非在查询时兼容两种格式。**理由**:兼容逻辑会增加每次查询的复杂度,且旧数据量有限(系统尚未大规模使用),一次性迁移更干净。
- **放弃**:不做 provider 字段的数据库唯一索引变更。**理由**:当前 `oauth_accounts` 表的唯一约束是 `(user_id, provider, provider_id)`,命名空间化后自然满足,无需改表结构。

**涉及文件**:
- 修改: `src/Modules/Auth/Services/SocialiteService.php`(3 处)
- 修改: `src/Modules/Auth/Services/SsoService.php`(确认 SAML/OIDC provider 格式)
- 新建: 数据迁移脚本 `database/migrations/xxxx_normalize_oauth_provider_namespace.php`

**预估**: 1-2 天

---

### 改进 3:Session Cookie 域名动态绑定(G5)

**当前状态**: `bootstrap/app.php` 中未注册任何 session domain 动态绑定中间件。Laravel 默认 `session.domain` 为 `null`(当前域名有效),但在反向代理 + 多域名场景下,Cookie 可能被发送到错误的域名。

**目标**: 新增 `BindSessionDomain` 中间件,在每次请求时将 `session.domain` 设为当前请求的完整 host。

**实施步骤**:

1. 新建 `src/Modules/Infrastructure/Http/Middleware/BindSessionDomain.php`:
   ```php
   public function handle(Request $request, Closure $next): Response
   {
       $host = $request->header('X-Original-Host') ?? $request->getHost();

       // 仅对非平台域名动态绑定(平台域名用全局配置)
       $platformDomains = config('tenancy.platform_domains', []);
       if (!in_array($host, $platformDomains)) {
           config(['session.domain' => $host]);
       }

       // 生产环境强制 secure + same_site
       if (app()->environment('production')) {
           config(['session.secure' => true, 'session.same_site' => 'lax']);
       }

       return $next($request);
   }
   ```
2. 在 `bootstrap/app.php` 的 `$middleware->api(prepend: [...])` 和 `$middleware->web(prepend: [...])` 中注册,位置在 `IdentifyDomain` 之后、`IdentifyTenant` 之前
3. 发布 `config/session.php`(`php artisan config:publish session`),确保 `domain` 默认值为 `null`

**取舍**:
- **选择**:中间件方式而非在 `IdentifyTenant` 中附带处理。**理由**:单一职责原则;`IdentifyTenant` 已经 160 行,不应再承担 session 配置职责。
- **选择**:平台域名不动态绑定。**理由**:平台域名(admin.scrm.com 等)是固定的,全局配置即可;动态绑定只对租户自定义域名有意义。
- **放弃**:不做 Cookie 的 `partitioned` 属性(CHIPS)。**理由**:浏览器兼容性不足,且当前 Sanctum token 方案不依赖 Cookie 传递 token(API 用 Bearer token),Cookie 主要用于 SPA session,风险可控。
- **放弃**:不做跨子路径的 session 共享优化。**理由**:同一租户内 `/console` 和 `/` 共享 Cookie 是期望行为(同一 host 下 Cookie 自然共享),无需额外处理。

**涉及文件**:
- 新建: `src/Modules/Infrastructure/Http/Middleware/BindSessionDomain.php`
- 修改: `bootstrap/app.php`(注册中间件)

**预估**: 0.5-1 天

---

### 改进 4:企业微信(wechat_work)独立 OAuth(G4)

**当前状态**: `SocialiteService` 通过 Laravel Socialite 驱动支持 wechat/dingtalk/feishu,但**企业微信(wechat_work)** 的授权流程与标准 OAuth2 不同:
- 需要 `corp_id` + `agent_id` + `secret`(不是 client_id/client_secret)
- 授权 URL 格式: `https://open.work.weixin.qq.com/wwopen/sso/qrConnect?appid={corp_id}&agentid={agent_id}&redirect_uri=...&state=...`
- 回调后需用 `code` + `corp_id` + `secret` 换取 `access_token`,再获取用户信息

Socialite 的 wechat 驱动是**微信扫码登录**(开放平台),不是企业微信。

**目标**: 新增 `WechatWorkOAuthService`,独立处理企业微信 OAuth 流程,复用 `SocialiteService` 的用户创建/绑定逻辑。

**实施步骤**:

1. 新建 `src/Modules/Auth/Services/WechatWorkOAuthService.php`:
   - `getAuthorizeUrl(int $tenantId): string` — 从 `tenant_settings` 读取 `oauth.wechat_work` 配置(corp_id/agent_id/secret/redirect_uri),构造授权 URL
   - `handleCallback(int $tenantId, string $code, string $state): array` — 用 code 换 access_token,获取用户信息,调用 `SocialiteService::findOrCreateUser()` 逻辑
   - state 缓存编码 tenant_id(解决 G10)
2. 在 `tenant_settings` 中约定 wechat_work 配置结构:
   ```
   group=oauth, key=wechat_work_corp_id
   group=oauth, key=wechat_work_agent_id
   group=oauth, key=wechat_work_secret (encrypted)
   group=oauth, key=wechat_work_redirect
   ```
3. 在 `TenantOAuthController` 中扩展 wechat_work 的 redirect/callback 路由
4. 在 `SocialiteService::getSupportedProviders()` 中添加 `wechat_work` 条目
5. 在 `SocialiteService::getRedirectUrl()` 和 `handleCallback()` 中添加 wechat_work 分支(类似 alipay 的处理方式)

**取舍**:
- **选择**:独立 Service 而非扩展 Socialite 驱动。**理由**:企业微信 API 与标准 OAuth2 差异大(无 client_id/secret,用 corp_id/agent_id/secret;token 获取方式不同),强行适配 Socialite 驱动会增加脆弱性。参考 `AlipayOAuthService` 的先例(支付宝也是独立 Service)。
- **选择**:配置键用 `wechat_work_corp_id` 而非 JSON 结构。**理由**:与 `SocialiteService::getOAuthConfig()` 现有的 `{provider}_{key}` 命名约定一致,无需改动配置读取逻辑。
- **放弃**:不同时支持企业微信"企业内部应用"和"第三方应用"两种模式。**理由**:第三方应用模式需要 suite_id/suite_secret/pre_auth_code,复杂度翻倍;先支持最常见的企业内部应用模式,第三方模式留作后续扩展。
- **放弃**:不做飞书/钉钉的独立 API 适配。**理由**:Socialite 社区已有成熟的飞书/钉钉驱动(`overtrue/socialite`),标准 client_id/client_secret 模式可正常工作;企业微信是唯一需要独立处理的。

**涉及文件**:
- 新建: `src/Modules/Auth/Services/WechatWorkOAuthService.php`
- 修改: `src/Modules/Auth/Services/SocialiteService.php`(添加 wechat_work 分支)
- 修改: `src/Modules/Auth/Http/Controllers/TenantOAuthController.php`(扩展配置字段)
- 修改: `config/socialite.php`(添加 wechat_work 占位配置)

**预估**: 3-5 天

---

### 改进 5:租户级 SMTP 动态切换(G6)

**当前状态**: `MailerService` 使用全局 `Mail::to($to)->send($mailable)`,所有租户共用同一 SMTP 配置和发件人地址。`TenantMail` 已实现模板渲染和品牌注入,但 SMTP 传输层是全局的。

**目标**: 支持租户在 `tenant_settings` 中配置自己的 SMTP,邮件以租户自己的发件人地址发送。

**实施步骤**:

1. 在 `tenant_settings` 中约定 mail 配置:
   ```
   group=mail, key=smtp_host
   group=mail, key=smtp_port
   group=mail, key=smtp_encryption (ssl/tls/null)
   group=mail, key=smtp_username
   group=mail, key=smtp_password (encrypted)
   group=mail, key=from_address
   group=mail, key=from_name
   ```
2. 在 `MailerService` 中新增 `sendWithTenantMailer()` 方法:
   ```php
   public function sendTemplate(string $to, string $type, array $data = [], ?int $tenantId = null, ...): bool
   {
       $tenantId = $tenantId ?? TenantContext::getId();
       $mailer = $this->resolveTenantMailer($tenantId);

       if ($mailer) {
           // 使用租户 SMTP
           $mailable = new TenantMail($type, $data, $tenantId, $attachments, $locale);
           $mailer->to($to)->send($mailable);
       } else {
           // 回退到全局 SMTP
           Mail::to($to)->send(new TenantMail(...));
       }
   }

   protected function resolveTenantMailer(?int $tenantId): ?Mailer
   {
       if (!$tenantId) return null;
       $host = TenantSettingService::get($tenantId, 'mail', 'smtp_host');
       if (!$host) return null;

       $transport = new SmtpTransport($host, $port, $encryption);
       $transport->setUsername($username);
       $transport->setPassword($password);
       return new Mailer('tenant', $transport, ...);
   }
   ```
3. 新增租户后台邮件配置 API:
   ```
   GET  /api/v1/tenant/auth/mail/config   → 获取 SMTP 配置(密码遮罩)
   PUT  /api/v1/tenant/auth/mail/config   → 更新 SMTP 配置
   POST /api/v1/tenant/auth/mail/test     → 发送测试邮件
   ```
4. 在 `TenantOAuthController` 同级新建 `TenantMailConfigController.php`(或扩展 `TenantOAuthController` 为 `TenantAuthConfigController`)

**取舍**:
- **选择**:运行时动态创建 Mailer 而非在 `config/mail.php` 中预定义多个 mailer。**理由**:租户数量不确定,预定义不可行;Laravel 的 `Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport` 支持运行时构造。
- **选择**:失败时静默回退到全局 SMTP。**理由**:邮件发送不应因租户 SMTP 配置错误而完全失败(如密码过期),回退保证邮件至少能发出,同时记录告警日志。
- **放弃**:不做 SMTP 连接池/复用。**理由**:每次邮件发送创建新连接的开销可接受(邮件发送频率低),连接池增加复杂度且 Symfony Mailer 内部已有连接管理。
- **放弃**:不做租户级邮件队列隔离。**理由**:当前队列按 tenant_id 标记即可,不需要独立队列;SMTP 切换在 Job 执行时动态处理。

**涉及文件**:
- 修改: `src/Modules/Infrastructure/Services/MailerService.php`(核心改造)
- 新建: `src/Modules/Auth/Http/Controllers/TenantMailConfigController.php`
- 修改: `src/Modules/Auth/Routes/tenant.php`(注册邮件配置路由)

**预估**: 2-3 天

---

### 改进 6:Nginx 白名单通配 + IdentifyTenant 安全加固(G7 + G8)

**当前状态**:
- `NginxConfigService::generateDomainWhitelistMap()` 只生成精确匹配的域名条目
- `IdentifyTenant::resolveTenantId()` 第 7 步无条件兜底到 `default_tenant_id`

**目标**:
- 白名单支持 `*.scrm.com` 通配(个人用户子域名)
- 非白名单域名返回 403 而非兜底

**实施步骤**:

1. 在 `NginxConfigService::generateDomainWhitelistMap()` 中添加通配规则:
   ```php
   // 在平台域名区块后添加
   '    # ===== 个人用户子域名(通配) =====',
   sprintf('    ~^.*\\.%s$  1;', config('domain.platform_domains.wildcard_base', 'scrm.com')),
   ```
2. 在 `config/domain.php` 中添加 `wildcard_base` 配置项
3. 修改 `IdentifyTenant::resolveFromCustomDomain()`:
   - 添加通配匹配:若 host 匹配 `*.{wildcard_base}` 且无精确匹配,返回 `default_tenant_id`
   - 若 host 不在白名单且不是通配子域名,返回 `null`(后续由 `EnsureTenantContext` 返回 403)
4. 修改 `IdentifyTenant::resolveTenantId()` 第 7 步:
   ```php
   // 旧: return config('tenancy.default_tenant_id') ? ... : null;
   // 新: 仅在通配子域名或明确配置了 fallback 时兜底
   if ($this->isWildcardSubdomain($host)) {
       return config('tenancy.default_tenant_id');
   }
   return null; // 未识别域名不兜底
   ```

**取舍**:
- **选择**:Nginx 层用正则 `~^.*\.scrm\.com$` 而非 Lua 脚本。**理由**:纯 Nginx map 指令即可实现,无需 OpenResty;与现有白名单生成逻辑一致。
- **选择**:通配子域名兜底到默认租户,其他未识别域名返回 403。**理由**:个人用户子域名是平台主动开放的( DNS 通配解析),应兜底;未识别域名可能是攻击或配置错误,应拒绝。
- **放弃**:不做子域名到 User 的映射(如 `arthur.scrm.com` → user_id=xxx)。**理由**:这需要在 DNS/Nginx 层做用户级路由,复杂度极高;当前方案中所有子域名共享默认租户的 SPA,用户通过登录区分身份即可。
- **放弃**:不做通配 SSL 证书自动申请。**理由**:Let's Encrypt 通配证书需要 DNS-01 验证,自动化复杂;初期可用默认证书(浏览器会警告但不阻断),后续接入 ACME 自动化。

**涉及文件**:
- 修改: `src/Modules/Domain/Services/NginxConfigService.php`
- 修改: `src/Modules/Infrastructure/Http/Middleware/IdentifyTenant.php`
- 修改: `src/Modules/Domain/Config/domain.php`(添加 wildcard_base)

**预估**: 1-2 天

---

### 改进 7:前端 public 端页面补全(G9)

**当前状态**: 后端 API 完备(注册/登录/邮箱验证/忘记密码/SSO),但 public 端前端缺少:
- 注册页面(Register.vue)
- 邮箱验证页面
- 申请进度查询页面(ApplyStatus.vue)
- 登录页动态渲染(根据 login-config 显示 OAuth 按钮)

**目标**: 补全 public 端前端页面,使注册→验证→登录→OAuth 全流程可用。

**实施步骤**:

1. 登录页改造:
   - 加载时调用 `GET /api/v1/tenant/resolve?domain={当前host}` 获取租户信息
   - 调用 `GET /api/v1/tenant/login-config` 获取可用登录方式
   - 动态渲染:邮箱/密码表单 + OAuth 按钮(wechat/dingtalk/feishu 等) + SSO 按钮
   - 显示租户品牌(Logo/名称/主题色)
2. 注册页面:
   - 调用 `POST /api/v1/auth/register`
   - 检查 `login-config.allow_register` 决定是否显示注册入口
   - 检查 `email_domain_restriction` 做前端校验
3. 邮箱验证页面:
   - 调用 `POST /api/v1/auth/verify-email`
   - 支持重发验证邮件
4. 申请进度查询页面:
   - 调用 Operator 注册后的申请状态查询 API

**取舍**:
- **选择**:在框架层提供基础页面模板,项目层可覆盖。**理由**:框架提供开箱即用的默认实现,项目通过 `resources/pages/` 覆盖自定义。
- **选择**:登录页用 Vue 组件而非 Blade 模板。**理由**:与现有 SPA 架构一致(Vite + Vue),且需要动态 API 调用。
- **放弃**:不做"记住我"跨租户功能。**理由**:与"会话绑定完整域名"原则冲突,每个域名的 session 独立。

**涉及文件**:
- 新建: `resources/pages/auth/Login.vue`(或改造现有)
- 新建: `resources/pages/auth/Register.vue`
- 新建: `resources/pages/auth/VerifyEmail.vue`
- 新建: `resources/pages/auth/ApplyStatus.vue`
- 修改: 前端路由配置

**预估**: 5-7 天

---

## 第三部分:Phase 划分与优先级

### Phase 1:安全加固(1 周)— 最高优先级

| 改进项 | 理由 | 工作量 |
|---|---|---|
| 改进 3:Session Cookie 域名绑定 | 多租户 session 隔离是安全底线 | 0.5-1 天 |
| 改进 2:provider 命名空间化 | 防止跨租户 OAuth 串扰 | 1-2 天 |
| 改进 6(IdentifyTenant 部分):未识别域名 403 | 防止未注册域名兜底到默认租户 | 0.5 天 |

**Phase 1 验收标准**:
- [ ] `crm.lanyantu.com` 的 session cookie domain = `crm.lanyantu.com`,不是 `.lanyantu.com`
- [ ] 两个不同域名的 session 完全隔离(用例 5)
- [ ] `oauth_accounts.provider` 格式为 `wechat:tenant:123`
- [ ] 未注册域名访问返回 403 而非兜底

### Phase 2:前端登录体验(1.5 周)— 高优先级

| 改进项 | 理由 | 工作量 |
|---|---|---|
| 改进 1:公开租户发现 API | 前端动态化的前提 | 2-3 天 |
| 改进 7:前端 public 端页面 | 用户可见的完整登录/注册流程 | 5-7 天 |

**Phase 2 验收标准**:
- [ ] 访问 `crm.lanyantu.com/login` 自动显示"蓝途 SCRM"品牌名和 Logo
- [ ] 登录页动态显示该租户配置的 OAuth 按钮
- [ ] 注册→邮箱验证→登录全流程可用
- [ ] 未开启注册的租户不显示注册入口

### Phase 3:企业级 OAuth + 邮件(2 周)— 中优先级

| 改进项 | 理由 | 工作量 |
|---|---|---|
| 改进 4:企业微信独立 OAuth | 企业客户刚需 | 3-5 天 |
| 改进 5:租户级 SMTP | 邮件品牌化最后一公里 | 2-3 天 |
| 改进 6(Nginx 部分):白名单通配 | 个人用户子域名 | 1 天 |

**Phase 3 验收标准**:
- [ ] 租户管理员可在后台配置企业微信 corp_id/agent_id/secret
- [ ] 终端用户可通过企业微信扫码登录
- [ ] 租户 A 的验证邮件从 `noreply@lanyantu.com` 发出
- [ ] `arthur.scrm.com` 可正常访问并兜底到默认租户

### Phase 4:测试与文档(1 周)

| 任务 | 工作量 |
|---|---|
| 编写 auth.md 中 6 个测试用例的 PHPUnit 实现 | 3-4 天 |
| 更新 `docs/auth.md` 修正认知偏差 | 0.5 天 |
| 更新 `docs/zh/` 下相关架构文档 | 1 天 |

---

## 第四部分:取舍总览

| 决策点 | 选择 | 放弃 | 理由 |
|---|---|---|---|
| 企业微信实现方式 | 独立 Service(参考 AlipayOAuthService) | 扩展 Socialite 驱动 | API 差异大,独立更稳健 |
| 飞书/钉钉适配 | 继续用 Socialite 社区驱动 | 独立 Service | 标准 OAuth2 模式,社区驱动成熟 |
| provider 命名空间格式 | `{provider}:tenant:{id}` | `{provider}:tenant_id:{id}` | 更简洁,与 SsoService 风格一致 |
| 旧数据迁移 | 一次性迁移脚本 | 查询时兼容两种格式 | 数据量小,一次性更干净 |
| SMTP 切换方式 | 运行时动态创建 Mailer | config/mail.php 预定义 | 租户数量不确定 |
| SMTP 失败处理 | 静默回退全局 + 告警日志 | 抛异常阻断 | 邮件不应因配置错误完全失败 |
| Session 绑定范围 | 仅非平台域名动态绑定 | 所有域名动态绑定 | 平台域名固定,无需动态 |
| 未识别域名处理 | 403 拒绝 | 兜底默认租户 | 安全优先 |
| 个人子域名映射 | 共享默认租户 SPA | 子域名→User 映射 | 复杂度极高,收益有限 |
| 通配 SSL | 初期用默认证书 | 自动 ACME | 先通后优 |
| 租户发现 API 位置 | Domain 模块 | Auth 模块 | 职责内聚 |
| login-config 认证 | 需 X-Tenant-ID | 完全公开 | 防枚举攻击 |
| 前端页面归属 | 框架层基础模板 + 项目层覆盖 | 纯项目层实现 | 开箱即用 |
| Cookie partitioned(CHIPS) | 不做 | 做 | 浏览器兼容性不足 |
| SMTP 连接池 | 不做 | 做 | 频率低,Symfony Mailer 内部管理 |

---

## 第五部分:风险与缓解

| 风险 | 影响 | 缓解措施 |
|---|---|---|
| provider 命名空间化迁移遗漏 | 旧 OAuth 账号无法匹配 | 迁移脚本 + 回滚方案;迁移后全量校验 |
| BindSessionDomain 与 Sanctum SPA 认证冲突 | SPA 登录流程异常 | Sanctum 的 `stateful` 域名配置需同步更新;充分测试 SPA 登录流程 |
| 企业微信 API 变更 | OAuth 流程中断 | 封装 HTTP 调用,集中管理 API 版本;添加健康检查 |
| 租户 SMTP 配置错误 | 邮件发送失败 | 回退全局 SMTP + 配置测试端点 + 告警 |
| 通配子域名 DNS 解析延迟 | 新子域名短暂不可用 | DNS TTL 设低(60s);Nginx 白名单定时刷新 |
| 前端登录页 API 依赖 | API 不可用时登录页白屏 | 前端降级:API 失败时显示纯邮箱/密码表单 |

---

## 第六部分:总工作量估算

| Phase | 内容 | 工作量 | 累计 |
|---|---|---|---|
| Phase 1 | 安全加固(Session/provider/403) | 2-3.5 天 | 2-3.5 天 |
| Phase 2 | 租户发现 API + 前端页面 | 7-10 天 | 9-13.5 天 |
| Phase 3 | 企业微信 + SMTP + 通配 | 6-9 天 | 15-22.5 天 |
| Phase 4 | 测试 + 文档 | 4-5 天 | 19-27.5 天 |

**总计**: 约 4-6 周(单人全职),可根据优先级裁剪。

**最小可行改进**(若时间紧张): Phase 1 全部 + 改进 1(租户发现 API) = 约 1 周,即可解决安全隔离和前端动态化两个核心问题。

---

## 附录:改进项与 auth.md 缺口对照

| auth.md 缺口编号 | 改进项 | Phase |
|---|---|---|
| G1 公开租户发现 API | 改进 1 | Phase 2 |
| G2 公开 SSO 配置 API | 改进 1 | Phase 2 |
| G3 provider 未命名空间化 | 改进 2 | Phase 1 |
| G4 企业微信独立 API | 改进 4 | Phase 3 |
| G5 Session Cookie 域名绑定 | 改进 3 | Phase 1 |
| G6 租户级 SMTP | 改进 5 | Phase 3 |
| G7 白名单通配 | 改进 6 | Phase 3 |
| G8 未识别域名无 403 | 改进 6 | Phase 1 |
| G9 前端 public 端页面 | 改进 7 | Phase 2 |
| G10 OAuth state 缺 tenant_id | 改进 2 + 改进 4 | Phase 1/3 |
