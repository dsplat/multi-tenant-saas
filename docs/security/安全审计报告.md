# 安全审计报告

**审计范围**: Multi-Tenant SaaS Framework v1.0.0  
**审计标准**: OWASP Top 10 (2021)  
**审计日期**: 2026-06-29  
**审计方式**: `composer audit` + 自动化测试（`tests/SecurityTest.php`）+ 手动代码审查

---

## 1. 审计结论

| 项 | 结果 | 说明 |
|----|------|------|
| OWASP Top 10 高危 | 0 | 无高危发现 |
| SQL 注入 | 通过 | Eloquent 参数绑定 + 禁止字符串拼接原生查询 |
| XSS | 通过 | API 统一返回 `application/json`，不渲染 HTML |
| CSRF | 通过 | 无状态 Bearer Token 鉴权，不依赖 Cookie/Session |
| 敏感数据泄露 | 通过 | password/remember_token 隐藏，手机号脱敏，API Key/Tokens 加密存储 |
| 批量赋值 | 通过 | `$fillable` 白名单，主键与受保护字段不可覆盖 |
| 租户隔离 / 越权 | 通过 | TenantScope 全局作用域 + RBAC 中间件 + 跨租户 403 |
| 依赖漏洞（composer audit） | 3 条 medium | 均为 guzzlehttp 传递依赖，需更新（见 §4） |

> 自动化安全测试见 `tests/SecurityTest.php`（14 用例，全绿）。

---

## 2. OWASP Top 10 (2021) 逐项核查

### A01:2021 — 失效的访问控制（Broken Access Control）✅

**风险**: 越权访问、跨租户数据泄露、垂直/水平越权。

**控制措施**:
- **四重访问架构**: admin / console / app / guest 四层域名隔离，`IdentifyDomain` 中间件识别域名类型。
- **RBAC 细粒度权限**: `rbac.permission:<node>` 中间件在路由级强制权限校验，40+ 权限节点。
- **租户作用域**: `BelongsToTenant` + `TenantScope` 自动为所有查询追加 `WHERE tenant_id = ?`，创建时自动填充 `tenant_id`。
- **跨租户校验**: 控制器显式校验资源归属（`AuthorizesTenantAccess`），跨租户访问返回 403。
- **Sanctum Token abilities**: 14 种细粒度 API 权限，Token 可限定作用域。

**测试**:
- `test_protected_endpoint_rejects_unauthenticated_request` → 401
- `test_rbac_denies_unauthorized_role_access` → 403
- `test_cross_tenant_access_is_forbidden` → 403
- `test_tenant_scope_prevents_cross_tenant_data_leak` → 跨租户数据不可见

### A02:2021 — 加密失败（Cryptographic Failures）✅

**风险**: 敏感数据明文存储/传输。

**控制措施**:
- **密码哈希**: `password` 属性强制 `hashed` cast（bcrypt），永不明文落库。
- **敏感字段隐藏**: `User::$hidden = ['password', 'remember_token']`，序列化永不返回。
- **API Key / OAuth Token 加密存储**: `Crypt::encrypt/decrypt`，`UserApiToken` 明文存储已修复。
- **手机号脱敏**: `UserResource::maskPhone()` 输出 `138****5678`。
- **支付日志脱敏**: 支付日志中敏感卡号/凭证脱敏。
- **HTTPS / HSTS**: 生产环境 `AddSecurityHeaders` 中间件设置 `Strict-Transport-Security`。

**测试**:
- `test_password_is_hidden_in_model_serialization`
- `test_password_is_hashed_not_stored_as_plaintext`
- `test_auth_me_response_does_not_leak_password`
- `test_phone_is_masked_in_user_resource`

### A03:2021 — 注入（Injection）✅

**风险**: SQL 注入。

**控制措施**:
- **Eloquent 参数绑定**: 所有查询使用 PDO 预处理语句，用户输入作为绑定值而非字符串拼接。
- **禁止危险拼接**: 原生查询（`DB::raw` / `whereRaw`）必须使用 `?` 占位符 + 绑定数组。
- **输入校验**: 控制器层 `validate()` 对入参类型/长度强校验。

**测试**:
- `test_sql_injection_payload_is_neutralized_by_parameter_binding` → `' OR '1'='1` 载荷被消解
- `test_raw_query_with_bindings_does_not_leak_cross_tenant_data`

### A04:2021 — 不安全设计（Insecure Design）✅

- **领域事件 + 审计**: 关键操作触发 `LogEventListener` 自动审计，操作可追溯。
- **租户暂停即清 Token**: `suspend` 时清除该租户所有 Sanctum Token，防止被 susp 后继续访问。
- **最后管理员保护**: 删除成员时校验，禁止删除租户内最后一个 `tenant_admin`。

### A05:2021 — 安全配置错误（Security Misconfiguration）✅

- **`APP_DEBUG=false`**: 生产环境强制关闭调试。
- **CORS 环境变量驱动**: `config/cors.php` 通过 env 配置允许来源。
- **安全响应头**: `X-Content-Type-Options: nosniff`、`X-Frame-Options: DENY`、`Referrer-Policy`。
- **`.env` 不入库**: `.gitignore` 排除 `.env`。

### A06:2021 — 易受攻击和过时的组件（Vulnerable Components）⚠

见 §4 依赖漏洞检查。3 条 medium（guzzlehttp），需更新传递依赖。

