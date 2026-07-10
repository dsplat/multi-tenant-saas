# 核心 API

**最后更新**: 2026-06-18

---

## TenantContext

租户上下文管理器，用于获取和设置当前请求的租户信息。

### 方法

#### getId()

获取当前租户 ID。

```php
$tenantId = TenantContext::getId();
// 返回: string|null (如 "1234567890123456")
```

#### setId(?string $tenantId)

设置当前租户 ID。

```php
TenantContext::setId('1234567890123456');
```

#### getTenant()

获取当前租户对象。

```php
$tenant = TenantContext::getTenant();
// 返回: Tenant|null
```

#### setTenant(?Tenant $tenant)

设置当前租户对象。

```php
$tenant = Tenant::find(1234567890123456);
TenantContext::setTenant($tenant);
```

#### getDomainType()

获取当前域名类型。

```php
$domainType = TenantContext::getDomainType();
// 返回: string|null ('admin'|'console'|'api'|'app'|'default')
```

#### setDomainType(?string $type)

设置当前域名类型。

```php
TenantContext::setDomainType('console');
```

#### getTenantRole()

获取当前用户在租户内的角色。

```php
$role = TenantContext::getTenantRole();
// 返回: string|null ('super_admin'|'tenant_admin'|'end_user')
```

#### setTenantRole(?string $role)

设置当前用户在租户内的角色。

```php
TenantContext::setTenantRole('tenant_admin');
```

#### clear()

清除所有上下文（用于测试）。

```php
TenantContext::clear();
```

---

## IdGenerator

全局唯一 ID 生成器，生成 16 位随机数字 ID。

### 方法

#### generate()

生成新的全局唯一 ID。

```php
$idGenerator = app(IdGenerator::class);
$id = $idGenerator->generate();
// 返回: int (如 1234567890123456)
```

#### batch(int $count = 10)

批量生成 ID。

```php
$ids = $idGenerator->batch(10);
// 返回: array<int, int>
```

#### validate(string|int $id)

验证 ID 格式是否正确。

```php
$isValid = $idGenerator->validate('1234567890123456');
// 返回: bool
```

#### isJsSafe(string|int $id)

检查 ID 是否在 JavaScript 安全范围内。

```php
$isSafe = $idGenerator->isJsSafe('1234567890123456');
// 返回: bool
```

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
