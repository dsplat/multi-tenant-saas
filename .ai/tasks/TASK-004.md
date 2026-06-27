# TASK-004: 全量回归修复 + 支付宝OAuth认证模块接入

**Sprint:** sprint-001  
**状态:** READY  
**预估时间:** 8 小时  
**依赖:** TASK-003 (DONE)  
**Auto-split:** ON  
**人工确认:** OFF（开发性问题按最优解处理）

---

## 目标

1. 对当前代码库（commit `535cf30` + TASK-003 修复）进行全量回归，修复所有阻塞测试的问题
2. 接入支付宝 OAuth 认证模块（独立于 Socialite 标准 OAuth2 流程，因支付宝使用 RSA 签名）
3. 确保全部测试通过，新服务正确注册，i18n 无缺失

---

## 范围

**允许修改：**
- `src/` 目录下所有文件
- `tests/` 目录下所有文件
- `database/migrations/` 目录下迁移文件
- `config/` 目录下配置文件
- `lang/zh_CN/` 和 `lang/en/` 语言文件
- `composer.json`（如需添加依赖）

**禁止修改：**
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口

---

## 前置条件

### TASK-003 已完成项（不需重复）

| 修复 | 文件 | 状态 |
|------|------|------|
| ExportService `downloadTaskFile` fail-closed | `src/Services/ExportService.php` | ✅ |
| AlertService `dispatchNotifications` 租户过滤 | `src/Services/AlertService.php` | ✅ |

### 审查误报已关闭项（不需修改）

- StripeService 已用 `withToken()` ×3
- PayPalService 已有 webhook verify-webhook-signature API 验签
- UnionPayService `verifySignature` 失败返回 `false`
- QueueService 所有方法有 `isHorizonAvailable()` 守卫
- orWhereNull 是"系统级+租户级"设计，非隔离 bug

### 设计决策

- SocialiteService state：直接加显式校验，捕获 `InvalidStateException` → `abort(403)`
- Alipay：独立实现 `AlipayOAuthService`，不依赖 Socialite OAuth2 框架
- RefundService：不存在旧数据，无需向后兼容

---

## Phase 1: 回归基座修复（3 小时）

### T1.1: TestCase 补 11 张缺失表 schema

**文件:** `tests/TestCase.php`

在 `setUpDatabase()` 方法末尾（`subscription_histories` 表之后）添加以下表。结构必须与对应 migration 文件一致。**不加外键约束**（SQLite `:memory:` 兼容性）。

需添加的表（按 migration 文件顺序）：

| 表名 | Migration 来源 |
|------|---------------|
| `system_settings` | `2026_02_17_012512` |
| `user_preferences` | `2026_06_27_000001` |
| `structured_logs` | `2026_06_27_000002` |
| `alert_rules` | `2026_06_27_000003` |
| `alerts` | `2026_06_27_000003` |
| `export_tasks` | `2026_06_27_000004` |
| `api_versions` | `2026_06_27_000005` |
| `plugins` | `2026_06_27_000006` |
| `plugin_dependencies` | `2026_06_27_000006` |
| `rate_limit_rules` | `2026_06_27_000007` |
| `user_payment_passwords` | `2026_06_27_000008` |
| `payment_logs` | `2026_06_27_000008` |
| `oauth_accounts` | `2026_06_27_000009` |

逐表读取对应 migration 文件的 `up()` 方法，在 TestCase 中用 `Schema::create()` 复刻。**去掉所有 `$table->foreign()` 调用**，保留索引。

**验收:** `php vendor/bin/phpunit tests/CoreServicesTest.php` 全部通过。

### T1.2: TenancyServiceProvider 注册新服务

**文件:** `src/TenancyServiceProvider.php`

在 `register()` 方法末尾添加：

