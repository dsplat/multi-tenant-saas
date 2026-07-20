# 权限控制指南

**最后更新**: 2026-07-20

---

## 双账户体系

系统存在两种独立身份：

| 维度 | Operator（运营者） | User（终端用户） |
|------|-------------------|-----------------|
| 数据表 | `operators` | `users` |
| 含义 | 平台管理员 / 租户管理员 / 团队成员 | 租户的终端业务用户 |
| 关联租户 | `operator_tenants`（N:N，含 role_id） | `tenant_users`（N:N，含 role_id） |
| Token | Sanctum `tokenable_type=Operator::class` | Sanctum `tokenable_type=User::class` |
| 权限模型 | RBAC（`operator_tenants.role_id` → `roles` → `permissions`） | RBAC（`tenant_users.role_id` → `roles` → `permissions`） |

---

## 角色体系

### 平台级角色（operator_tenants.role）

| 角色 | 说明 | 访问权限 |
|------|------|----------|
| `super_admin` | 超级管理员 | 系统后台 + 所有租户后台 |
| `tenant_admin` | 租户管理员 | 租户后台 |
| `member` | 普通成员 | 租户后台（受限） |

### 租户内角色（tenant_users.role）

| 角色 | 说明 | 访问权限 |
|------|------|----------|
| `tenant_admin` | 租户管理员 | 租户后台 + 用户前台 |
| `end_user` | 终端用户 | 用户前台 |

---

## 权限矩阵

| 域名类型 | super_admin | tenant_admin | end_user |
|----------|-------------|--------------|----------|
| admin | ✅ | ❌ | ❌ |
| console | ✅ | ✅ | ❌ |
| app | ✅ | ✅ | ✅ |
| guest | ✅ | ✅ | ✅ |

---

## 中间件配置

### CheckPermission 中间件

```php
class CheckPermission
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $domainType = TenantContext::getDomainType();
        
        return match ($domainType) {
            'admin' => $this->checkAdminAccess($request, $user, $next, $role),
            'console' => $this->checkConsoleAccess($request, $user, $next, $role),
            'api', 'app' => $this->checkTenantAccess($request, $user, $next, $role),
            default => $next($request),
        };
    }
}
```

### 权限检查逻辑

#### 系统后台（admin）

```php
protected function checkAdminAccess(Request $request, $user, Closure $next, ?string $role): Response
{
    if ($user->role !== self::ROLE_SUPER_ADMIN) {
        return $this->forbidden($request, '仅超级管理员可以访问');
    }
    
    return $next($request);
}
```

#### 租户后台（console）

```php
protected function checkConsoleAccess(Request $request, $user, Closure $next, ?string $role): Response
{
    $tenantId = TenantContext::getId();
    
    if (!$tenantId) {
        return $this->forbidden($request, '缺少租户信息');
    }
    
    // super_admin 可以访问所有租户后台
    if ($user->role === self::ROLE_SUPER_ADMIN) {
        TenantContext::setTenantRole(self::ROLE_SUPER_ADMIN);
        return $next($request);
    }
    
    // 检查用户是否属于该租户
    $tenantUser = $user->tenants()
        ->where('tenants.tenant_id', $tenantId)
        ->wherePivot('is_active', true)
        ->first();
    
    if (!$tenantUser) {
        return $this->forbidden($request, '您不属于该租户');
    }
    
    $tenantRole = $tenantUser->pivot->role;
    
    // console 仅允许 tenant_admin
    if ($tenantRole !== self::ROLE_TENANT_ADMIN) {
        return $this->forbidden($request, '仅租户管理员可以访问管理后台');
    }
    
    TenantContext::setTenantRole($tenantRole);
    
    return $next($request);
}
```

#### 用户前台（app）

```php
protected function checkTenantAccess(Request $request, $user, Closure $next, ?string $role): Response
{
    $tenantId = TenantContext::getId();
    
    if (!$tenantId) {
        return $this->forbidden($request, '缺少租户信息');
    }
    
    // super_admin 可以访问所有租户
    if ($user->role === self::ROLE_SUPER_ADMIN) {
        TenantContext::setTenantRole(self::ROLE_SUPER_ADMIN);
        return $next($request);
    }
    
    // 检查用户是否属于该租户
    $tenantUser = $user->tenants()
        ->where('tenants.tenant_id', $tenantId)
        ->wherePivot('is_active', true)
        ->first();
    
    if (!$tenantUser) {
        return $this->forbidden($request, '您不属于该租户');
    }
    
    $tenantRole = $tenantUser->pivot->role;
    TenantContext::setTenantRole($tenantRole);
    
    // 检查指定角色
    if ($role && $tenantRole !== $role) {
        return $this->forbidden($request, "需要 {$role} 角色权限");
    }
    
    return $next($request);
}
```

