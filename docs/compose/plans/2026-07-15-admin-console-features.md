# Admin & Console 功能补全实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 补全 Admin（平台后台）和 Console（租户后台）缺失功能，实现完整的多租户管理能力

**Architecture:** 基于现有模块化架构，每个功能对应一个框架模块。Admin 路由在 `Routes/admin.php`，Console 路由在 `Routes/tenant.php`。使用 RBAC 权限控制，ApiResponse 统一响应格式。

**Tech Stack:** Laravel 13, Sanctum, PHP 8.4

## Global Constraints

- 所有 Controller 使用 `AuthorizesTenantAccess` trait + `AuditService::log()`
- 所有路由使用 `rbac.permission:xxx.yyy` 中间件
- 响应格式统一使用 `ApiResponse` trait
- 测试使用 RbacModule schema 提供角色/权限表
- Admin 路由前缀：`v1/admin`
- Console 路由前缀：`v1/tenant`

---

## P0 — 核心业务（Sprint 1，第 1 周）

### Task 1: 运营人员管理前端页面（Admin）

**Covers:** 运营人员管理

**Files:**
- Create: `src/Modules/Operator/Http/Controllers/OperatorController.php`（已存在，补全方法）
- Modify: `src/Modules/Operator/Routes/admin.php`
- Create: `tests/Feature/Modules/Operator/OperatorAdminTest.php`

**Interfaces:**
- Consumes: `OperatorService::getOperators()`, `OperatorService::invite()`, `OperatorService::update()`
- Produces: Admin 运营人员列表、邀请、编辑、禁用 API

- [ ] **Step 1: 检查现有代码**

```bash
# 查看现有 Controller 方法
cat src/Modules/Operator/Http/Controllers/OperatorController.php | grep "function "

# 查看现有路由
cat src/Modules/Operator/Routes/admin.php
```

- [ ] **Step 2: 补全 Controller 方法**

```php
// src/Modules/Operator/Http/Controllers/OperatorController.php

public function index(Request $request): JsonResponse
{
    $request->validate([
        'per_page' => 'integer|min:1|max:100',
        'scope' => 'string|in:platform,tenant',
    ]);

    $operators = $this->operatorService->getOperators(
        $request->input('per_page', 15),
        $request->input('scope')
    );

    return $this->success($operators);
}

public function show(string $operatorId): JsonResponse
{
    $operator = $this->operatorService->getOperator($operatorId);

    if (!$operator) {
        return $this->notFound('Operator not found');
    }

    return $this->success($operator);
}

public function invite(Request $request): JsonResponse
{
    $request->validate([
        'email' => 'required|email',
        'name' => 'required|string|max:255',
        'scope' => 'required|string|in:platform,tenant',
    ]);

    $operator = $this->operatorService->invite($request->only('email', 'name', 'scope'));

    AuditService::log('operator.invite', ['operator_id' => $operator['id']]);

    return $this->created($operator);
}

public function update(Request $request, string $operatorId): JsonResponse
{
    $request->validate([
        'name' => 'string|max:255',
        'is_active' => 'boolean',
    ]);

    $operator = $this->operatorService->update($operatorId, $request->only('name', 'is_active'));

    AuditService::log('operator.update', ['operator_id' => $operatorId]);

    return $this->success($operator);
}
```

- [ ] **Step 3: 更新路由**

```php
// src/Modules/Operator/Routes/admin.php
Route::prefix('operators')->middleware(['auth:sanctum', 'rbac.permission:member.view'])->group(function () {
    Route::get('/', [OperatorController::class, 'index']);
    Route::get('/{operatorId}', [OperatorController::class, 'show']);
    Route::post('/invite', [OperatorController::class, 'invite'])->middleware('rbac.permission:member.create');
    Route::put('/{operatorId}', [OperatorController::class, 'update'])->middleware('rbac.permission:member.update');
    Route::delete('/{operatorId}', [OperatorController::class, 'destroy'])->middleware('rbac.permission:member.delete');
});
```

- [ ] **Step 4: 编写测试**

