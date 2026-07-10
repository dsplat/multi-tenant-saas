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

## WebhookService

Webhook 事件订阅与交付服务，支持重试、签名验证、交付记录。

### 方法

#### registerWebhook(int $tenantId, array $config)

注册 Webhook 端点。

```php
$webhook = app(WebhookService::class)->registerWebhook($tenantId, [
    'url' => 'https://api.example.com/webhook',
    'events' => ['tenant.created', 'payment.completed'],
    'secret' => 'whsec_xxx',
]);
```

#### dispatchEvent(string $event, array $payload)

分发事件到所有订阅的 Webhook。

```php
app(WebhookService::class)->dispatchEvent('tenant.created', ['tenant_id' => 123]);
```

#### getDeliveryLog(int $webhookId, int $limit = 50)

获取交付记录。

---

## EventBusService

事件总线服务，支持异步事件分发、死信队列、事件订阅管理。

### 方法

#### publish(string $event, array $payload, bool $async = true)

发布事件。

```php
app(EventBusService::class)->publish('user.registered', ['user_id' => 456]);
```

#### subscribe(string $event, string $handler)

订阅事件。

```php
app(EventBusService::class)->subscribe('user.registered', SendWelcomeEmail::class);
```

#### getDeadLetters(int $limit = 50)

获取死信队列。

---

## FeatureFlagService

功能开关服务，支持按租户、百分比、规则评估功能开关。

### 方法

#### createFlag(array $data)

创建功能开关。

```php
app(FeatureFlagService::class)->createFlag([
    'name' => 'new_dashboard',
    'enabled' => false,
    'rollout_percentage' => 10,
    'allowed_tenants' => [123, 456],
]);
```

#### isEnabled(string $flag, ?int $tenantId = null)

检查功能开关是否启用。

```php
if (app(FeatureFlagService::class)->isEnabled('new_dashboard', $tenantId)) {
    // 显示新面板
}
```

#### setRolloutPercentage(string $flag, int $percentage)

设置灰度百分比。

---

## CostService

成本追踪与资源计费服务。

### 方法

#### recordUsage(int $tenantId, string $resource, float $amount, string $unit)

记录资源用量。

```php
app(CostService::class)->recordUsage($tenantId, 'api_calls', 100, 'calls');
```

#### getCostBreakdown(int $tenantId, string $period = 'monthly')

获取成本明细。

```php
$breakdown = app(CostService::class)->getCostBreakdown($tenantId, 'monthly');
```

#### allocateCost(int $tenantId, array $allocation)

分配成本到部门/项目。

---

## ResourceService

租户资源配额管理服务。

### 方法

#### setQuota(int $tenantId, string $resource, int $limit)

设置资源配额。

```php
app(ResourceService::class)->setQuota($tenantId, 'storage_gb', 100);
```

#### getUsage(int $tenantId, string $resource)

获取资源使用量。

```php
$usage = app(ResourceService::class)->getUsage($tenantId, 'storage_gb');
```

#### checkAvailability(int $tenantId, string $resource, int $amount)

检查资源是否可用。

---

## ReportService

自定义报表生成服务，支持 PDF / Excel 导出。

### 方法

#### createReport(array $config)

创建报表任务。

```php
$report = app(ReportService::class)->createReport([
    'name' => '月度用量报告',
    'type' => 'usage',
    'period' => '2026-06',
    'format' => 'pdf',
]);
```

#### generate(int $reportId)

生成报表文件。

#### download(int $reportId)

下载报表。

---

## ErrorTrackingService

错误追踪与聚合服务。

### 方法

#### reportError(Throwable $e, array $context = [])

上报错误。

```php
app(ErrorTrackingService::class)->reportError($exception, ['tenant_id' => $id]);
```

#### getErrorStats(int $tenantId, string $period = '24h')

获取错误统计。

```php
$stats = app(ErrorTrackingService::class)->getErrorStats($tenantId, '24h');
// ['total' => 42, 'unique' => 8, 'top_errors' => [...]]
```

#### resolveError(string $errorId)

标记错误为已解决。

---

## BroadcastingService

实时广播服务，支持 WebSocket / SSE 推送。

### 方法

#### broadcast(string $channel, string $event, array $data)

广播事件。

```php
app(BroadcastingService::class)->broadcast('tenant.123', 'notification', ['msg' => 'hello']);
```

#### broadcastToUser(int $userId, string $event, array $data)

向指定用户广播。

#### getActiveChannels()

获取活跃频道列表。

---

## InAppNotificationService

应用内通知服务，支持未读数、偏好设置、批量标记已读。

### 方法

#### send(int $userId, array $notification)

发送应用内通知。

```php
app(InAppNotificationService::class)->send($userId, [
    'type' => 'info',
    'title' => '任务完成',
    'message' => '您的导出任务已完成',
]);
```

#### getUnread(int $userId, int $limit = 20)

