# 四重访问架构

**最后更新**: 2026-06-18

---

## 架构概览

四重访问架构将系统分为四个独立的访问层级，每个层级有不同的访问权限和用途：

```
┌─────────────────────────────────────────────────────────────┐
│                    四重访问架构                               │
├─────────────────────────────────────────────────────────────┤
│  层级 1: 系统后台 (Admin)                                    │
│  ├─ 域名: admin.lyt.com                                     │
│  ├─ 权限: super_admin only                                  │
│  └─ 功能: 系统配置、租户管理、全局监控                        │
├─────────────────────────────────────────────────────────────┤
│  层级 2: 租户后台 (Console)                                  │
│  ├─ 域名: ai.lyt.com/console/* 或 ai-admin.tenant1.local    │
│  ├─ 权限: tenant_admin only                                 │
│  └─ 功能: 租户配置、成员管理、数据统计                        │
├─────────────────────────────────────────────────────────────┤
│  层级 3: 用户前台 (App)                                      │
│  ├─ 域名: ai.lyt.com 或 ai.tenant1.local                    │
│  ├─ 权限: end_user + tenant_admin                           │
│  └─ 功能: 业务功能、数据操作                                  │
├─────────────────────────────────────────────────────────────┤
│  层级 4: 访客 (Guest)                                       │
│  ├─ 域名: 同用户前台                                         │
│  ├─ 权限: 未登录用户                                         │
│  └─ 功能: 公开页面、登录注册                                  │
└─────────────────────────────────────────────────────────────┘
```

---

## 层级详解

### 1. 系统后台 (Admin)

**域名**: `admin.lyt.com` (独立域名)

**访问权限**: 仅 `super_admin` 角色

**功能范围**:
- 系统配置管理
- 租户管理（创建、编辑、删除）
- 全局监控和统计
- 系统日志查看

**路由示例**:
```php
Route::prefix('admin')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard']);
    Route::get('/tenants', [AdminController::class, 'tenants']);
    Route::get('/settings', [AdminController::class, 'settings']);
});
```

**安全建议**:
- 使用独立域名，避免暴力破解
- 限制 IP 访问（可选）
- 启用两步验证（推荐）

---

### 2. 租户后台 (Console)

**域名**: 
- 单域名模式: `ai.lyt.com/console/*`
- 多域名模式: `ai-admin.tenant1.local` 或 `ai.tenant1.local/console/*`

**访问权限**: 仅 `tenant_admin` 角色

**功能范围**:
- 租户配置管理
- 成员管理
- 数据统计和分析
- 租户级系统设置

**路由示例**:
```php
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    Route::get('/', [ConsoleController::class, 'dashboard']);
    Route::get('/members', [ConsoleController::class, 'members']);
    Route::get('/settings', [ConsoleController::class, 'settings']);
});
```

**权限检查**:
```php
// CheckPermission 中间件
protected function checkConsoleAccess(Request $request, $user, Closure $next, ?string $role): Response
{
    // super_admin 可以访问所有租户后台
    if ($user->role === self::ROLE_SUPER_ADMIN) {
        return $next($request);
    }
    
    // 检查用户是否属于该租户
    $tenantUser = $user->tenants()
        ->where('tenants.tenant_id', $tenantId)
        ->wherePivot('is_active', true)
        ->first();
    
    // console 仅允许 tenant_admin
    if ($tenantUser->pivot->role !== self::ROLE_TENANT_ADMIN) {
        return $this->forbidden($request, '仅租户管理员可以访问管理后台');
    }
    
    return $next($request);
}
```

---

### 3. 用户前台 (App)

**域名**: 
- 平台租户: `ai.lyt.com`
- 企业租户: `ai.tenant1.local`

**访问权限**: `end_user` + `tenant_admin` 角色

**功能范围**:
- 业务功能使用
- 数据查看和操作
- 个人设置

**路由示例**:
```php
Route::middleware(['auth'])->group(function () {
    Route::get('/', [AppController::class, 'index']);
    Route::get('/dashboard', [AppController::class, 'dashboard']);
    Route::get('/profile', [AppController::class, 'profile']);
});
```

---

### 4. 访客 (Guest)

**域名**: 同用户前台

**访问权限**: 未登录用户

**功能范围**:
- 公开页面查看
- 登录/注册
- 忘记密码

**路由示例**:
```php
// 不需要认证的路由
Route::get('/', [GuestController::class, 'index']);
Route::get('/login', [GuestController::class, 'login']);
Route::post('/login', [GuestController::class, 'doLogin']);
Route::get('/register', [GuestController::class, 'register']);
```

---

## 域名配置

### 单域名模式

所有功能在同一域名下，通过路径区分：

```
ai.lyt.com/              → 用户前台/访客
ai.lyt.com/console/*     → 租户后台
ai.lyt.com/api/*         → API接口
```

**配置**:
```nginx
server {
    listen 80;
    server_name ai.lyt.com;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ^~ /console {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
    }
}
```

### 多域名模式

租户使用独立域名：

```
ai.tenant1.local/           → 用户前台/访客
ai.tenant1.local/console/*  → 租户后台
ai-admin.tenant1.local/     → 租户后台（子域名方案）
```

**配置**:
```nginx
# 企业租户域名
server {
    listen 80;
    server_name ai.tenant1.local;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ^~ /console {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
    }
}

# 子域名方案
server {
    listen 80;
    server_name ai-admin.tenant1.local;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

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
        
        // 3. 中间件别名
        $middleware->alias([
            'tenant.ensure' => \MultiTenantSaas\Middleware\EnsureTenantContext::class,
            'tenant.permission' => \MultiTenantSaas\Middleware\CheckPermission::class,
        ]);
    })
    ->create();
```

### 路由中间件

```php
// 系统后台路由
Route::prefix('admin')->group(function () {
    // admin 域名自动识别，无需额外中间件
});

// 租户后台路由
Route::middleware(['tenant.ensure'])->prefix('console')->group(function () {
    // tenant.ensure 确保租户上下文有效
});

// 用户前台路由
Route::middleware(['auth'])->group(function () {
    // 需要认证
});
```

---

## 测试验证

### 测试系统后台

```bash
# 应该成功
curl -H "X-Original-Host: admin.lyt.com" http://admin.lyt.com/admin

# 应该失败（非 admin 域名）
curl -H "X-Original-Host: ai.lyt.com" http://ai.lyt.com/admin
```

### 测试租户后台

```bash
# 应该成功（tenant_admin）
curl -H "X-Original-Host: ai.tenant1.local" http://ai.tenant1.local/console

# 应该失败（end_user）
curl -H "X-Original-Host: ai.tenant1.local" -H "X-Tenant-ID: 2432992121034120" http://ai.tenant1.local/console
```

### 测试用户前台

```bash
# 应该成功
curl -H "X-Original-Host: ai.tenant1.local" http://ai.tenant1.local
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