```php
// tests/Feature/Modules/Operator/OperatorAdminTest.php
public function test_can_list_operators(): void
{
    $this->actingAs($this->platformOperator)
        ->getJson('/v1/admin/operators')
        ->assertOk()
        ->assertJsonStructure(['data']);
}

public function test_can_invite_operator(): void
{
    $this->actingAs($this->platformOperator)
        ->postJson('/v1/admin/operators/invite', [
            'email' => 'new@example.com',
            'name' => 'New Operator',
            'scope' => 'platform',
        ])
        ->assertCreated();
}
```

- [ ] **Step 5: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/Operator/
```

- [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "feat(operator): complete admin operator management"
```

---

### Task 2: 订阅计划管理（Admin）

**Covers:** 订阅计划管理

**Files:**
- Modify: `src/Modules/Billing/Http/Controllers/SubscriptionController.php`
- Modify: `src/Modules/Billing/Routes/admin.php`
- Create: `tests/Feature/Modules/Billing/BillingAdminTest.php`

**Interfaces:**
- Consumes: `BillingService::getPlans()`, `BillingService::createPlan()`, `BillingService::updatePlan()`
- Produces: Admin 订阅计划 CRUD API

- [ ] **Step 1: 检查现有代码**

```bash
cat src/Modules/Billing/Http/Controllers/SubscriptionController.php | grep "function "
cat src/Modules/Billing/Routes/admin.php
```

- [ ] **Step 2: 补全 Controller**

```php
// src/Modules/Billing/Http/Controllers/SubscriptionController.php

public function plans(): JsonResponse
{
    $plans = $this->billingService->getPlans();
    return $this->success($plans);
}

public function storePlan(Request $request): JsonResponse
{
    $request->validate([
        'name' => 'required|string|max:255',
        'slug' => 'required|string|max:100|unique:subscription_plans,slug',
        'price' => 'required|numeric|min:0',
        'billing_cycle' => 'required|string|in:monthly,yearly',
        'features' => 'array',
        'is_active' => 'boolean',
    ]);

    $plan = $this->billingService->createPlan($request->all());

    AuditService::log('billing.plan.create', ['plan_id' => $plan['id']]);

    return $this->created($plan);
}

public function updatePlan(Request $request, string $planId): JsonResponse
{
    $request->validate([
        'name' => 'string|max:255',
        'price' => 'numeric|min:0',
        'features' => 'array',
        'is_active' => 'boolean',
    ]);

    $plan = $this->billingService->updatePlan($planId, $request->all());

    AuditService::log('billing.plan.update', ['plan_id' => $planId]);

    return $this->success($plan);
}

public function destroyPlan(string $planId): JsonResponse
{
    $this->billingService->deletePlan($planId);

    AuditService::log('billing.plan.delete', ['plan_id' => $planId]);

    return $this->noContent();
}
```

- [ ] **Step 3: 更新路由**

```php
// src/Modules/Billing/Routes/admin.php
Route::prefix('admin/billing')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/plans', [SubscriptionController::class, 'plans'])->middleware('rbac.permission:subscription.view');
    Route::post('/plans', [SubscriptionController::class, 'storePlan'])->middleware('rbac.permission:subscription.create');
    Route::put('/plans/{planId}', [SubscriptionController::class, 'updatePlan'])->middleware('rbac.permission:subscription.update');
    Route::delete('/plans/{planId}', [SubscriptionController::class, 'destroyPlan'])->middleware('rbac.permission:subscription.delete');
});
```

- [ ] **Step 4: 编写测试**

```php
// tests/Feature/Modules/Billing/BillingAdminTest.php
public function test_can_list_plans(): void
{
    $this->actingAs($this->platformOperator)
        ->getJson('/v1/admin/billing/plans')
        ->assertOk();
}

public function test_can_create_plan(): void
{
    $this->actingAs($this->platformOperator)
        ->postJson('/v1/admin/billing/plans', [
            'name' => 'Pro',
            'slug' => 'pro',
            'price' => 99.99,
            'billing_cycle' => 'monthly',
        ])
        ->assertCreated();
}
```

- [ ] **Step 5: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/Billing/
```

- [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "feat(billing): complete admin subscription plan management"
```

---

### Task 3: 角色权限管理（Admin）

**Covers:** 角色权限管理

**Files:**
- Modify: `src/Modules/Auth/Http/Controllers/RbacController.php`
- Modify: `src/Modules/Auth/Routes/admin.php`
- Create: `tests/Feature/Modules/Auth/RbacAdminTest.php`

