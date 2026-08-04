# 统一认证中心（IdP）对接协议规范

> 版本: 1.0  
> 状态: 需求提出（待 id.lanyantu.com 实施）  
> 最后更新: 2026-08-04  
> 调用方: multi-tenant-saas 框架 → IdentityProviderOAuthService  
> 被调用方: id.lanyantu.com（蓝眼兔认证中心）

---

## 1. 背景

蓝眼兔体系下多个产品（app.lanyantu.com、scrm.lanyantu.com、bdc 等）共用同一微信 appid，
由 id.lanyantu.com 统一代理 OAuth 授权。当前实现存在以下问题：

| 问题 | 风险 |
|------|------|
| `/login/wechat?redirect=xxx` 不校验 redirect 域名 | 开放重定向，攻击者可窃取 JWT |
| `/verify` 无调用方鉴权 | 任何持有 token 的人可获取用户信息 |
| `/verify` 不返回 unionid/openid | 下游系统无法建立 OAuth 绑定关系 |
| 无 client 注册机制 | 无法区分/审计/吊销各产品的接入权限 |

本规范定义一套**通用 IdP 协议**，参照 OIDC（OpenID Connect）核心流程精简而来，
任何主流认证产品（Auth0、Keycloak、Casdoor、自建 IdP）均可实现。

---

## 2. 核心概念

| 术语 | 说明 |
|------|------|
| **Client** | 接入 IdP 的下游应用（如 scrm.lanyantu.com） |
| **client_id** | 应用唯一标识（注册时分配） |
| **client_secret** | 应用密钥（服务端持有，不暴露给前端） |
| **redirect_uri** | 授权完成后 IdP 回跳的 URL（注册时白名单） |
| **authorization_code** | 一次性授权码（替代当前直接传 JWT 的方式） |
| **IdP Token** | IdP 签发的 JWT，含用户身份 + 受众（aud） |

---

## 3. 端点规范

### 3.1 客户端注册（管理接口，非运行时）

**需求**：IdP 维护一张 `oauth_clients` 表：