### A07:2021 — 身份验证失败（Identification and Authentication Failures）✅

- **登录限流**: `/auth/login` 挂载 `throttle:5,1`，`/auth/register` 挂载 `throttle:3,1`。
- **认证后全局限流**: `throttle:api`（60/min，按用户 ID）。
- **密码策略**: `min(8) + mixedCase + numbers`。
- **MFA**: TOTP / 邮箱 / 短信多因素认证 + 恢复码 + 受信设备 + 会话管理。
- **邮箱验证 + 密码重置**: 异步 Job 投递，指数退避重试。

**测试**:
- `test_auth_endpoints_are_protected_by_rate_limit_middleware`

### A08:2021 — 软件和数据完整性失败（Software and Data Integrity Failures）✅

- **批量赋值防护**: 所有模型 `$fillable` 白名单，`$guarded` 默认 `['*']` 以外的字段不可注入。
- **主键全局 ID**: 16 位随机 ID（`HasGlobalId`），无序不可推测，禁止自增。

**测试**:
- `test_mass_assignment_cannot_overwrite_guarded_attributes` → 主键/`email_verified_at`/`login_attempts` 不可覆盖

### A09:2021 — 安全日志和监控失败（Security Logging and Monitoring Failures）✅

- **结构化日志**: `StructuredLogService` 带租户/用户上下文。
- **审计日志**: `AuditService` 全链路审计（Auth/Tenant/Payment/RBAC）。
- **登录日志**: `LoginLogService` 记录登录地理位置/设备。
- **告警系统**: `AlertService` 阈值监控 + 告警规则。
- **Sentry 集成**: 错误追踪（dev）。

### A10:2021 — 服务端请求伪造（SSRF）✅

- **Webhook URL 校验**: 创建 Webhook 时校验 URL 协议与可解析性，禁止内网地址回环。
- **OAuth 回调白名单**: 回调地址走配置白名单，不允许任意跳转。

---

## 3. 手动安全测试结果

| 测试项 | 方法 | 结果 |
|--------|------|------|
| SQL 注入 | 构造 `' OR '1'='1` 载荷查询用户/客户表 | 通过：返回空集，无全表泄露 |
| 反射型 XSS | 用户名注入 `<script>`，请求 `/auth/me` | 通过：`application/json`，脚本作为数据返回 |
| CSRF | 无 Bearer Token 访问受保护端点 | 通过：401，API 不依赖 Cookie |
| 越权（水平） | 租户 A 管理员访问租户 B 成员 | 通过：403 |
| 越权（垂直） | `tenant_admin` 访问 `tenant.view` 端点 | 通过：403 |
| 密码泄露 | `/auth/me` 响应体检查 | 通过：无 `password` 字段 |
| 手机号脱敏 | `UserResource` 输出 | 通过：`138****5678` |
| 批量赋值 | `fill()` 注入主键与受保护字段 | 通过：忽略，保持原值 |
| 登录暴力破解 | 连续错误登录 | 限流中间件生效（`throttle:5,1`） |

---

## 4. 依赖漏洞检查（composer audit）

执行 `composer audit` 输出 3 条 advisory，均为 **medium** 级别，集中在 `guzzlehttp/guzzle` 与 `guzzlehttp/psr7`（传递依赖）：

| CVE | 包 | 严重性 | 标题 |
|-----|----|--------|------|
| CVE-2026-55767 | guzzlehttp/guzzle (<7.12.1) | medium | Dot-only cookie domains match all hosts |
| CVE-2026-55568 | guzzlehttp/guzzle (<7.12.1) | medium | Silent HTTPS proxy downgrade to cleartext |
| CVE-2026-55766 | guzzlehttp/psr7 (<2.12.1) | medium | CRLF injection in HTTP start-line serialization |

**风险评估**: 框架核心未直接以受影响方式使用 guzzle（未设置 dot-only cookie domain、未使用 HTTPS 代理），实际暴露面有限，但建议升级以消除潜在风险。

**修复建议**（需在独立任务中执行，超出本任务可修改范围）：

```bash
composer update guzzlehttp/guzzle guzzlehttp/psr7 --with-all-dependencies
composer audit
```

> 更新传递依赖会变更 `composer.lock`，应在独立分支验证全量测试后再合入。

---

## 5. 安全响应头

`App\Http\Middleware\AddSecurityHeaders` 统一注入：

| 头 | 值 | 说明 |
|----|----|------|
| `X-Content-Type-Options` | `nosniff` | 禁止 MIME 嗅探 |
| `X-Frame-Options` | `DENY` | 禁止被嵌入 iframe（防点击劫持） |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | 限制 Referer 泄露 |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | HSTS（仅生产） |

---

## 6. 残留项与跟进

| 项 | 状态 | 跟进 |
|----|------|------|
| guzzle 传递依赖 3 条 medium | 待修复 | 独立任务执行 `composer update`，更新 `composer.lock` 后回归测试 |
| Octane 部署的缓存/配置隔离 | 已修复 | `TenantContext`/`SocialiteService` 已移除跨请求缓存泄漏 |
| API Key 明文存储 | 已修复 | `UserApiToken` 改用 `Crypt` 加密 |
| 跨租户数据泄露 | 已修复 | File/Subscription/SubscriptionHistory/UserApiToken 已加隔离 |

---

**报告版本**: v1.0.0  
**最后更新**: 2026-06-29
