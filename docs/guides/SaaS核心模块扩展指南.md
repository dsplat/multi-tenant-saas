# SaaS 核心模块扩展指南

> TASK-001 新增模块文档  
> 框架版本: v1.1.0  
> 最后更新: 2026-06-27

---

## 概览

TASK-001 在框架原有 21 个核心服务的基础上，新增 10 个 SaaS 核心模块，覆盖用户管理、租户管理、日志、缓存、队列、健康检查、监控告警、数据导出、API 网关与插件系统。

所有新增服务均：
- 以 `singleton` 注册在 `TenancyServiceProvider`，支持 DI 注入与派生项目替换
- 遵循租户隔离原则，通过 `TenantContext::getId()` 自动附加租户过滤
- 配置驱动，不硬编码业务参数
- 完整 PHPDoc + 类型声明 + 异常处理

---

## 模块清单

### 2.1 用户管理模块 — `UserProfileService`

| 方法 | 说明 |
|------|------|
| `getProfile(int $userId): User` | 获取用户基本信息（含租户/积分/OAuth 关联） |
| `updateProfile(int $userId, array $data): User` | 更新用户基本信息 |
| `getPreferences(int $userId): array` | 获取用户偏好（默认值回退） |
| `updatePreferences(int $userId, array $prefs): array` | 部分更新偏好 |
| `resetPreferences(int $userId): array` | 重置偏好为默认值 |
| `recordLogin(int $userId, ?Request $r): AuditLog` | 记录登录日志 |
| `getLoginLogs(int $userId, int $limit): Collection` | 查询登录日志 |
| `getDevices(int $userId): array` | 获取用户设备列表 |
| `revokeDevice(int $userId, string $ip): int` | 注销指定设备 |
| `detectAnomalousLogin(int $userId, string $ip): bool` | 异常登录检测 |
| `listTenantLoginLogs(int $tenantId, int $perPage): Paginator` | 分页查询租户登录日志 |

存储表：`user_preferences`、`audit_logs`

### 2.2 租户管理模块 — `TenantProfileService`

| 方法 | 说明 |
|------|------|
| `getUsageStats(int $tenantId): array` | 租户使用统计 |
| `getResourceQuota(int $tenantId): array` | 资源配额与使用率 |
| `getBillingInfo(int $tenantId): array` | 账单信息 |
| `getHealthStatus(int $tenantId): array` | 健康状态 |
| `startTrial(int $tenantId, int $days): Tenant` | 开启试用期 |
| `isTrialExpired(int $tenantId): bool` | 试用期是否过期 |
| `migrateData(int $from, int $to, array $res): array` | 租户数据迁移 |
| `backupTenant(int $tenantId): array` | 备份租户配置 |
| `cleanupData(int $tenantId, bool $dryRun): array` | 清理租户数据 |

### 2.3 日志管理模块 — `StructuredLogService`

| 方法 | 说明 |
|------|------|
| `operation(string $action, array $ctx, ?int $uid): int` | 记录操作日志 |
| `error(string $action, Throwable\|array $e, ?int $uid): int` | 记录错误日志 |
| `performance(string $action, float $sec, array $ctx, ?int $uid): int` | 记录性能日志 |
| `security(string $action, array $ctx, ?int $uid): int` | 记录安全日志 |
| `timed(string $action, callable $cb, ?int $uid): mixed` | 计时执行闭包 |
| `query(array $filters, int $perPage): Paginator` | 分页查询日志 |
| `stats(array $filters): array` | 按 category 统计 |
| `exportCsv(array $filters, int $limit): string` | 导出 CSV |
| `alert(string $cat, int $threshold, int $window, callable $cb): int` | 告警触发 |

存储表：`structured_logs`

### 2.4 缓存管理模块 — `CacheService`

| 方法 | 说明 |
|------|------|
| `key(?string $key, ?int $tid): string` | 生成租户级缓存 Key |
| `remember(string $key, callable $cb, int $ttl, ?int $tid): mixed` | 缓存 remember |
| `rememberForever(...)`, `put(...)`, `get(...)`, `forget(...)` | 基础缓存操作 |
| `clearTenant(?int $tid): int` | 清除租户缓存（Redis SCAN） |
| `clearAll(): bool` | 清除所有缓存（admin only） |
| `warmup(array $items, int $ttl, ?int $tid): int` | 缓存预热 |
| `stats(): array` | 缓存统计 |
| `getTtlConfig(): array` | TTL 配置 |