**Interfaces:**
- Consumes: `RbacService::getRoles()`, `RbacService::createRole()`, `RbacService::updateRolePermissions()`
- Produces: Admin 角色 CRUD + 权限分配 API

- [ ] **Step 1: 检查现有代码**

```bash
cat src/Modules/Auth/Http/Controllers/RbacController.php | grep "function "
cat src/Modules/Auth/Routes/admin.php
```

- [ ] **Step 2: 补全 Controller**

```php
// src/Modules/Auth/Http/Controllers/RbacController.php

public function roles(): JsonResponse
{
    $roles = $this->rbacService->getRoles();
    return $this->success($roles);
}

public function storeRole(Request $request): JsonResponse
{
    $request->validate([
        'name' => 'required|string|max:100|unique:roles,name',
        'display_name' => 'required|string|max:255',
        'description' => 'string|max:500',
        'permissions' => 'array',
        'permissions.*' => 'integer|exists:permissions,permission_id',
    ]);

    $role = $this->rbacService->createRole($request->all());

    AuditService::log('rbac.role.create', ['role_id' => $role['id']]);

    return $this->created($role);
}

public function updateRolePermissions(Request $request, string $roleId): JsonResponse
{
    $request->validate([
        'permissions' => 'required|array',
        'permissions.*' => 'integer|exists:permissions,permission_id',
    ]);

    $this->rbacService->updateRolePermissions($roleId, $request->input('permissions'));

    AuditService::log('rbac.role.update_permissions', ['role_id' => $roleId]);

    return $this->success(['message' => 'Permissions updated']);
}
```

- [ ] **Step 3: 更新路由**

```php
// src/Modules/Auth/Routes/admin.php
Route::prefix('auth')->middleware(['auth:sanctum', 'rbac.permission:rbac.manage'])->group(function () {
    Route::get('/permissions', [RbacController::class, 'permissions']);
    Route::get('/roles', [RbacController::class, 'roles']);
    Route::post('/roles', [RbacController::class, 'storeRole']);
    Route::put('/roles/{roleId}', [RbacController::class, 'updateRole']);
    Route::put('/roles/{roleId}/permissions', [RbacController::class, 'updateRolePermissions']);
    Route::delete('/roles/{roleId}', [RbacController::class, 'destroyRole']);
});
```

- [ ] **Step 4: 编写测试**

```php
// tests/Feature/Modules/Auth/RbacAdminTest.php
public function test_can_list_roles(): void
{
    $this->actingAs($this->platformOperator)
        ->getJson('/v1/admin/auth/roles')
        ->assertOk();
}

public function test_can_create_role(): void
{
    $this->actingAs($this->platformOperator)
        ->postJson('/v1/admin/auth/roles', [
            'name' => 'editor',
            'display_name' => 'Editor',
            'permissions' => [1, 2, 3],
        ])
        ->assertCreated();
}
```

- [ ] **Step 5: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/Auth/
```

- [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "feat(auth): complete admin role and permission management"
```

---

## P1 — 运营必需（Sprint 2，第 2 周）

### Task 4: 模块管理（Admin）

**Covers:** 模块管理

**Files:**
- Modify: `src/Modules/Infrastructure/Http/Controllers/ModuleController.php`
- Modify: `src/Modules/Infrastructure/Routes/admin.php`
- Create: `tests/Feature/Modules/Infrastructure/ModuleAdminTest.php`

- [ ] **Step 1: 检查现有代码**

```bash
cat src/Modules/Infrastructure/Http/Controllers/ModuleController.php | grep "function "
```

- [ ] **Step 2: 补全 Controller**

```php
// src/Modules/Infrastructure/Http/Controllers/ModuleController.php

public function index(): JsonResponse
{
    $modules = $this->moduleRegistry->getAll();
    return $this->success($modules);
}

public function enable(string $name): JsonResponse
{
    $this->moduleRegistry->enable($name);

    AuditService::log('module.enable', ['module' => $name]);

    return $this->success(['message' => "Module {$name} enabled"]);
}

public function disable(string $name): JsonResponse
{
    $this->moduleRegistry->disable($name);

    AuditService::log('module.disable', ['module' => $name]);

    return $this->success(['message' => "Module {$name} disabled"]);
}
```

