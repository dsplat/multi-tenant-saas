# 服务层 API

**最后更新**: 2026-06-18

---

## TenantService

租户管理服务，提供租户 CRUD 操作。

### 方法

#### list(array $filters = [])

获取租户列表（带分页和筛选）。

```php
$tenantService = app(TenantService::class);

$tenants = $tenantService->list([
    'search' => 'keyword',
    'status' => 'active',
    'plan' => 'pro',
    'active_only' => true,
    'sort_by' => 'created_at',
    'sort_direction' => 'desc',
    'per_page' => 15,
]);

// 返回: LengthAwarePaginator
```

#### create(array $data)

创建租户。

```php
$tenant = $tenantService->create([
    'name' => '企业名称',
    'slug' => 'company-slug',
    'status' => 'active',
    'plan' => 'pro',
    'custom_domain' => 'ai.company.com',
    'description' => '企业描述',
    'contact_name' => '联系人',
    'contact_email' => 'contact@company.com',
    'contact_phone' => '13800138000',
    'total_credits' => 10000,
    'settings' => [],
    'branding' => [],
]);

// 返回: Tenant
```

#### update(int $tenantId, array $data)

更新租户。

```php
$tenant = $tenantService->update(1234567890123456, [
    'name' => '新名称',
    'status' => 'active',
]);

// 返回: Tenant
```

#### delete(int $tenantId)

删除租户（软删除）。

```php
$result = $tenantService->delete(1234567890123456);
// 返回: bool
```

#### find(int $tenantId)

查找租户。

```php
$tenant = $tenantService->find(1234567890123456);
// 返回: Tenant
```

#### getMembers(int $tenantId)

获取租户成员列表。

```php
$members = $tenantService->getMembers(1234567890123456);
// 返回: Collection
```

#### getFinancials(int $tenantId)

获取租户财务信息。

```php
$financials = $tenantService->getFinancials(1234567890123456);
// 返回: array
```

---

## TenantSettingService

租户配置管理服务。

### 配置组常量

```php
const GROUP_INFO = 'info';           // 企业信息
const GROUP_OAUTH = 'oauth';         // OAuth 配置
const GROUP_AUTH = 'auth';           // 登录认证限制
const GROUP_MAIL = 'mail';           // 邮件服务配置
const GROUP_REGISTRATION = 'registration';  // 开放注册配置
```

### 方法

#### getTenantInfo(int $tenantId)

获取企业信息配置。

```php
$settingService = app(TenantSettingService::class);

$info = $settingService->getTenantInfo(1234567890123456);
// 返回: array
```

#### updateTenantInfo(int $tenantId, array $data)

更新企业信息配置。

```php
$settingService->updateTenantInfo(1234567890123456, [
    'name' => '新名称',
    'logo' => 'https://example.com/logo.png',
    'domain' => 'ai.newdomain.com',
    'description' => '新描述',
    'contact_email' => 'new@company.com',
    'contact_phone' => '13900139000',
]);
```

#### getOAuthConfig(int $tenantId)

获取 OAuth 配置。

```php
$oauthConfig = $settingService->getOAuthConfig(1234567890123456);
// 返回: array (wechat, dingtalk, feishu)
```

#### updateOAuthConfig(int $tenantId, string $provider, array $config)

更新 OAuth 配置。

```php
$settingService->updateOAuthConfig(1234567890123456, 'wechat', [
    'enabled' => true,
    'corp_id' => 'wx1234567890',
    'agent_id' => '1000001',
    'secret' => 'secret_key',
]);
```

#### getAuthConfig(int $tenantId)

获取登录认证配置。

```php
$authConfig = $settingService->getAuthConfig(1234567890123456);
// 返回: array
```

#### updateAuthConfig(int $tenantId, array $config)

更新登录认证配置。

```php
$settingService->updateAuthConfig(1234567890123456, [
    'allow_phone_login' => true,
    'allow_password_login' => true,
    'email_domains' => ['company.com', 'subsidiary.com'],
]);
```

#### getRegistrationConfig(int $tenantId)

获取开放注册配置。

```php
$registrationConfig = $settingService->getRegistrationConfig(1234567890123456);
// 返回: array
```

#### updateRegistrationConfig(int $tenantId, array $config)

更新开放注册配置。

```php
$settingService->updateRegistrationConfig(1234567890123456, [
    'allow_register' => true,
    'welcome_credits' => 500,
]);
```

---

## TenantCreditService

积分/配额管理服务。

### 方法

#### getBalance(int $tenantId, ?int $userId = null)

获取积分余额。

```php
$creditService = app(TenantCreditService::class);

$balance = $creditService->getBalance(1234567890123456);
// 返回: int
```

#### addCredits(int $tenantId, int $amount, string $description, ?int $userId = null)

添加积分。

```php
$creditService->addCredits(1234567890123456, 1000, '充值');
```

#### deductCredits(int $tenantId, int $amount, string $description, ?int $userId = null)