```php
// TASK-001 新增核心服务
$this->app->singleton(\MultiTenantSaas\Services\UserProfileService::class);
$this->app->singleton(\MultiTenantSaas\Services\StructuredLogService::class);
$this->app->singleton(\MultiTenantSaas\Services\ApiVersionService::class);
$this->app->singleton(\MultiTenantSaas\Services\ExportService::class);
$this->app->singleton(\MultiTenantSaas\Services\AlertService::class);
$this->app->singleton(\MultiTenantSaas\Services\RateLimitService::class);
$this->app->singleton(\MultiTenantSaas\Services\PluginService::class);
$this->app->singleton(\MultiTenantSaas\Services\QueueService::class);
$this->app->singleton(\MultiTenantSaas\Services\PerformanceService::class);
$this->app->singleton(\MultiTenantSaas\Services\CacheService::class);
$this->app->singleton(\MultiTenantSaas\Services\HealthCheckService::class);
$this->app->singleton(\MultiTenantSaas\Services\StripeService::class);
$this->app->singleton(\MultiTenantSaas\Services\PayPalService::class);
$this->app->singleton(\MultiTenantSaas\Services\UnionPayService::class);
$this->app->singleton(\MultiTenantSaas\Services\RefundService::class);
$this->app->singleton(\MultiTenantSaas\Services\SystemSettingService::class);
$this->app->singleton(\MultiTenantSaas\Services\SocialiteService::class);
```

Phase 3 完成后追加：
```php
$this->app->singleton(\MultiTenantSaas\Services\AlipayOAuthService::class);
```

### T1.3: 全量测试运行与修复

执行 `php vendor/bin/phpunit`，修复所有失败的测试。常见预期问题：

- `trans()` 调用的 key 在 lang 文件中不存在 → 补充翻译
- 服务类构造函数依赖未绑定 → 在 TestCase 或 ServiceProvider 中配置
- 模型 `newFactory()` 缺失 → 已在 TASK-002 修复，验证不回归

**验收:** `php vendor/bin/phpunit` 全绿（8 个测试文件，76 个测试方法）。

### T1.4: PHP 语法检查

```bash
find src/ -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
```

修复所有语法错误。

---

## Phase 2: 安全加固（1 小时）

### T2.1: SocialiteService 显式 state 校验

**文件:** `src/Services/SocialiteService.php`

修改 `handleCallback()` 方法，捕获 `InvalidStateException`：

```php
public static function handleCallback(string $provider, int $tenantId): array
{
    // Alipay 走独立流程（Phase 3 实现）
    if ($provider === 'alipay') {
        return app(AlipayOAuthService::class)->handleCallback($tenantId);
    }

    self::configureDriver($provider, $tenantId);

    try {
        $socialUser = Socialite::driver($provider)->user();
    } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
        abort(403, trans('common.oauth_state_invalid'));
    } finally {
        self::resetDriverConfig($provider);
    }

    $user = self::findOrCreateUser($socialUser, $provider, $tenantId);
    self::recordOAuthAccount($user, $socialUser, $provider, $tenantId);

    return [
        'user' => [
            'user_id' => $user->user_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ],
        'token' => $user->createToken("{$provider}-login")->plainTextToken,
    ];
}
```

同样修改 `getRedirectUrl()`，Alipay 走独立流程：

```php
public static function getRedirectUrl(string $provider, int $tenantId): string
{
    if ($provider === 'alipay') {
        return app(AlipayOAuthService::class)->getAuthorizeUrl($tenantId);
    }

    self::configureDriver($provider, $tenantId);
    try {
        return Socialite::driver($provider)->redirect()->getTargetUrl();
    } finally {
        self::resetDriverConfig($provider);
    }
}
```

### T2.2: 添加 Alipay 到提供商列表

修改 `getSupportedProviders()`：

```php
public static function getSupportedProviders(): array
{
    return [
        'wechat' => ['name' => trans("common.wechat"), 'icon' => 'wechat'],
        'dingtalk' => ['name' => trans("common.dingtalk"), 'icon' => 'dingtalk'],
        'feishu' => ['name' => trans("common.feishu"), 'icon' => 'feishu'],
        'github' => ['name' => 'GitHub', 'icon' => 'github'],
        'google' => ['name' => 'Google', 'icon' => 'google'],
        'alipay' => ['name' => trans("common.alipay"), 'icon' => 'alipay'],
    ];
}
```

修改 `getOAuthConfigForDisplay()` 中 `$providers` 数组追加 `'alipay'`。

---

## Phase 3: 支付宝 OAuth 认证模块（3 小时）

### 背景

支付宝 OAuth 与标准 OAuth2 有本质差异：
- 使用 RSA2 签名（非 client_secret）
- 授权端点独立（非标准 /authorize）
- 通过统一网关 `gateway.do` 调用所有 API
- 回调参数是 `auth_code`（非 `code`）

因此不能复用 Socialite 的 OAuth2 Provider，需独立实现。

### T3.1: 创建 AlipayOAuthService

**新建文件:** `src/Services/AlipayOAuthService.php`

**类结构:**