---

## 路由配置

### 系统后台路由

```php
Route::prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard']);
    Route::get('/tenants', [AdminController::class, 'tenants']);
    Route::get('/settings', [AdminController::class, 'settings']);
});
```

### 租户后台路由

```php
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    Route::get('/', [ConsoleController::class, 'dashboard']);
    Route::get('/members', [ConsoleController::class, 'members']);
    Route::get('/settings', [ConsoleController::class, 'settings']);
});
```

### 用户前台路由

```php
Route::middleware(['auth'])->group(function () {
    Route::get('/', [AppController::class, 'index']);
    Route::get('/dashboard', [AppController::class, 'dashboard']);
    Route::get('/profile', [AppController::class, 'profile']);
});
```

---

## 典型账号状态

### 超级管理员

```
users.role = 'super_admin'
tenant_users.role = 'tenant_admin'（平台默认租户）
```

**访问权限**：
- 系统后台 ✅
- 所有租户后台 ✅
- 所有用户前台 ✅

### 企业管理员

```
users.role = 'platform_user'
tenant_users.role = 'tenant_admin'（企业租户）
```

**访问权限**：
- 系统后台 ❌
- 自己租户的后台 ✅
- 自己租户的用户前台 ✅

### 企业用户

```
users.role = 'platform_user'
tenant_users.role = 'end_user'（企业租户）
```

**访问权限**：
- 系统后台 ❌
- 租户后台 ❌
- 自己租户的用户前台 ✅

---

## 测试权限

### 测试系统后台访问

```bash
# 应该成功（super_admin）
curl -H "X-Original-Host: admin.lyt.com" \
     -H "Authorization: Bearer {super_admin_token}" \
     http://admin.lyt.com/admin

# 应该失败（非 super_admin）
curl -H "X-Original-Host: admin.lyt.com" \
     -H "Authorization: Bearer {user_token}" \
     http://admin.lyt.com/admin
```

### 测试租户后台访问

```bash
# 应该成功（tenant_admin）
curl -H "X-Original-Host: ai.tenant1.local" \
     -H "Authorization: Bearer {tenant_admin_token}" \
     http://ai.tenant1.local/console

# 应该失败（end_user）
curl -H "X-Original-Host: ai.tenant1.local" \
     -H "Authorization: Bearer {end_user_token}" \
     http://ai.tenant1.local/console
```

### 测试用户前台访问

```bash
# 应该成功（tenant_admin 或 end_user）
curl -H "X-Original-Host: ai.tenant1.local" \
     -H "Authorization: Bearer {user_token}" \
     http://ai.tenant1.local
```

---

## 常见问题

### Q: 如何让用户同时属于多个租户？

A: 当前设计中，一个用户只属于一个租户。如果需要多租户支持，可以：
1. 创建多个用户账号
2. 修改 `tenant_users` 表，移除唯一约束

### Q: 如何自定义角色？

A: 可以扩展 `tenant_users.role` 字段，添加自定义角色：

```php
// 在 CheckPermission 中间件中添加
const ROLE_CUSTOM = 'custom_role';

protected function checkConsoleAccess(...)
{
    // ...
    $allowedRoles = [self::ROLE_TENANT_ADMIN, self::ROLE_CUSTOM];
    if (!in_array($tenantRole, $allowedRoles)) {
        return $this->forbidden($request, '权限不足');
    }
}
```

### Q: 如何实现更细粒度的权限控制？

A: 可以使用 Laravel 的 Gate 或 Policy：

```php
// 定义 Gate
Gate::define('manage-members', function ($user, $tenant) {
    return $user->tenants()
        ->where('tenants.tenant_id', $tenant->tenant_id)
        ->wherePivot('role', 'tenant_admin')
        ->exists();
});

// 在控制器中使用
$this->authorize('manage-members', $tenant);
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