获取未读通知。

#### markAsRead(int $notificationId)

标记已读。

#### markAllAsRead(int $userId)

全部标记已读。

---

## IsolationService

数据隔离策略服务，支持 shared-db / schema-based / database-based 策略。

### 方法

#### getStrategy(string $type)

获取隔离策略实例。

```php
$strategy = app(IsolationService::class)->getStrategy('shared-db');
```

#### migrateTenant(int $tenantId, string $targetStrategy)

迁移租户隔离策略。

#### getCurrentStrategy(int $tenantId)

获取当前策略。

---

## DataResidencyService

数据驻留管理服务，支持区域配置、跨区域迁移。

### 方法

#### setResidencyRegion(int $tenantId, string $region)

设置数据驻留区域。

```php
app(DataResidencyService::class)->setResidencyRegion($tenantId, 'CN');
```

#### migrateToRegion(int $tenantId, string $targetRegion)

跨区域迁移数据。

#### getResidencyConfig(int $tenantId)

获取驻留配置。

#### getAvailableRegions()

获取可用区域列表。

---

## TenantCloneService

租户克隆服务，支持模板创建、快照导入导出、克隆验证。

### 方法

#### createFromTemplate(int $sourceId, array $config)

从模板租户创建新租户。

```php
$newTenant = app(TenantCloneService::class)->createFromTemplate($templateId, [
    'name' => 'New Corp',
    'slug' => 'new-corp',
]);
```

#### exportSnapshot(int $tenantId)

导出租户快照。

```php
$snapshot = app(TenantCloneService::class)->exportSnapshot($tenantId);
```

#### importSnapshot(int $targetId, array $snapshot)

导入快照到租户。

#### exportSnapshotJson(int $tenantId)

导出 JSON 格式快照。

---

## CrossTenantService

跨租户数据共享服务，支持层级关系、数据共享规则。

### 方法

#### establishHierarchy(int $parentId, int $childId, array $config)

建立父子租户关系。

```php
app(CrossTenantService::class)->establishHierarchy($parentId, $childId, [
    'shared_tables' => ['customers', 'products'],
]);
```

#### shareData(int $sourceId, int $targetId, string $table, array $filters)

跨租户共享数据。

#### getHierarchy(int $tenantId)

获取租户层级关系。

---

## SandboxService

沙盒环境服务，支持独立测试环境创建、数据隔离、过期清理。

### 方法

#### createSandbox(int $tenantId, array $config)

创建沙盒环境。

```php
$sandbox = app(SandboxService::class)->createSandbox($tenantId, [
    'expires_at' => now()->addDays(7),
    'copy_data' => ['customers'],
]);
```

#### destroySandbox(int $sandboxId)

销毁沙盒。

#### listSandboxes(int $tenantId)

列出租户的沙盒。

---

## DeveloperPortalService

开发者门户服务，支持 API Key 管理、文档、SDK 生成。

### 方法

#### getPortalConfig(int $tenantId)

获取门户配置。

#### generateApiKey(int $tenantId, array $permissions)

生成 API 密钥。

#### revokeApiKey(string $keyId)

吊销 API 密钥。

#### listApiKeys(int $tenantId)

列出 API 密钥。

---

## TenantKeyService

租户 BYOK（Bring Your Own Key）密钥管理服务。

### 方法

#### storeKey(int $tenantId, array $keyData)

存储租户自定义密钥。

```php
app(TenantKeyService::class)->storeKey($tenantId, [
    'provider' => 'openai',
    'key' => 'sk-xxx',
    'encrypted' => true,
]);
```

#### getKey(int $tenantId, string $provider)

获取密钥（自动解密）。

#### rotateKey(int $tenantId, string $provider, string $newKey)

轮换密钥。

#### deleteKey(int $tenantId, string $provider)

删除密钥。

---

## MetricsService

指标统计与快照服务。

### 方法

#### recordMetric(string $name, float $value, array $tags = [])

记录指标。

```php
app(MetricsService::class)->recordMetric('api_latency_ms', 45.2, ['endpoint' => '/users']);
```

#### getMetrics(string $name, string $period = '1h')

获取指标数据。

#### takeSnapshot(int $tenantId)

生成指标快照。

---

## SlaService

SLA 管理服务，支持 SLA 定义、事件追踪、违规检测。

### 方法

#### defineSla(array $config)

定义 SLA 规则。

```php
app(SlaService::class)->defineSla([
    'name' => '99.9% Uptime',
    'target' => 99.9,
    'window' => 'monthly',
]);
```

#### recordEvent(int $tenantId, string $event, array $data)

记录 SLA 事件。

#### checkCompliance(int $tenantId, string $slaName)

检查 SLA 合规性。

#### getViolationReport(int $tenantId)

获取 SLA 违规报告。

---

**文档版本**: v1.1.0  
**最后更新**: 2026-06-29
