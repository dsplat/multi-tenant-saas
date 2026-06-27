# TASK-003: 修复TASK-001新增代码的安全与逻辑问题

**Sprint:** sprint-001  
**状态:** READY  
**预估时间:** 4 小时  
**依赖:** TASK-001 (SUPERSEDED), TASK-002 (DONE)

---

## 目标

修复 commit `535cf30` 中新增代码的 4 个明确问题，使 CoreServicesTest 测试全部通过，新服务正确注册，OAuth 安全增强。

---

## 范围

**只允许修改以下文件：**
- `tests/TestCase.php`
- `src/TenancyServiceProvider.php`
- `src/Services/SocialiteService.php`
- `lang/zh_CN/common.php`
- `lang/en/common.php`

**禁止修改其他文件。**

---

## 前置说明

### 已完成（本轮人工修复，不在本 Task 范围内）

| 修复 | 文件 | 状态 |
|------|------|------|
| ExportService `downloadTaskFile` fail-open → fail-closed | `src/Services/ExportService.php` | ✅ 已修复 |
| AlertService `dispatchNotifications` 无租户过滤 → 已加 tenant_id 过滤 | `src/Services/AlertService.php` | ✅ 已修复 |

### 审查误报（已确认关闭，不需要修改）

| 审查声称 | 实际情况 | 结论 |
|---------|---------|------|
| StripeService 用 `withBasicAuth()` | 已用 `withToken($secretKey)` ×3 处 | 关闭 |
| PayPalService 无 webhook 验签 | 已调用 PayPal verify-webhook-signature API | 关闭 |
| UnionPayService `verifySignature` 返回 `true` | 失败返回 `false`，做了 `openssl_verify` | 关闭 |
| QueueService Horizon 硬依赖 | 所有方法有 `isHorizonAvailable()` 守卫 | 关闭 |
| orWhereNull 破坏租户隔离 ×3 | "系统级 + 租户级"设计，不泄露其他租户数据 | 关闭 |

### 设计决策（已确认）

| 问题 | 决策 |
|------|------|
| SocialiteService state 验证 | 直接加显式校验，捕获 `InvalidStateException` 返回 403 |
| SocialiteService 提供商覆盖 | 添加 Alipay（支付宝） |
| RefundService 向后兼容 | 不存在旧数据，无需处理 |

---

## 任务清单

### T1: TestCase 补 9 张新表 schema（1.5 小时）

**文件:** `tests/TestCase.php`

在 `setUpDatabase()` 方法末尾（`subscription_histories` 表之后、方法闭合 `}` 之前）添加以下 9 张表的 schema 定义。表结构必须与 `database/migrations/2026_06_27_*` 系列迁移文件完全一致。

#### 1.1 `user_preferences`

来源: `2026_06_27_000001_create_user_preferences_table.php`

```php
Schema::create('user_preferences', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('user_id')->unsigned()->unique();
    $table->json('preferences')->nullable();
    $table->timestamps();
});
```

> 注意：不加外键约束（SQLite `:memory:` 下外键会导致测试顺序依赖问题）

#### 1.2 `structured_logs`

来源: `2026_06_27_000002_create_structured_logs_table.php`

```php
Schema::create('structured_logs', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->bigInteger('tenant_id')->unsigned()->nullable();
    $table->bigInteger('user_id')->unsigned()->nullable();
    $table->string('category', 30);
    $table->string('action', 100);
    $table->json('context')->nullable();
    $table->string('ip_address', 45)->nullable();
    $table->string('user_agent', 500)->nullable();
    $table->timestamp('created_at')->nullable();

    $table->index(['tenant_id', 'category', 'created_at']);
    $table->index(['user_id', 'created_at']);
    $table->index('action');
});
```

#### 1.3 `alert_rules` + `alerts`

来源: `2026_06_27_000003_create_alert_tables.php`