```php
namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Models\OauthAccount;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\TenantSetting;
```

**租户级配置项（存储在 tenant_settings, group='oauth'）:**

| Key | 说明 | 加密 |
|-----|------|------|
| `alipay_app_id` | 支付宝应用 ID | 否 |
| `alipay_private_key` | 应用私钥（PEM 格式） | 是 |
| `alipay_public_key` | 支付宝公钥（PEM 格式） | 否 |
| `alipay_mode` | sandbox / production | 否 |
| `alipay_redirect` | 回调 URL | 否 |

**需实现的方法:**

#### `getAuthorizeUrl(int $tenantId): string`

生成授权跳转 URL。

- 生产: `https://openauth.alipay.com/oauth2/publicAppAuthorize.htm`
- 沙箱: `https://openauth.alipaydev.com/oauth2/publicAppAuthorize.htm`
- 参数: `app_id`, `scope=auth_user`, `redirect_uri`, `state`（随机串，存 session 用于回调验证）

#### `handleCallback(int $tenantId): array`

处理回调，返回用户信息 + token（与 SocialiteService::handleCallback 返回格式一致）。

流程:
1. 从 request 获取 `auth_code` 和 `state`
2. 验证 state（与 session 中存储的比对，不匹配 abort(403)）
3. 调用 `getAccessToken()` 换取 access_token
4. 调用 `getUserInfo()` 获取用户信息
5. 调用 `findOrCreateUser()`（复用 SocialiteService 的逻辑或独立实现）
6. 记录 OAuth 账号（access_token 加密存储）
7. 返回 `['user' => [...], 'token' => ...]`

#### `getAccessToken(int $tenantId, string $authCode): array`

调用 `alipay.system.oauth.token` 换取 token。

- 网关: `https://openapi.alipay.com/gateway.do`（生产）/ `https://openapi.alipaydev.com/gateway.do`（沙箱）
- 业务参数: `grant_type=authorization_code`, `code={authCode}`
- 公共参数: `app_id`, `method=alipay.system.oauth.token`, `charset=UTF-8`, `sign_type=RSA2`, `sign`, `timestamp`, `version=1.0`
- 签名: 对所有非空非 sign 参数按 key 升序拼接为 `k1=v1&k2=v2&...`，用应用私钥 RSA2 签名
- 响应: `access_token`, `user_id`, `expires_in`, `refresh_token`

#### `getUserInfo(int $tenantId, string $accessToken): array`

调用 `alipay.user.userinfo.share` 获取用户信息。

- 业务参数: `auth_token={accessToken}`
- 响应字段: `user_id`, `nick_name`, `avatar`, `gender`, `province`, `city`, `email`（可能为空）

#### `sign(int $tenantId, array $params): string`

RSA2 签名：

```php
protected function sign(int $tenantId, array $params): string
{
    $privateKeyPem = TenantSetting::get($tenantId, 'oauth', 'alipay_private_key', '');
    if (empty($privateKeyPem)) {
        throw new \RuntimeException(trans('common.oauth_not_configured', ['provider' => 'alipay', 'tenant' => $tenantId]));
    }

    // 过滤空值和 sign 字段，按 key 升序排序
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    unset($params['sign'], $params['sign_type']);
    ksort($params);
    $data = http_build_query($params);

    openssl_sign($data, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
    return base64_encode($signature);
}
```

> 注意：私钥需要是 PEM 格式。如果存储的是纯 base64 字符串，需在运行时包装为 PEM：
> `"-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($key, 64, "\n") . "-----END RSA PRIVATE KEY-----\n"`

#### `verifySign(int $tenantId, array $params, string $sign): bool`

RSA2 验签（验证网关返回数据的签名）：

```php
protected function verifySign(int $tenantId, array $params, string $sign): bool
{
    $publicKeyPem = TenantSetting::get($tenantId, 'oauth', 'alipay_public_key', '');
    if (empty($publicKeyPem)) {
        return false; // fail-closed
    }

    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    unset($params['sign'], $params['sign_type']);
    ksort($params);
    $data = http_build_query($params);

    return openssl_verify($data, base64_decode($sign), $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
}
```

#### `findOrCreateUser(array $alipayUser, int $tenantId): User`

与 SocialiteService::findOrCreateUser 逻辑一致：
1. 通过 `OauthAccount` (provider='alipay', provider_id=alipay_user_id) 查找
2. 不存在则创建 User（email 用 `user_id@alipay` 占位，因支付宝可能不返回 email）
3. 创建 TenantUser 关联
4. 返回 User