扣除积分。

```php
$creditService->deductCredits(1234567890123456, 100, '使用服务');
```

#### checkQuota(int $tenantId, string $resource, int $amount = 1)

检查配额。

```php
$hasQuota = $creditService->checkQuota(1234567890123456, 'customers', 1);
// 返回: bool
```

#### getTransactions(int $tenantId, ?int $userId = null, int $limit = 50)

获取交易记录。

```php
$transactions = $creditService->getTransactions(1234567890123456);
// 返回: Collection
```

---

## TenantMemberService

租户成员管理服务。

### 方法

#### list(int $tenantId, array $filters = [])

获取成员列表。

```php
$memberService = app(TenantMemberService::class);

$members = $memberService->list(1234567890123456, [
    'role' => 'tenant_admin',
    'is_active' => true,
]);

// 返回: Collection
```

#### add(int $tenantId, int $userId, string $role = 'end_user')

添加成员。

```php
$memberService->add(1234567890123456, 9876543210987654, 'tenant_admin');
```

#### updateRole(int $tenantId, int $userId, string $role)

更新成员角色。

```php
$memberService->updateRole(1234567890123456, 9876543210987654, 'tenant_admin');
```

#### remove(int $tenantId, int $userId)

移除成员。

```php
$memberService->remove(1234567890123456, 9876543210987654);
```

#### isActive(int $tenantId, int $userId)

检查成员是否激活。

```php
$isActive = $memberService->isActive(1234567890123456, 9876543210987654);
// 返回: bool
```

---

## SocialiteService

第三方登录服务（基于 laravel/socialite）。

> **注意**：原 `OAuthService` 已废弃，统一使用 `SocialiteService`。

### 方法

#### getRedirectUrl(string $provider, int $tenantId): string

获取 OAuth 重定向 URL。

```php
$url = SocialiteService::getRedirectUrl('wechat', 1234567890123456);
```

#### handleCallback(string $provider, int $tenantId): array

处理 OAuth 回调，返回用户信息和 token。

```php
$result = SocialiteService::handleCallback('wechat', 1234567890123456);
// 返回: ['user' => [...], 'token' => '...']
```

#### isConfigured(int $tenantId, string $provider): bool

检查租户是否已配置指定 OAuth。

```php
$isConfigured = SocialiteService::isConfigured(1234567890123456, 'wechat');
```

#### getOAuthConfigForDisplay(int $tenantId): array

获取租户 OAuth 配置（用于后台展示，敏感信息脱敏）。

```php
$config = SocialiteService::getOAuthConfigForDisplay(1234567890123456);
```

#### updateOAuthConfig(int $tenantId, string $provider, array $config): void

更新租户 OAuth 配置。

```php
SocialiteService::updateOAuthConfig(1234567890123456, 'wechat', [
    'client_id' => 'wx1234567890',
    'client_secret' => '********', // 遮罩占位符会被跳过
]);
```

处理 OAuth 回调。

```php
$user = $oauthService->callback(1234567890123456, 'wechat', [
    'code' => 'auth_code',
]);

// 返回: User
```

---

## UserService

用户管理服务。

### 方法

#### find(int $userId)

查找用户。

```php
$userService = app(UserService::class);

$user = $userService->find(9876543210987654);
// 返回: User
```

#### create(array $data)

创建用户。

```php
$user = $userService->create([
    'name' => '用户名',
    'email' => 'user@example.com',
    'password' => 'password',
    'role' => 'platform_user',
]);

// 返回: User
```

#### update(int $userId, array $data)

更新用户。

```php
$user = $userService->update(9876543210987654, [
    'name' => '新名称',
    'email' => 'new@example.com',
]);

// 返回: User
```

#### delete(int $userId)

删除用户（软删除）。

```php
$result = $userService->delete(9876543210987654);
// 返回: bool
```

---

## SystemSettingService

系统配置管理服务。

### 方法

#### get(string $key, $default = null)

获取系统配置。

```php
$settingService = app(SystemSettingService::class);

$value = $settingService->get('app.name');
// 返回: mixed
```

#### set(string $key, $value, bool $encrypted = false)

设置系统配置。

```php
$settingService->set('app.name', 'Multi-Tenant SaaS');
```

#### getGroup(string $group)

获取配置组。

```php
$config = $settingService->getGroup('app');
// 返回: array
```

---

## 辅助函数

#### tenant_id()

获取当前租户 ID。

```php
$tenantId = tenant_id();
// 返回: string|null
```

#### tenant_config($key, $default = null)

获取租户配置。

```php
$corpId = tenant_config('wecom', 'corp_id');
// 返回: mixed
```

#### check_quota($resource, $amount = 1)

检查配额。

```php
check_quota('customers', 1);
// 如果配额不足，抛出 QuotaExceededException
```

#### generate_id()

生成全局唯一 ID。

```php
$id = generate_id();
// 返回: int
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