```php
Schema::create('alert_rules', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('tenant_id')->unsigned()->nullable();
    $table->string('name', 100);
    $table->string('metric', 100);
    $table->string('operator', 10)->default('>');
    $table->double('threshold')->default(0);
    $table->string('severity', 20)->default('warning');
    $table->json('channels')->nullable();
    $table->integer('cooldown_sec')->default(300);
    $table->boolean('enabled')->default(true);
    $table->timestamps();

    $table->index(['tenant_id', 'enabled']);
    $table->index('metric');
});

Schema::create('alerts', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('tenant_id')->unsigned()->nullable();
    $table->string('rule_name', 100);
    $table->string('severity', 20);
    $table->text('message');
    $table->json('context')->nullable();
    $table->timestamp('triggered_at');
    $table->timestamps();

    $table->index(['tenant_id', 'triggered_at']);
    $table->index(['rule_name', 'triggered_at']);
    $table->index('severity');
});
```

#### 1.4 `export_tasks`

来源: `2026_06_27_000004_create_export_tasks_table.php`

```php
Schema::create('export_tasks', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('tenant_id')->unsigned()->nullable();
    $table->bigInteger('user_id')->unsigned()->nullable();
    $table->string('job_class');
    $table->json('payload')->nullable();
    $table->string('status', 20)->default('pending');
    $table->string('file_path', 500)->nullable();
    $table->boolean('error')->default(false);
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->index('user_id');
});
```

#### 1.5 `api_versions`

来源: `2026_06_27_000005_create_api_versions_table.php`

```php
Schema::create('api_versions', function (Blueprint $table) {
    $table->id();
    $table->string('version', 20)->unique();
    $table->string('status', 20)->default('stable');
    $table->date('release_date')->nullable();
    $table->date('sunset_date')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->index('status');
});
```

#### 1.6 `plugins` + `plugin_dependencies`

来源: `2026_06_27_000006_create_plugins_tables.php`

```php
Schema::create('plugins', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('tenant_id')->unsigned()->nullable();
    $table->string('name', 100);
    $table->string('version', 30)->nullable();
    $table->string('status', 20)->default('installed');
    $table->json('manifest')->nullable();
    $table->json('config')->nullable();
    $table->timestamp('installed_at')->nullable();
    $table->timestamp('enabled_at')->nullable();
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
    $table->unique(['tenant_id', 'name']);
});

Schema::create('plugin_dependencies', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('plugin_id');
    $table->string('dependency_name', 200);
    $table->string('version_constraint', 100)->nullable();
    $table->timestamps();

    $table->index('dependency_name');
});
```

> 注意：不加外键约束（同 1.1 原因）

#### 1.7 `rate_limit_rules`

来源: `2026_06_27_000007_create_rate_limit_rules_table.php`

```php
Schema::create('rate_limit_rules', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('tenant_id')->unsigned()->nullable();
    $table->string('scope', 20)->default('user');
    $table->string('pattern', 200)->nullable();
    $table->unsignedInteger('max_attempts')->default(60);
    $table->unsignedInteger('decay_sec')->default(60);
    $table->string('strategy', 30)->default('fixed');
    $table->boolean('enabled')->default(true);
    $table->timestamps();

    $table->index(['tenant_id', 'enabled']);
    $table->index(['scope', 'enabled']);
});
```

**验收:** `php vendor/bin/phpunit tests/CoreServicesTest.php` 全部 26 个测试通过。

---

### T2: TenancyServiceProvider 注册新服务（0.5 小时）

**文件:** `src/TenancyServiceProvider.php`

在 `register()` 方法末尾（Payment 模块注册之后、方法闭合 `}` 之前）添加以下服务注册：

```php
// TASK-001 新增核心服务注册
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
```

**验收:** `php vendor/bin/phpunit tests/CoreServicesTest.php` 中所有 `app(xxxService::class)` 调用正常解析。

---

### T3: SocialiteService 显式 state 校验（0.5 小时）

**文件:** `src/Services/SocialiteService.php`

修改 `handleCallback()` 方法，捕获 `InvalidStateException` 并返回 403：

```php
public static function handleCallback(string $provider, int $tenantId): array
{
    self::configureDriver($provider, $tenantId);

    try {
        $socialUser = Socialite::driver($provider)->user();
    } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
        self::resetDriverConfig($provider);
        abort(403, trans('common.oauth_state_invalid'));
    } finally {
        self::resetDriverConfig($provider);
    }

    // ... 后续逻辑不变
}
```

> 注意：`finally` 块在 `catch` 之后仍会执行 `resetDriverConfig`，需确保不会重复执行。实际实现时应将 `resetDriverConfig` 只放在 `finally` 中，`catch` 只做 `abort`。

正确实现：

