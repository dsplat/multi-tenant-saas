# 租户隔离架构

**最后更新**: 2026-06-18

---

## 设计原则

**核心思想**：租户隔离的条件是**租户 ID**，而不是域名类型。

- Nginx 只做 HTTP 路由
- 所有业务识别（域名类型、租户 ID）均在 Laravel 中间件层处理
- 数据隔离通过全局作用域实现，对业务代码透明

---

## 租户识别

### IdentifyTenant 中间件

**职责**：按优先级从多个来源解析租户 ID，验证后注入请求属性和全局上下文。

**识别优先级**：

| 优先级 | 来源 | 说明 |
|--------|------|------|
| 1（最高） | URL 参数 `?tenant_id=` / `?tid=` | API 调试、显式指定 |
| 2 | Header `X-Tenant-ID` | 标准 API 调用 |
| 3 | 自定义域名 DB 查询 | 企业域名自动识别 |
| 4 | Cookie `tenant_id` | Web 会话 |
| 5 | Session `tenant_id` | 登录后持久化 |
| 6（兜底） | `config('tenancy.default_tenant_id')` | 公共平台默认租户 |

**自定义域名识别**：

```php
protected function resolveFromCustomDomain(Request $request): ?string
{
    // 从 X-Original-Host 获取原始域名（Nginx 传入）
    $host = $request->header('X-Original-Host') ?? $request->getHost();
    
    // 排除平台域名
    $platformDomains = config('tenancy.platform_domains', []);
    if (in_array($host, $platformDomains)) {
        return null;
    }
    
    // 从数据库查询自定义域名对应的租户
    return Tenant::where('custom_domain', $host)
        ->where('status', 'active')
        ->value('tenant_id');
}
```

**识别成功后**：

```php
// 注入请求属性
$request->attributes->set('tenant_id', $tenantId);
$request->attributes->set('tenant', $tenant);

// Web 请求自动保存到 session
$request->session()->put('tenant_id', $tenantId);

// 为 TenantScope 设置上下文
TenantContext::setId($tenantId);
TenantContext::setTenant($tenant);
```

---

## 数据隔离

### TenantScope 全局作用域

**职责**：自动为所有查询追加 `WHERE tenant_id = ?`，实现透明的数据隔离。

**实现**：

```php
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::getId();
        
        if ($tenantId) {
            $builder->where($model->getTable().'.tenant_id', $tenantId);
        }
    }
}
```

**启用方式**：

```php
class Order extends Model
{
    use BelongsToTenant;
    
    // 或手动添加
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
```

**效果**：

```php
// 自动添加租户过滤
Order::all();
// SQL: SELECT * FROM orders WHERE tenant_id = 123456

Order::where('status', 'active')->get();
// SQL: SELECT * FROM orders WHERE tenant_id = 123456 AND status = 'active'

// 跨租户查询（Super Admin 场景）
Order::withoutGlobalScope(TenantScope::class)->get();
// SQL: SELECT * FROM orders

// 指定租户查询
Order::withTenant('67890')->get();
// SQL: SELECT * FROM orders WHERE tenant_id = 67890
```

### BelongsToTenant Trait

**职责**：为模型自动应用租户作用域，创建时自动填充 tenant_id。

```php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);
        
        // 创建时自动填充 tenant_id
        static::creating(function (Model $model) {
            if (empty($model->tenant_id)) {
                $model->tenant_id = TenantContext::getId();
            }
        });
    }
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }
}
```

---

## 上下文管理

### TenantContext

**职责**：管理当前请求的租户信息，全局可用。

```php
class TenantContext
{
    protected static ?string $tenantId = null;
    protected static ?Tenant $tenant = null;
    protected static ?string $domainType = null;
    protected static ?string $tenantRole = null;
    
    // 获取当前租户ID
    public static function getId(): ?string;
    
    // 设置当前租户ID
    public static function setId(?string $tenantId): void;
    
    // 获取当前租户对象
    public static function getTenant(): ?Tenant;
    
    // 设置当前租户
    public static function setTenant(?Tenant $tenant): void;
    
    // 获取域名类型
    public static function getDomainType(): ?string;
    
    // 设置域名类型
    public static function setDomainType(?string $type): void;
    
    // 获取租户内角色
    public static function getTenantRole(): ?string;
    
    // 设置租户内角色
    public static function setTenantRole(?string $role): void;
    
    // 清除上下文（用于测试）
    public static function clear(): void;
}
```