```sql
CREATE TABLE oauth_clients (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  client_id     VARCHAR(64) NOT NULL UNIQUE,
  client_secret VARCHAR(128) NOT NULL,
  name          VARCHAR(128) NOT NULL COMMENT '应用名称',
  redirect_uris TEXT NOT NULL COMMENT 'JSON 数组，允许的回跳地址',
  scopes        VARCHAR(255) DEFAULT 'openid profile' COMMENT '允许的范围',
  is_active     TINYINT DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

示例数据：
```
client_id: scrm_prod
client_secret: <random 64 chars>
name: SCRM 客户关系管理
redirect_uris: ["https://scrm.lanyantu.com/api/v1/auth/wechat/callback"]
```

---

### 3.2 授权端点 `GET /authorize`

**替代现有** `/login/wechat`

| 参数 | 必填 | 说明 |
|------|------|------|
| client_id | Y | 调用方标识 |
| redirect_uri | Y | 回跳地址（必须在白名单内） |
| scope | N | 请求范围，默认 `openid profile` |
| state | Y | 防 CSRF 随机串（原样回传） |
| provider | N | 指定登录方式：`wechat`/`wechat_work`，默认 `wechat` |

**行为**：
1. 校验 `client_id` 存在且 `is_active=1`
2. 校验 `redirect_uri` 在该 client 的白名单内（精确匹配或前缀匹配）
3. 校验失败 → 返回 400 + 错误描述（**不跳转**）
4. 校验通过 → 302 跳转到微信授权页（state 中编码 client_id + redirect_uri + state）

**错误响应**（JSON，不跳转）：
```json
{ "error": "invalid_client", "error_description": "client_id not registered" }
{ "error": "invalid_redirect_uri", "error_description": "redirect_uri not in whitelist" }
```

---

### 3.3 授权回调 `GET /callback`（内部，微信回跳）

微信授权完成后回跳到 IdP 自身。IdP 处理 code → 获取 unionid/openid → 生成 **authorization_code**。

**完成后 302 跳转到 client 的 redirect_uri**：
```
{redirect_uri}?code={authorization_code}&state={原始state}
```

**authorization_code 要求**：
- 一次性使用（用后即删）
- 有效期 ≤ 5 分钟
- 绑定 client_id（换 token 时校验）
- 随机不可猜测（≥ 32 字节）

> 这是与当前实现的核心区别：不再直接传 JWT，而是传一次性 code。
> JWT 只在 Token 端点换取，防止 URL 泄露导致 token 被盗用。

---

### 3.4 Token 端点 `POST /token`

**替代现有** `/verify`（服务端对服务端）

| 参数 | 必填 | 说明 |
|------|------|------|
| grant_type | Y | 固定 `authorization_code` |
| code | Y | 授权码 |
| client_id | Y | 调用方标识 |
| client_secret | Y | 调用方密钥 |
| redirect_uri | Y | 与授权请求一致（校验用） |

**鉴权**：`client_id` + `client_secret` 必须匹配 `oauth_clients` 表。

**成功响应**：
```json
{
  "access_token": "<JWT>",
  "token_type": "Bearer",
  "expires_in": 604800,
  "id_token": "<JWT, 含用户身份>",
  "user": {
    "sub": "12345",
    "name": "张三",
    "avatar": "https://...",
    "mobile": "18611703579",
    "email": "zhang@example.com",
    "oauth_bindings": [
      {
        "provider": "wechat",
        "openid": "oXXXX...",
        "unionid": "uXXXX...",
        "appid": "wxc1987290d7cd3517"
      }
    ]
  }
}
```

**关键字段说明**：
- `user.sub`：IdP 内的全局用户 ID（即现有 guid）
- `user.oauth_bindings`：**必须返回**，下游系统依赖此数据建立本地 OAuth 绑定
- `id_token`：标准 JWT，payload 含 `sub`、`aud`（= client_id）、`iss`（= id.lanyantu.com）、`exp`

**错误响应**：
```json
{ "error": "invalid_client", "error_description": "..." }
{ "error": "invalid_grant", "error_description": "code expired or already used" }
```

---

### 3.5 用户信息端点 `GET /userinfo`（可选，扩展用）

| Header | 说明 |
|--------|------|
| Authorization: Bearer {access_token} | IdP 签发的 JWT |
| X-Client-Id | 调用方标识 |
| X-Client-Secret | 调用方密钥 |

返回与 `/token` 中 `user` 结构一致。用于 token 有效期内刷新用户资料。

---

### 3.6 发现端点 `GET /.well-known/openid-configuration`（推荐）

```json
{
  "issuer": "https://id.lanyantu.com",
  "authorization_endpoint": "https://id.lanyantu.com/authorize",
  "token_endpoint": "https://id.lanyantu.com/token",
  "userinfo_endpoint": "https://id.lanyantu.com/userinfo",
  "jwks_uri": "https://id.lanyantu.com/.well-known/jwks.json",
  "scopes_supported": ["openid", "profile", "email", "phone"],
  "response_types_supported": ["code"],
  "grant_types_supported": ["authorization_code"]
}
```

框架可通过此端点自动发现 IdP 能力，无需硬编码路径。

---

## 4. 安全要求

| 项目 | 要求 |
|------|------|
| redirect_uri | 精确匹配白名单，禁止通配符 |
| authorization_code | 一次性、≤5min 有效、绑定 client_id |
| client_secret | 仅服务端传输，HTTPS only |
| JWT | HS256 或 RS256 签名，含 `aud`（受众）防跨系统重放 |
| 频率限制 | /token 端点每 client 100 次/分钟 |
| 日志审计 | 记录每次 /token 调用的 client_id + IP + 时间 |

---

## 5. 向后兼容（过渡期）

现有 `/login/wechat` + `/verify` 接口在过渡期保留，但需补充：
1. `/login/wechat` 增加 `redirect` 白名单校验（硬编码允许的域名列表）
2. `/verify` 增加 `X-Client-Id` + `X-Client-Secret` 头校验
3. `/verify` 响应增加 `oauth_bindings` 字段

新协议（/authorize + /token）就绪后，旧接口标记 deprecated，3 个月后下线。

---

## 6. 框架侧对接方式（已实现 / 待适配）

框架 `IdentityProviderOAuthService` 将按以下优先级对接：

1. **标准协议**（本规范）：`/authorize` → `/token`（authorization_code 流程）
2. **兼容模式**（过渡期）：`/login/wechat?redirect=` → `/verify`（现有 JWT 直传）

租户配置（tenant_settings group=oauth）：
```
oauth_mode              = delegated
idp_base_url            = https://id.lanyantu.com
idp_client_id           = scrm_prod
idp_client_secret       = <secret>
idp_protocol            = standard | legacy
```

---

## 7. 字段映射规范

框架通过可配置映射层适配不同 IdP 的返回格式。以下为**推荐字段名**（IdP 实现时优先采用）：

| 框架字段 | 推荐 IdP 字段 | 兼容别名 | 说明 |
|----------|--------------|----------|------|
| external_id | `sub` | `guid` | IdP 内全局用户唯一 ID |
| name | `name` | `nickname` | 用户显示名 |
| avatar | `avatar` | `headimgurl` | 头像 URL |
| phone | `mobile` | `phone_number`, `phone` | 手机号 |
| email | `email` | — | 邮箱 |
| phone_verified | `mobile_verified` | `phone_verified` | 手机是否已验证（bool） |
| email_verified | `email_verified` | — | 邮箱是否已验证（bool） |
| oauth_bindings | `oauth_bindings` | — | 数组，每项含 provider/openid/unionid/appid |

**映射规则**：
- 框架按 `推荐字段 → 兼容别名` 优先级取第一个非空值
- 租户可通过 `idp_field_mapping` 配置自定义覆盖（JSON 对象）
- `phone_verified=true` 时框架直接信任，不再要求二次短信验证
- `oauth_bindings` 中的 unionid 用于跨应用用户匹配（核心字段，**必须返回**）

**互斥原则**：
- 租户选择 delegated 模式后，框架侧 email/SMS 登录、注册入口全部关闭
- 用户生命周期（注册/注销/改密/验证）完全由 IdP 管理
- 框架仅做「身份映射 + 会话管理」，不越权

---

## 8. 验收标准

- [ ] `oauth_clients` 表创建，scrm_prod 注册完成
- [ ] `GET /authorize?client_id=scrm_prod&redirect_uri=...&state=xxx` 正确跳转微信
- [ ] 非法 redirect_uri 返回 400 而非跳转
- [ ] 微信授权后回跳携带 `code` + `state`
- [ ] `POST /token` 正确返回 user + oauth_bindings（含 unionid/openid）
- [ ] 错误 client_secret 返回 401
- [ ] code 二次使用返回 invalid_grant
- [ ] `/.well-known/openid-configuration` 可访问