### 2.5 队列监控模块 — `QueueService`

| 方法 | 说明 |
|------|------|
| `isHorizonAvailable(): bool` | Horizon 是否可用 |
| `getStats(): array` | 队列统计概览 |
| `getQueueStats(): array` | 各队列详情 |
| `getFailedJobs(int $limit): Collection` | 失败任务列表 |
| `retryFailed(string\|int $jobId): bool` | 重试失败任务 |
| `retryBatch(array $jobIds): array` | 批量重试 |
| `dispatchToQueue(string $job, string $queue): string` | 派发到指定优先级队列 |
| `checkBacklog(int $threshold): array` | 积压检查 |

### 2.6 健康检查模块 — `HealthCheckService`

| 方法 | 说明 |
|------|------|
| `checkAll(bool $useCache): array` | 执行全部检查 |
| `checkDatabase(): array` | 数据库连接检查 |
| `checkRedis(): array` | Redis 连接检查 |
| `checkQueue(): array` | 队列服务检查 |
| `checkStorage(): array` | 存储服务检查 |
| `checkTenantService(): array` | 租户服务可用性 |
| `checkPaymentService(): array` | 支付服务可用性 |
| `checkOauthService(): array` | OAuth 服务可用性 |
| `checkExternalApis(): array` | 第三方服务可用性 |

### 2.7 监控告警模块 — `PerformanceService` + `AlertService`

**PerformanceService：**
| 方法 | 说明 |
|------|------|
| `recordApiResponse(string $route, float $sec, int $status): void` | 记录 API 响应时间 |
| `recordDbQueries(int $count, float $sec): void` | 记录 DB 查询 |
| `recordMemory(int $used, int $peak): void` | 记录内存使用 |
| `recordCpu(float $percent): void` | 记录 CPU 使用率 |
| `getAggregated(string $metric, int $min): array` | 聚合查询 |
| `getOverview(): array` | 性能概览 |
| `getSlowRequests(float $threshold, int $limit): Collection` | 慢请求列表 |

**AlertService：**
| 方法 | 说明 |
|------|------|
| `trigger(string $rule, string $sev, string $msg, array $ctx): int` | 触发告警 |
| `configureRule(array $rule, ?int $tid): int` | 配置告警规则 |
| `toggleRule(int $id, bool $enabled): int` | 启用/禁用规则 |
| `listRules(?int $tid): Collection` | 列出规则 |
| `history(array $filters, int $perPage): Paginator` | 告警历史 |
| `shouldEscalate(string $rule, int $cooldown): ?string` | 升级机制 |

存储表：`alert_rules`、`alerts`、`structured_logs`

### 2.8 数据导出模块 — `ExportService`

| 方法 | 说明 |
|------|------|
| `exportExcel(array $data, array $headings, string $file): Response` | 同步导出 Excel |
| `exportCsv(array $data, array $headings, string $file): Response` | 同步导出 CSV |
| `exportPdf(string $view, array $data, string $file): Response` | 同步导出 PDF |
| `createAsyncTask(string $job, array $payload, ?int $uid): int` | 创建异步导出任务 |
| `getTaskStatus(int $id): ?\stdClass` | 查询任务进度 |
| `listTasks(int $perPage): Paginator` | 列出任务 |
| `updateTaskStatus(int $id, string $status, ?string $file): int` | 更新任务状态 |
| `downloadTaskFile(int $id): Response` | 下载导出文件 |
| `cleanupOldTasks(int $days): int` | 清理过期任务 |
| `generateExportPath(string $ext): string` | 生成导出路径 |

存储表：`export_tasks`

### 2.9 API 网关模块 — `RateLimitService` + `ApiVersionService`

**RateLimitService：**
| 方法 | 说明 |
|------|------|
| `hit(Request $req, string $scope): bool` | 命中限流 |
| `isLimited(Request $req, string $scope): bool` | 检查是否被限流 |
| `remaining(Request $req, string $scope): int` | 剩余次数 |
| `configureRule(array $rule, ?int $tid): int` | 配置限流规则 |
| `toggleRule(int $id, bool $enabled): int` | 启用/禁用规则 |
| `listRules(?int $tid): Collection` | 列出规则 |
| `dynamicLimit(int $base): int` | 动态限流策略 |