#### `isConfigured(int $tenantId): bool`

检查 `alipay_app_id` 和 `alipay_private_key` 是否配置。

### T3.2: i18n 翻译

**文件:** `lang/zh_CN/common.php`, `lang/en/common.php`

新增 key：

| Key | zh_CN | en |
|-----|-------|-----|
| `alipay` | 支付宝 | Alipay |
| `oauth_state_invalid` | OAuth 状态验证失败，请重新登录 | OAuth state validation failed, please try again |

检查并补充以下已有 key（若缺失）：

| Key | zh_CN | en |
|-----|-------|-----|
| `oauth_not_configured` | OAuth 提供商 {provider} 未配置（租户 {tenant}） | OAuth provider {provider} not configured (tenant {tenant}) |
| `task_not_found` | 任务不存在 | Task not found |
| `task_not_completed` | 任务未完成 | Task not completed |
| `file_not_found` | 文件不存在 | File not found |
| `cross_tenant_forbidden` | 禁止跨租户访问 | Cross-tenant access forbidden |
| `rule_name_required` | 规则名称不能为空 | Rule name is required |
| `plugin_not_found` | 插件 {name} 不存在 | Plugin {name} not found |
| `plugin_already_installed` | 插件已安装 | Plugin already installed |
| `plugin_not_installed` | 插件未安装 | Plugin not installed |
| `plugin_uninstall_failed` | 插件卸载失败 | Plugin uninstall failed |
| `plugin_dep_missing` | 依赖 {dep} 缺失 | Dependency {dep} missing |
| `invalid_queue` | 无效的队列名 | Invalid queue name |
| `horizon_not_available` | Horizon 不可用 | Horizon not available |
| `job_retry_failed` | 任务重试失败 | Job retry failed |

### T3.3: 测试

**文件:** `tests/CoreServicesTest.php` 或新建 `tests/AlipayOAuthTest.php`

添加测试：
- `test_alipay_service_can_be_resolved`: `app(AlipayOAuthService::class)` 返回实例
- `test_alipay_is_configured_returns_false_when_not_set`: 未配置时 `isConfigured()` 返回 false
- `test_alipay_is_in_supported_providers`: `SocialiteService::getSupportedProviders()` 包含 alipay

> 注意：不测试实际 HTTP 调用（需要 mock），只测试服务解析和配置逻辑。

---

## Phase 4: i18n 完整性扫描（1 小时）

### T4.1: 扫描全部 trans() 调用

```bash
grep -rohP "trans\(['\"]([^'\"]+)" src/ | sed "s/trans(['\"]//" | sort -u
```

对每个 key，检查 `lang/zh_CN/` 和 `lang/en/` 下对应文件中是否存在。

### T4.2: 补充缺失翻译

对所有缺失的 key，在对应 lang 文件中添加中英文翻译。

**验收:** 上述 grep 命令输出的所有 key 均在 lang 文件中找到。

---

## 全局验收标准

1. `php vendor/bin/phpunit` — 全部测试通过，0 失败
2. `find src/ -name "*.php" -exec php -l {} \;` — 无语法错误
3. `SocialiteService::getSupportedProviders()` 返回 6 个提供商（含 alipay）
4. `app(AlipayOAuthService::class)` 可正常解析
5. `TenantContext::getId()` 为空时 `ExportService::downloadTaskFile()` 返回 403
6. `SocialiteService::handleCallback()` 中 state 不匹配时 `abort(403)`
7. 所有 `trans()` 调用的 key 在 `lang/zh_CN/` 和 `lang/en/` 中均存在
8. `TenancyServiceProvider::register()` 注册了全部 18 个服务（含 AlipayOAuthService）

---

## 状态流转记录

| 时间 | 状态 | 备注 |
|------|------|------|
| 2026-06-28 | READY | 创建任务，auto_split=ON，移交 tool-run 执行 |

---

## 附录：已修复文件清单（TASK-003 完成，不在本 Task 范围内）

### ExportService fail-closed

**文件:** `src/Services/ExportService.php`  
**变更:** `downloadTaskFile()` 中 `if ($task->tenant_id)` 条件跳过改为始终校验

### AlertService 租户过滤

**文件:** `src/Services/AlertService.php`  
**变更:** `dispatchNotifications()` 添加 `TenantContext::getId()` + `where(function ($q) ...)` 租户过滤
