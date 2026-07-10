# 中间件 API

**最后更新**: 2026-06-18

---

## IdentifyDomain

域名识别中间件，识别当前请求的域名类型。

### 方法

#### handle(Request $request, Closure $next)

处理请求，识别域名类型并存储到 TenantContext。

```php
// 在中间件栈中使用
$middleware->prepend([
    \MultiTenantSaas\Middleware\IdentifyDomain::class,
]);
```

#### identifyDomainType(string $host, string $path = '/')

识别域名类型。

```php
$domainType = $this->identifyDomainType('ai.lyt.com', '/console');
// 返回: 'console'
```

**识别逻辑**：

1. 测试环境 localhost → 路径区分
2. Admin域名精确匹配 → 'admin'
3. 路径 `/console` → 'console'
4. 路径 `/api` → 'api'
5. 其他 → 'app'

#### getCurrentDomainType(Request $request)

获取当前域名类型（静态方法）。

```php
$domainType = IdentifyDomain::getCurrentDomainType($request);
// 返回: string
```

#### isAdminDomain(Request $request)

判断是否为管理后台域名（静态方法）。

```php
$isAdmin = IdentifyDomain::isAdminDomain($request);
// 返回: bool
```

---

## IdentifyTenant

租户识别中间件，按优先级从多个来源解析租户 ID。

### 方法

#### handle(Request $request, Closure $next)

处理请求，识别租户并存储到 TenantContext。

```php
// 在中间件栈中使用
$middleware->web(prepend: [
    \MultiTenantSaas\Middleware\IdentifyTenant::class,
]);
```

#### resolveTenantId(Request $request)

按优先级解析租户 ID。

```php
$tenantId = $this->resolveTenantId($request);
// 返回: string|null
```

**识别优先级**：

1. URL 参数 `?tenant_id=` 或 `?tid=`
2. Header `X-Tenant-ID`
3. 自定义域名（X-Original-Host → 数据库查询）
4. Cookie `tenant_id`
5. Session `tenant_id`
6. 认证用户的 `current_tenant_id`
7. 默认租户 `config('tenancy.default_tenant_id')`

#### resolveFromCustomDomain(Request $request)

从自定义域名识别租户。

```php
$tenantId = $this->resolveFromCustomDomain($request);
// 返回: string|null
```

#### loadTenant(int $tenantId)

加载租户（带缓存）。

```php
$tenant = $this->loadTenant(1234567890123456);
// 返回: Tenant|null
```

#### getCurrentTenantId(Request $request)

获取当前租户 ID（静态方法）。

```php
$tenantId = IdentifyTenant::getCurrentTenantId($request);
// 返回: string|null
```

#### getCurrentTenant(Request $request)

获取当前租户对象（静态方法）。

```php
$tenant = IdentifyTenant::getCurrentTenant($request);
// 返回: Tenant|null
```

#### hasTenant(Request $request)

检查是否已识别租户（静态方法）。

```php
$hasTenant = IdentifyTenant::hasTenant($request);
// 返回: bool
```

---

## CheckPermission

权限控制中间件，根据域名类型和用户角色进行权限控制。

### 方法

#### handle(Request $request, Closure $next, ?string $role = null)

处理请求，检查权限。

```php
// 在路由中使用
Route::middleware(['tenant.permission'])->group(function () {
    // 需要权限检查的路由
});

// 指定角色
Route::middleware(['tenant.permission:tenant_admin'])->group(function () {
    // 仅 tenant_admin 可访问
});
```

#### checkAdminAccess(Request $request, $user, Closure $next, ?string $role)

检查管理后台访问权限。

```php
// 仅 super_admin 可访问
if ($user->role !== self::ROLE_SUPER_ADMIN) {
    return $this->forbidden($request, '仅超级管理员可以访问');
}
```

#### checkConsoleAccess(Request $request, $user, Closure $next, ?string $role)

检查租户后台访问权限。

```php
// 仅 tenant_admin 可访问
if ($tenantRole !== self::ROLE_TENANT_ADMIN) {
    return $this->forbidden($request, '仅租户管理员可以访问管理后台');
}
```

#### checkTenantAccess(Request $request, $user, Closure $next, ?string $role)

检查租户访问权限。

```php
// tenant_admin 和 end_user 都可访问
$tenantRole = $tenantUser->pivot->role;
TenantContext::setTenantRole($tenantRole);

// 检查指定角色
if ($role && $tenantRole !== $role) {
    return $this->forbidden($request, "需要 {$role} 角色权限");
}
```

---

## EnsureTenantContext

租户上下文验证中间件，确保在需要租户上下文的请求中租户信息有效。

### 方法

#### handle(Request $request, Closure $next)

处理请求，验证租户上下文。

```php
// 在路由中使用
Route::middleware(['tenant.ensure'])->group(function () {
    // 需要租户上下文的路由
});
```

**检查内容**：

1. Admin 域名跳过检查
2. 租户 ID 是否存在
3. 租户对象是否存在
4. 租户状态是否为 active

---

## 中间件配置

### bootstrap/app.php

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        // 1. 域名识别
        $middleware->prepend([
            \MultiTenantSaas\Middleware\IdentifyDomain::class,
        ]);
        
        // 2. 租户识别
        $middleware->web(prepend: [
            \MultiTenantSaas\Middleware\IdentifyTenant::class,
        ]);
        
        $middleware->api(prepend: [
            \MultiTenantSaas\Middleware\IdentifyTenant::class,
        ]);
        
        // 3. 中间件别名
        $middleware->alias([
            'tenant.ensure' => \MultiTenantSaas\Middleware\EnsureTenantContext::class,
            'tenant.permission' => \MultiTenantSaas\Middleware\CheckPermission::class,
        ]);
    })
    ->create();
```

---

## 使用示例

### 路由中间件

```php
// 系统后台路由
Route::prefix('admin')->group(function () {
    // admin 域名自动识别
});

// 租户后台路由
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    // tenant.ensure 确保租户上下文有效
});

// 需要特定角色的路由
Route::middleware(['tenant.permission:tenant_admin'])->group(function () {
    // 仅 tenant_admin 可访问
});

// 用户前台路由
Route::middleware(['auth'])->group(function () {
    // 需要认证
});
```

### 控制器中使用

```php
class OrderController extends Controller
{
    public function index(Request $request)
    {
        // 获取当前租户
        $tenantId = TenantContext::getId();
        $tenant = TenantContext::getTenant();
        
        // 获取域名类型
        $domainType = TenantContext::getDomainType();
        
        // 获取用户角色
        $tenantRole = TenantContext::getTenantRole();
        
        // 查询数据（自动按租户过滤）
        $orders = Order::all();
        
        return response()->json([
            'tenant_id' => $tenantId,
            'domain_type' => $domainType,
            'tenant_role' => $tenantRole,
            'orders' => $orders,
        ]);
    }
}
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