**ApiVersionService：**
| 方法 | 说明 |
|------|------|
| `registerVersion(array $version): int` | 注册版本 |
| `deprecateVersion(string $v, ?string $sunset): int` | 标记废弃 |
| `listVersions(int $perPage): Paginator` | 列出版本 |
| `getActiveVersions(): Collection` | 获取生效版本 |
| `resolveVersionFromRequest(Request $req): string` | 从请求解析版本 |
| `checkDeprecation(Request $req): array` | 检查废弃状态 |
| `isCompatible(string $route, string $version): bool` | 兼容性检查 |
| `addDeprecationHeaders(Response $resp, Request $req): Response` | 添加废弃响应头 |

存储表：`rate_limit_rules`、`api_versions`

### 2.10 插件系统模块 — `PluginService`

| 方法 | 说明 |
|------|------|
| `install(string $name, ?int $tid): int` | 安装插件 |
| `uninstall(string $name, ?int $tid): bool` | 卸载插件 |
| `enable(string $name, ?int $tid): int` | 启用插件 |
| `disable(string $name, ?int $tid): int` | 禁用插件 |
| `getConfig(string $name, ?int $tid): array` | 获取插件配置 |
| `updateConfig(string $name, array $cfg, ?int $tid): int` | 更新插件配置 |
| `listInstalled(?int $tid): Collection` | 列出已安装插件 |
| `scanAvailable(): array` | 扫描可用插件 |
| `checkDependencies(array $manifest): bool` | 依赖检查 |

存储表：`plugins`、`plugin_dependencies`

插件目录约定：`plugins/{name}/manifest.json` + `Plugin\{Name}\PluginServiceProvider`

---

## 使用示例

### 注入服务

```php
use MultiTenantSaas\Services\UserProfileService;
use MultiTenantSaas\Services\StructuredLogService;

public function __construct(
    private UserProfileService $profileService,
    private StructuredLogService $logService,
) {}

public function show(Request $request)
{
    $profile = $this->profileService->getProfile($request->user()->user_id);
    
    $this->logService->operation('profile.view', ['user_id' => $request->user()->user_id]);
    
    return response()->json($profile);
}
```

### 计时执行

```php
$result = $this->logService->timed('user.export', function () use ($userId) {
    return $this->exportService->exportExcel($this->getUsers($userId), ['ID', 'Name'], 'users.xlsx');
});
```

### 告警触发

```php
$this->alertService->trigger(
    ruleName: 'queue_backlog',
    severity: AlertService::SEVERITY_CRITICAL,
    message: "Queue backlog exceeds 1000 jobs",
    context: ['pending' => $count]
);
```

### 缓存预热

```php
$this->cacheService->warmup([
    'user:2001' => fn () => User::find(2001),
    'tenant:1001' => fn () => Tenant::find(1001),
], ttl: 3600);
```

---

## 数据库迁移

新增迁移文件位于 `database/migrations/2026_06_27_*.php`：

| 迁移文件 | 表 |
|---------|-----|
| `000001_create_user_preferences_table` | user_preferences |
| `000002_create_structured_logs_table` | structured_logs |
| `000003_create_alert_tables` | alert_rules, alerts |
| `000004_create_export_tasks_table` | export_tasks |
| `000005_create_api_versions_table` | api_versions |
| `000006_create_plugins_tables` | plugins, plugin_dependencies |
| `000007_create_rate_limit_rules_table` | rate_limit_rules |
| `000008_create_payment_security_tables` | user_payment_passwords, payment_logs |
| `000009_create_oauth_accounts_table` | oauth_accounts（补全缺失迁移） |

运行迁移：
```bash
php artisan migrate
```

---

## 测试覆盖

新增测试文件：`tests/CoreServicesTest.php`，包含 30 个测试用例，覆盖：
- UserProfileService（6 个测试）
- StructuredLogService（9 个测试）
- ApiVersionService（4 个测试）
- ExportService（4 个测试）
- PluginService（3 个测试）
- RateLimitService（3 个测试）
- 异常登录检测（1 个测试）

运行测试：
```bash
vendor/bin/phpunit --filter CoreServicesTest
```