- [ ] **Step 3: 更新路由**

```php
// src/Modules/Infrastructure/Routes/admin.php
Route::prefix('admin/modules')->middleware(['auth:sanctum', 'rbac.permission:setting.view'])->group(function () {
    Route::get('/', [ModuleController::class, 'index']);
    Route::post('/{name}/enable', [ModuleController::class, 'enable'])->middleware('rbac.permission:setting.update');
    Route::post('/{name}/disable', [ModuleController::class, 'disable'])->middleware('rbac.permission:setting.update');
});
```

- [ ] **Step 4: 编写测试**

```php
// tests/Feature/Modules/Infrastructure/ModuleAdminTest.php
public function test_can_list_modules(): void
{
    $this->actingAs($this->platformOperator)
        ->getJson('/v1/admin/modules')
        ->assertOk();
}

public function test_can_enable_module(): void
{
    $this->actingAs($this->platformOperator)
        ->postJson('/v1/admin/modules/ai/enable')
        ->assertOk();
}
```

- [ ] **Step 5: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/Infrastructure/
```

- [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "feat(infrastructure): complete admin module management"
```

---

### Task 5: SSL 证书管理（Admin + Console）

**Covers:** SSL 证书管理

**Files:**
- Modify: `src/Modules/Ssl/Http/Controllers/TenantSslController.php`
- Modify: `src/Modules/Ssl/Routes/admin.php`
- Modify: `src/Modules/Ssl/Routes/tenant.php`
- Create: `tests/Feature/Modules/Ssl/SslAdminTest.php`

- [ ] **Step 1: 检查现有代码**

```bash
cat src/Modules/Ssl/Http/Controllers/TenantSslController.php | grep "function "
```

- [ ] **Step 2: 补全 Controller**

```php
// src/Modules/Ssl/Http/Controllers/TenantSslController.php

// Admin: 查看所有租户 SSL 证书
public function index(Request $request): JsonResponse
{
    $certificates = $this->sslService->getAllCertificates(
        $request->input('per_page', 15)
    );

    return $this->success($certificates);
}

// Admin/Console: 上传证书
public function store(Request $request, string $tenantId): JsonResponse
{
    $request->validate([
        'certificate' => 'required|string',
        'private_key' => 'required|string',
        'chain' => 'string',
    ]);

    $certificate = $this->sslService->uploadCertificate($tenantId, $request->all());

    AuditService::log('ssl.certificate.upload', ['tenant_id' => $tenantId]);

    return $this->created($certificate);
}

// Admin/Console: 删除证书
public function destroy(string $tenantId): JsonResponse
{
    $this->sslService->deleteCertificate($tenantId);

    AuditService::log('ssl.certificate.delete', ['tenant_id' => $tenantId]);

    return $this->noContent();
}

// Admin/Console: 续期证书
public function renew(Request $request, string $tenantId): JsonResponse
{
    $certificate = $this->sslService->renewCertificate($tenantId);

    AuditService::log('ssl.certificate.renew', ['tenant_id' => $tenantId]);

    return $this->success($certificate);
}
```

- [ ] **Step 3: 更新路由**

```php
// src/Modules/Ssl/Routes/admin.php
Route::prefix('admin/ssl')->middleware(['auth:sanctum', 'rbac.permission:ssl.manage'])->group(function () {
    Route::get('/', [TenantSslController::class, 'index']);
    Route::post('/{tenantId}', [TenantSslController::class, 'store']);
    Route::delete('/{tenantId}', [TenantSslController::class, 'destroy']);
    Route::post('/{tenantId}/renew', [TenantSslController::class, 'renew']);
});

// src/Modules/Ssl/Routes/tenant.php
Route::prefix('ssl')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [TenantSslController::class, 'tenantIndex']);
    Route::post('/', [TenantSslController::class, 'tenantStore']);
    Route::delete('/', [TenantSslController::class, 'tenantDestroy']);
});
```

- [ ] **Step 4: 编写测试**

```php
// tests/Feature/Modules/Ssl/SslAdminTest.php
public function test_can_list_certificates(): void
{
    $this->actingAs($this->platformOperator)
        ->getJson('/v1/admin/ssl')
        ->assertOk();
}
```