```php
public static function handleCallback(string $provider, int $tenantId): array
{
    self::configureDriver($provider, $tenantId);

    try {
        $socialUser = Socialite::driver($provider)->user();
    } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
        abort(403, trans('common.oauth_state_invalid'));
    } finally {
        self::resetDriverConfig($provider);
    }

    // 查找或创建用户
    $user = self::findOrCreateUser($socialUser, $provider, $tenantId);

    // 记录 OAuth 账号
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

**验收:** 代码无语法错误，`abort(403)` 在 state 不匹配时触发。

---

### T4: SocialiteService 添加 Alipay 提供商（1 小时）

**文件:** `src/Services/SocialiteService.php`, `lang/zh_CN/common.php`, `lang/en/common.php`

#### 4.1 修改 `getSupportedProviders()`

在 `src/Services/SocialiteService.php` 的 `getSupportedProviders()` 方法中，在 `'google'` 之后添加：

```php
'alipay' => ['name' => trans("common.alipay"), 'icon' => 'alipay'],
```

#### 4.2 修改 `getOAuthConfigForDisplay()`

在 `$providers` 数组中添加 `'alipay'`：

```php
$providers = ['wechat', 'dingtalk', 'feishu', 'github', 'google', 'alipay'];
```

#### 4.3 添加语言翻译

在 `lang/zh_CN/common.php` 中添加：

```php
'alipay' => '支付宝',
'oauth_state_invalid' => 'OAuth 状态验证失败，请重新登录',
```

在 `lang/en/common.php` 中添加：

```php
'alipay' => 'Alipay',
'oauth_state_invalid' => 'OAuth state validation failed, please try again',
```

> 注意：Alipay 的实际 Socialite 驱动需要实现项目安装第三方包（如 `socialiteproviders/alipay`）。框架层只需提供配置入口和提供商注册，不强制依赖。

**验收:** `SocialiteService::getSupportedProviders()` 返回 6 个提供商，包含 alipay。

---

### T5: i18n 补全（0.5 小时）

**文件:** `lang/zh_CN/common.php`, `lang/en/common.php`

检查 T3 和 T4 中新增的翻译键是否已存在，若不存在则添加：

| Key | zh_CN | en |
|-----|-------|-----|
| `oauth_state_invalid` | OAuth 状态验证失败，请重新登录 | OAuth state validation failed, please try again |
| `alipay` | 支付宝 | Alipay |

同时检查以下已有翻译键是否存在（若缺失则补充）：

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

---

## 验收标准

1. `php vendor/bin/phpunit tests/CoreServicesTest.php` — 全部 26 个测试通过
2. `php vendor/bin/phpunit` — 全部已有测试通过（不引入回归）
3. `php -l src/Services/SocialiteService.php` — 无语法错误
4. `php -l src/TenancyServiceProvider.php` — 无语法错误
5. `php -l tests/TestCase.php` — 无语法错误
6. `SocialiteService::getSupportedProviders()` 返回 6 个提供商（含 alipay）
7. `TenantContext::getId()` 在无租户时，`ExportService::downloadTaskFile()` 返回 403
8. `AlertService::dispatchNotifications()` 只查询当前租户 + 系统级规则

---

## 状态流转记录

| 时间 | 状态 | 备注 |
|------|------|------|
| 2026-06-28 | READY | 创建任务，移交 tool-run 执行 |

---

## 附录：已修复文件清单（本轮人工完成，不在本 Task 范围内）

### ExportService fail-closed 修复

**文件:** `src/Services/ExportService.php`  
**行:** `downloadTaskFile()` 方法  
**变更:**

```diff
- $tenantId = TenantContext::getId();
- if ($task->tenant_id) {
-     if (!$tenantId || (int) $task->tenant_id !== (int) $tenantId) {
-         abort(403, trans('common.cross_tenant_forbidden'));
-     }
- }
+ $tenantId = TenantContext::getId();
+ if (!$tenantId || (int) ($task->tenant_id ?? 0) !== (int) $tenantId) {
+     abort(403, trans('common.cross_tenant_forbidden'));
+ }
```

### AlertService 租户过滤修复

**文件:** `src/Services/AlertService.php`  
**行:** `dispatchNotifications()` 方法  
**变更:** 添加 `TenantContext::getId()` 获取 + `where(function ($q) ...)` 租户过滤查询条件。