---

## 权限控制

### CheckPermission 中间件

**职责**：根据域名类型和用户角色进行权限控制。

**角色维度**：

**维度一：平台级角色（`users.role`）**

| 值 | 说明 |
|---|---|
| `super_admin` | 超级管理员，访问 `admin.lyt.com` |
| `platform_user` | 普通用户（默认） |

**维度二：租户内角色（`tenant_users.role`）**

| 值 | 说明 |
|---|---|
| `tenant_admin` | 企业管理员，访问 `/console/*` |
| `end_user` | 终端用户，使用应用 |

**权限检查逻辑**：

```php
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
```

**各层级权限**：

| 域名类型 | 允许的角色 |
|----------|-----------|
| admin | super_admin |
| console | tenant_admin |
| api/app | tenant_admin + end_user |

---

## 典型账号状态

```
超级管理员：users.role='super_admin'  + tenant_users.role='tenant_admin'（平台默认租户）
企业管理员：users.role='platform_user' + tenant_users.role='tenant_admin'（企业租户）
企业用户：  users.role='platform_user' + tenant_users.role='end_user'（企业租户）
公共用户：  users.role='platform_user' + tenant_users.role='end_user'（公共平台租户）
```

---

## 请求数据流

```
HTTP Request
    │
    ▼
Nginx
    ├─ 识别路径前缀（/console、/api）→ 路由到 PHP-FPM
    ├─ 注入 X-Original-Host: $host
    └─ 其余路径 → 静态文件
    │
    ▼
Laravel Middleware Stack
    │
    ├─ 1. IdentifyDomain
    │     读 X-Original-Host + getPathInfo()
    │     → domain_type: 'admin' | 'console' | 'api' | 'app'
    │
    ├─ 2. IdentifyTenant
    │     IF admin → 跳过
    │     ELSE 按优先级解析租户 ID：
    │       URL param → Header → custom_domain DB → Cookie → Session → platform default
    │     → tenant_id, tenant 注入 TenantContext
    │
    ├─ 3. CheckPermission
    │     检查用户角色和权限
    │
    └─ 4. Controller / Route Handler
    │
    ▼
Eloquent Model
    └─ TenantScope 自动: WHERE tenant_id = {current_tenant_id}
```

---

## 测试

### 测试租户隔离

```php
class TenantIsolationTest extends TestCase
{
    public function test_queries_are_scoped_by_tenant_id(): void
    {
        // 为不同租户创建数据
        Order::create(['tenant_id' => 1001, 'name' => 'Order A']);
        Order::create(['tenant_id' => 1002, 'name' => 'Order B']);
        
        // 设置当前租户
        TenantContext::setId('1001');
        
        // 查询只返回当前租户的数据
        $orders = Order::all();
        $this->assertCount(1, $orders);
        $this->assertEquals('Order A', $orders->first()->name);
    }
    
    public function test_create_auto_fills_tenant_id(): void
    {
        TenantContext::setId('1001');
        
        $order = Order::create(['name' => 'New Order']);
        
        $this->assertEquals('1001', $order->tenant_id);
    }
}
```

### 测试域名识别

```php
class IdentifyDomainTest extends TestCase
{
    public function test_admin_domain_is_recognized(): void
    {
        $response = $this->withHeaders([
            'X-Original-Host' => 'admin.lyt.com',
        ])->get('/admin');
        
        $response->assertSuccessful();
    }
    
    public function test_custom_domain_identifies_tenant(): void
    {
        $response = $this->withHeaders([
            'X-Original-Host' => 'ai.tenant1.local',
        ])->get('/');
        
        $response->assertSuccessful();
        $response->assertJson([
            'tenant_name' => '点石数字传媒',
        ]);
    }
}
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