- [ ] **Step 5: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/Ssl/
```

- [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "feat(ssl): complete SSL certificate management for admin and console"
```

---

### Task 6: Webhooks 管理（Admin + Console）

**Covers:** Webhooks 管理

**Files:**
- Modify: `src/Modules/Infrastructure/Http/Controllers/WebhookController.php`
- Modify: `src/Modules/Infrastructure/Routes/admin.php`
- Modify: `src/Modules/Infrastructure/Routes/tenant.php`
- Create: `tests/Feature/Modules/Infrastructure/WebhookAdminTest.php`

- [ ] **Step 1: 检查现有代码**

```bash
cat src/Modules/Infrastructure/Http/Controllers/WebhookController.php | grep "function "
```

- [ ] **Step 2: 补全 Controller**

```php
// src/Modules/Infrastructure/Http/Controllers/WebhookController.php

public function index(Request $request): JsonResponse
{
    $webhooks = $this->webhookService->getAll(
        $request->input('tenant_id'),
        $request->input('per_page', 15)
    );

    return $this->success($webhooks);
}

public function store(Request $request): JsonResponse
{
    $request->validate([
        'url' => 'required|url',
        'events' => 'required|array',
        'events.*' => 'string',
        'secret' => 'string',
    ]);

    $webhook = $this->webhookService->create($request->all());

    AuditService::log('webhook.create', ['webhook_id' => $webhook['id']]);

    return $this->created($webhook);
}

public function destroy(string $webhookId): JsonResponse
{
    $this->webhookService->delete($webhookId);

    AuditService::log('webhook.delete', ['webhook_id' => $webhookId]);

    return $this->noContent();
}
```

- [ ] **Step 3: 更新路由**

```php
// src/Modules/Infrastructure/Routes/admin.php
Route::prefix('admin/webhooks')->middleware(['auth:sanctum', 'rbac.permission:webhook.view'])->group(function () {
    Route::get('/', [WebhookController::class, 'index']);
    Route::post('/', [WebhookController::class, 'store'])->middleware('rbac.permission:webhook.create');
    Route::delete('/{webhookId}', [WebhookController::class, 'destroy'])->middleware('rbac.permission:webhook.delete');
});

// src/Modules/Infrastructure/Routes/tenant.php
Route::prefix('webhooks')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [WebhookController::class, 'tenantIndex']);
    Route::post('/', [WebhookController::class, 'tenantStore']);
    Route::delete('/{webhookId}', [WebhookController::class, 'tenantDestroy']);
});
```

- [ ] **Step 4: 编写测试**

```php
// tests/Feature/Modules/Infrastructure/WebhookAdminTest.php
public function test_can_list_webhooks(): void
{
    $this->actingAs($this->platformOperator)
        ->getJson('/v1/admin/webhooks')
        ->assertOk();
}
```

- [ ] **Step 5: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/Infrastructure/
```

- [ ] **Step 6: 提交**

```bash
git add -A && git commit -m "feat(infrastructure): complete webhook management for admin and console"
```

---

## P2 — 增强功能（Sprint 3-4，第 3-4 周）

### Task 7: 工作流管理（Console）

**Covers:** 工作流管理

**Files:**
- Create: `src/Modules/Workflow/Http/Controllers/WorkflowController.php`
- Create: `src/Modules/Workflow/Routes/tenant.php`
- Create: `tests/Feature/Modules/Workflow/WorkflowConsoleTest.php`

- [ ] **Step 1: 创建 Controller**

```php
// src/Modules/Workflow/Http/Controllers/WorkflowController.php
namespace MultiTenantSaas\Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowService;
use MultiTenantSaas\Services\AuditService;

class WorkflowController extends Controller
{
    use ApiResponse, AuthorizesTenantAccess;

