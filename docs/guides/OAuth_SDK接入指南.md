# OAuth SDK 接入指南

> TASK-001 OAuth SDK 扩展文档  
> 框架版本: v1.1.0  
> 最后更新: 2026-06-27

---

## 概览

框架内置 14 种 OAuth 提供商，按厂商分组：

| 厂商 | 提供商 | 说明 |
|------|--------|------|
| 微信 | `wechat` | 微信开放平台（网站应用） |
| 微信 | `wechat_work_internal` | 企业微信（内部应用） |
| 微信 | `wechat_work_thirdparty` | 企业微信（第三方应用） |
| 钉钉 | `dingtalk` | 钉钉（通用扫码登录） |
| 钉钉 | `dingtalk_internal` | 钉钉（企业内部应用） |
| 钉钉 | `dingtalk_thirdparty` | 钉钉（第三方企业应用） |
| 飞书 | `feishu` | 飞书（通用网页登录） |
| 飞书 | `feishu_internal` | 飞书（企业自建应用） |
| 飞书 | `feishu_thirdparty` | 飞书（第三方应用） |
| GitHub | `github` | GitHub OAuth App |
| GitHub | `github_app` | GitHub App |
| Google | `google` | Google OAuth |
| Google | `google_workspace` | Google Workspace |
| Microsoft | `azure_ad` | Azure Active Directory |

---

## 配置

### 1. 全局默认配置（`config/socialite.php`）

```php
'github' => [
    'client_id' => env('GITHUB_CLIENT_ID', ''),
    'client_secret' => env('GITHUB_CLIENT_SECRET', ''),
    'redirect' => env('GITHUB_REDIRECT_URI', '/auth/github/callback'),
],
```

### 2. 租户级配置（动态覆盖）

通过 `SocialiteService::updateOAuthConfig()` 写入 `tenant_settings` 表：

```php
use MultiTenantSaas\Services\SocialiteService;

SocialiteService::updateOAuthConfig($tenantId, 'github', [
    'client_id' => 'Ov23li...',
    'client_secret' => 'secret...',  // 加密存储
    'redirect' => 'https://app.example.com/auth/github/callback',
]);
```

### 3. 安全增强配置

```php
'security' => [
    'pkce_enabled' => true,    // 强制 PKCE
    'state_enabled' => true,   // 强制 state 参数
    'state_ttl' => 300,        // state 缓存 TTL（秒）
    'refresh_enabled' => true, // Token 刷新
    'revoke_enabled' => true,  // Token 撤销
],
```

---

## 接入流程

### 1. 获取重定向 URL

```php
$redirectUrl = SocialiteService::getRedirectUrl('github', $tenantId);
// 返回: https://github.com/login/oauth/authorize?client_id=...&state=...&code_challenge=...
```

### 2. 处理回调

```php
$result = SocialiteService::handleCallback('github', $tenantId, $request->input('state'));
// 返回: ['user' => [...], 'token' => 'sanctum-token']
```

### 3. 刷新 Token

```php
$success = SocialiteService::refreshToken($userId, 'github');
```

### 4. 撤销 Token

```php
$success = SocialiteService::revokeToken($userId, 'github');
```

---

## 配置管理

### 验证配置

```php
$result = SocialiteService::validateConfig($tenantId, 'github');
// ['valid' => true, 'message' => '配置有效']
```

### 测试连接

```php
$result = SocialiteService::testConnection($tenantId, 'github');
// ['success' => true, 'redirect_url' => '...', 'message' => '连接成功']
```

### 导入/导出

```php
// 导出（敏感字段掩码）
$config = SocialiteService::exportConfig($tenantId);

// 导入
SocialiteService::importConfig($tenantId, $config);
```

### 查看配置状态

```php
$display = SocialiteService::getOAuthConfigForDisplay($tenantId);
// ['github' => ['configured' => true, 'client_id' => '...', 'redirect' => '...'], ...]
```

---

## 安全机制

### PKCE（Proof Key for Code Exchange）

- 自动生成 `code_verifier`（64 字符）与 `code_challenge`（SHA256 + Base64URL）
- `code_verifier` 缓存 5 分钟，回调时自动附加
- 防止授权码拦截攻击

### State 参数

- 生成 40 字符随机 state
- 缓存到 Cache（默认 5 分钟 TTL）
- 回调时校验后立即删除（一次性）
- 防止 CSRF 攻击

### Token 加密存储

- `access_token` 与 `refresh_token` 通过 Laravel `encrypt()` 加密存储
- 读取时通过 `decrypt()` 解密
- 即使数据库泄露也无法直接使用 Token

---

## 示例：GitHub OAuth 完整流程

```php
// 1. 在控制器中获取重定向 URL
public function redirectToGithub(Request $request)
{
    $tenantId = TenantContext::getId();
    $url = SocialiteService::getRedirectUrl('github', $tenantId);
    
    return redirect($url);
}

// 2. 处理回调
public function handleGithubCallback(Request $request)
{
    $tenantId = TenantContext::getId();
    
    try {
        $result = SocialiteService::handleCallback('github', $tenantId, $request->input('state'));
        
        return response()->json([
            'success' => true,
            'user' => $result['user'],
            'token' => $result['token'],
        ]);
    } catch (\RuntimeException $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 400);
    }
}
```

---

## 常见问题

### Q: 如何添加新的 OAuth 提供商？

1. 在 `config/socialite.php` 中添加提供商配置
2. 在 `SocialiteService::getSupportedProviders()` 中注册
3. 实现 Socialite 驱动（参考 `SocialiteProviders` 包）
4. 在 `getTokenUrl()` / `getRevokeUrl()` 中添加端点

### Q: Token 过期如何处理？

- `OauthAccount::isTokenExpired()` 判断是否过期
- `SocialiteService::refreshToken()` 使用 refresh_token 刷新
- 可在中间件中自动检测并刷新

### Q: 如何禁用 PKCE/State？

```php
// .env
OAUTH_PKCE_ENABLED=false
OAUTH_STATE_ENABLED=false
```