    public function __construct(
        private WorkflowService $workflowService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workflows = $this->workflowService->getAll(
            $request->input('per_page', 15)
        );

        return $this->success($workflows);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'string|max:500',
            'steps' => 'required|array',
            'steps.*.type' => 'required|string',
            'steps.*.config' => 'required|array',
        ]);

        $workflow = $this->workflowService->create($request->all());

        AuditService::log('workflow.create', ['workflow_id' => $workflow['id']]);

        return $this->created($workflow);
    }

    public function update(Request $request, string $workflowId): JsonResponse
    {
        $request->validate([
            'name' => 'string|max:255',
            'description' => 'string|max:500',
            'steps' => 'array',
        ]);

        $workflow = $this->workflowService->update($workflowId, $request->all());

        AuditService::log('workflow.update', ['workflow_id' => $workflowId]);

        return $this->success($workflow);
    }

    public function destroy(string $workflowId): JsonResponse
    {
        $this->workflowService->delete($workflowId);

        AuditService::log('workflow.delete', ['workflow_id' => $workflowId]);

        return $this->noContent();
    }

    public function execute(Request $request, string $workflowId): JsonResponse
{
        $execution = $this->workflowService->execute($workflowId, $request->all());

        AuditService::log('workflow.execute', ['workflow_id' => $workflowId]);

        return $this->success($execution);
    }
}
```

- [ ] **Step 2: 创建路由**

```php
// src/Modules/Workflow/Routes/tenant.php
Route::prefix('workflows')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [WorkflowController::class, 'index']);
    Route::post('/', [WorkflowController::class, 'store']);
    Route::put('/{workflowId}', [WorkflowController::class, 'update']);
    Route::delete('/{workflowId}', [WorkflowController::class, 'destroy']);
    Route::post('/{workflowId}/execute', [WorkflowController::class, 'execute']);
});
```

- [ ] **Step 3: 编写测试**

```php
// tests/Feature/Modules/Workflow/WorkflowConsoleTest.php
public function test_can_list_workflows(): void
{
    $this->actingAs($this->tenantAdmin)
        ->getJson('/v1/tenant/workflows')
        ->assertOk();
}
```

- [ ] **Step 4: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/Workflow/
```

- [ ] **Step 5: 提交**

```bash
git add -A && git commit -m "feat(workflow): add workflow management for console"
```

---

### Task 8: 插件管理（Admin）

**Covers:** 插件管理

**Files:**
- Create: `src/Modules/Plugin/Http/Controllers/PluginController.php`
- Modify: `src/Modules/Plugin/Routes/admin.php`
- Create: `tests/Feature/Modules/Plugin/PluginAdminTest.php`

- [ ] **Step 1: 创建 Controller**

```php
// src/Modules/Plugin/Http/Controllers/PluginController.php
namespace MultiTenantSaas\Modules\Plugin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Plugin\Services\PluginService;
use MultiTenantSaas\Services\AuditService;

class PluginController extends Controller
{
    use ApiResponse, AuthorizesTenantAccess;

    public function __construct(
        private PluginService $pluginService
    ) {}

    public function index(): JsonResponse
    {
        $plugins = $this->pluginService->getAll();
        return $this->success($plugins);
    }

    public function install(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string',
            'version' => 'string',
        ]);

        $plugin = $this->pluginService->install($request->input('name'), $request->input('version'));

        AuditService::log('plugin.install', ['plugin' => $request->input('name')]);

        return $this->created($plugin);
    }

    public function uninstall(string $name): JsonResponse
    {
        $this->pluginService->uninstall($name);

        AuditService::log('plugin.uninstall', ['plugin' => $name]);

        return $this->noContent();
    }

    public function enable(string $name): JsonResponse
    {
        $this->pluginService->enable($name);

        AuditService::log('plugin.enable', ['plugin' => $name]);

        return $this->success(['message' => "Plugin {$name} enabled"]);
    }

    public function disable(string $name): JsonResponse
    {
        $this->pluginService->disable($name);

        AuditService::log('plugin.disable', ['plugin' => $name]);

        return $this->success(['message' => "Plugin {$name} disabled"]);
    }
}
```

- [ ] **Step 2: 更新路由**

```php
// src/Modules/Plugin/Routes/admin.php
Route::prefix('admin/plugins')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [PluginController::class, 'index']);
    Route::post('/install', [PluginController::class, 'install']);
    Route::delete('/{name}', [PluginController::class, 'uninstall']);
    Route::post('/{name}/enable', [PluginController::class, 'enable']);
    Route::post('/{name}/disable', [PluginController::class, 'disable']);
});
```

- [ ] **Step 3: 编写测试**

```php
// tests/Feature/Modules/Plugin/PluginAdminTest.php
public function test_can_list_plugins(): void
{
    $this->actingAs($this->platformOperator)
        ->getJson('/v1/admin/plugins')
        ->assertOk();
}
```

- [ ] **Step 4: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/Plugin/
```

- [ ] **Step 5: 提交**

```bash
git add -A && git commit -m "feat(plugin): add plugin management for admin"
```

---

### Task 9: 开发者门户（Admin）

**Covers:** 开发者门户

**Files:**
- Create: `src/Modules/DeveloperPortal/Http/Controllers/DeveloperPortalController.php`
- Modify: `src/Modules/DeveloperPortal/Routes/admin.php`
- Create: `tests/Feature/Modules/DeveloperPortal/DeveloperPortalAdminTest.php`

- [ ] **Step 1: 创建 Controller**

```php
// src/Modules/DeveloperPortal/Http/Controllers/DeveloperPortalController.php
namespace MultiTenantSaas\Modules\DeveloperPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\DeveloperPortal\Services\ApiDocService;
use MultiTenantSaas\Modules\DeveloperPortal\Services\SandboxService;

class DeveloperPortalController extends Controller
{
    use ApiResponse, AuthorizesTenantAccess;

    public function __construct(
        private ApiDocService $apiDocService,
        private SandboxService $sandboxService
    ) {}

    public function index(): JsonResponse
    {
        return $this->success([
            'endpoints' => $this->apiDocService->getEndpoints(),
            'sdks' => $this->apiDocService->getSdks(),
        ]);
    }

    public function sandbox(Request $request): JsonResponse
    {
        $request->validate([
            'method' => 'required|string|in:GET,POST,PUT,DELETE',
            'path' => 'required|string',
            'body' => 'array',
        ]);

        $result = $this->sandboxService->execute(
            $request->input('method'),
            $request->input('path'),
            $request->input('body', [])
        );

        return $this->success($result);
    }
}
```

- [ ] **Step 2: 更新路由**

```php
// src/Modules/DeveloperPortal/Routes/admin.php
Route::prefix('admin/developer-portal')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/', [DeveloperPortalController::class, 'index']);
    Route::post('/sandbox', [DeveloperPortalController::class, 'sandbox']);
});
```

- [ ] **Step 3: 编写测试**

```php
// tests/Feature/Modules/DeveloperPortal/DeveloperPortalAdminTest.php
public function test_can_access_developer_portal(): void
{
    $this->actingAs($this->platformOperator)
        ->getJson('/v1/admin/developer-portal')
        ->assertOk();
}
```

- [ ] **Step 4: 运行测试**

```bash
php vendor/bin/phpunit tests/Feature/Modules/DeveloperPortal/
```

- [ ] **Step 5: 提交**

```bash
git add -A && git commit -m "feat(developer-portal): add developer portal for admin"
```

---

## 附录：权限清单

| 权限 | 说明 | 用于 |
|------|------|------|
| member.view | 查看运营人员 | Task 1 |
| member.create | 邀请运营人员 | Task 1 |
| member.update | 编辑运营人员 | Task 1 |
| member.delete | 删除运营人员 | Task 1 |
| subscription.view | 查看订阅计划 | Task 2 |
| subscription.create | 创建订阅计划 | Task 2 |
| subscription.update | 编辑订阅计划 | Task 2 |
| subscription.delete | 删除订阅计划 | Task 2 |
| rbac.manage | 管理角色权限 | Task 3 |
| setting.view | 查看系统设置 | Task 4 |
| setting.update | 更新系统设置 | Task 4 |
| ssl.manage | 管理 SSL 证书 | Task 5 |
| webhook.view | 查看 Webhooks | Task 6 |
| webhook.create | 创建 Webhooks | Task 6 |
| webhook.delete | 删除 Webhooks | Task 6 |

## 附录：执行顺序

```
Sprint 1 (P0)
├── Task 1: 运营人员管理
├── Task 2: 订阅计划管理
└── Task 3: 角色权限管理

Sprint 2 (P1)
├── Task 4: 模块管理
├── Task 5: SSL 证书管理
└── Task 6: Webhooks 管理

Sprint 3-4 (P2)
├── Task 7: 工作流管理
├── Task 8: 插件管理
└── Task 9: 开发者门户
```
