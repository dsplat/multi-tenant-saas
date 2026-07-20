# 下游项目架构指南

**最后更新**: 2026-07-19

## 核心原则

**下游项目不应直接 import 框架模块的模型**。

框架模块是可插拔的，内部结构可能随版本变化。下游应通过 Service 层访问框架功能。

## 快速开始

```bash
# 创建新项目
composer create-project dsplat/multi-tenant-saas my-app
cd my-app

# 初始化
php artisan migrate
php artisan platform:init --email=admin@example.com --password=your-password

# 构建前端
cd resources/js/admin && npm install && npx vite build && cd ../../..
cd resources/js/console && npm install && npx vite build && cd ../../..
```

---

## 推荐架构

### 1. 下游项目有自己的模型

```php
// app/Models/User.php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    // 下游自定义字段、方法
    // 不继承框架的 User
}
```

### 2. 通过 Service 访问框架功能

```php
// app/Services/TenantUserService.php
namespace App\Services;

use MultiTenantSaas\Contracts\UserContract;

class TenantUserService
{
    public function __construct(
        private UserContract $frameworkUsers
    ) {}

    /**
     * 同步框架用户到本地
     */
    public function syncFromFramework(string $frameworkUserId): ?User
    {
        $frameworkUser = $this->frameworkUsers->findById($frameworkUserId);

        if (!$frameworkUser) {
            return null;
        }

        return User::updateOrCreate(
            ['framework_user_id' => $frameworkUserId],
            [
                'name' => $frameworkUser['name'],
                'email' => $frameworkUser['email'],
            ]
        );
    }
}
```

### 3. VPN 模块示例

```php
// app/Modules/VPN/Models/Node.php
namespace App\Modules\VPN\Models;

use Illuminate\Database\Eloquent\Model;

class Node extends Model
{
    protected $fillable = ['name', 'ip', 'user_id'];

    // user_id 指向本地 users 表
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
```

```php
// app/Modules/VPN/Services/VpnService.php
namespace App\Modules\VPN\Services;

use App\Models\User;
use MultiTenantSaas\Contracts\UserContract;

class VpnService
{
    public function __construct(
        private UserContract $frameworkUsers
    ) {}

    /**
     * 创建 VPN 节点
     */
    public function createNode(string $userId, array $data): Node
    {
        // 验证用户存在（通过框架 Service）
        $user = $this->frameworkUsers->findById($userId);

        if (!$user) {
            throw new \InvalidArgumentException("User not found: {$userId}");
        }

        // 在本地创建节点
        return Node::create([
            'name' => $data['name'],
            'ip' => $data['ip'],
            'user_id' => $userId,
        ]);
    }

    /**
     * 获取用户的节点列表
     */
    public function getUserNodes(string $userId): array
    {
        return Node::where('user_id', $userId)->get()->toArray();
    }
}
```

---

## 框架提供的 Contract

框架在 `src/Contracts/` 下定义接口，下游通过依赖注入使用：

| Contract | 说明 | 核心方法 |
|----------|------|----------|
| `UserContract` | 用户操作 | findById, findByEmail, create, update |
| `TenantContract` | 租户操作 | findById, create, update, delete |
| `TenantContextContract` | 上下文 | getId, setId, getDomainType |

### 使用方式

```php
// 在 ServiceProvider 中注册
$this->app->bind(\MultiTenantSaas\Contracts\UserContract::class, function ($app) {
    return new \App\Services\LocalUserService();
});

// 或直接使用框架实现
$this->app->bind(\MultiTenantSaas\Contracts\UserContract::class, \MultiTenantSaas\Services\UserService::class);
```

---

## 为什么不用继承

| 问题 | 继承 | Service/Contract |
|------|------|------------------|
| 框架升级 User 模型 | 可能破坏下游 | 不影响 |
| 下游需要自定义字段 | 受限于父类结构 | 完全自主 |
| 测试 | 需要框架环境 | 可 Mock Contract |
| 多框架支持 | 不可能 | 可适配不同框架 |

---

## 迁移步骤

如果下游项目已经直接 import 了框架模型：

### 1. 创建本地模型

```bash
php artisan make:model User
```

### 2. 定义本地字段

```php
// database/migrations/xxxx_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('framework_user_id')->nullable(); // 关联框架用户
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamps();
});
```

### 3. 创建 Service 层

```php
// app/Services/FrameworkUserService.php
namespace App\Services;

use MultiTenantSaas\Contracts\UserContract;

class FrameworkUserService
{
    public function __construct(
        private UserContract $users
    ) {}

    // 封装框架调用
}
```

### 4. 替换所有 import

```php
// 旧代码
use MultiTenantSaas\Models\User;

// 新代码
use App\Models\User;
// 或通过 Service 访问
```

---

## 常见场景

### 场景 1：获取当前登录用户

```php
// 框架提供
$user = TenantContext::getUser(); // 返回框架 User

// 下游转换
$localUser = User::where('framework_user_id', $user['id'])->first();
```

### 场景 2：创建用户

```php
// 通过框架 Service
$frameworkUser = $this->frameworkUsers->create([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// 同步到本地
$localUser = User::create([
    'framework_user_id' => $frameworkUser['id'],
    'name' => $frameworkUser['name'],
    'email' => $frameworkUser['email'],
]);
```

### 场景 3：查询用户

```php
// 通过框架 Service 查询
$users = $this->frameworkUsers->search(['name' => 'John']);

// 或查询本地同步的数据
$users = User::where('name', 'like', '%John%')->get();
```

---

## 扩展框架：添加自定义模块

下游项目可以在不修改框架代码的情况下，通过添加自定义模块来扩展功能。

### 目录结构

```
src/Modules/MyModule/
├── MyModuleServiceProvider.php    ← 继承 ModuleServiceProvider
├── composer.json                  ← extra.saas 配置
├── Database/migrations/           ← 自动加载
├── Models/                        ← 使用 HasGlobalId + BelongsToTenant
├── Services/                      ← 业务逻辑
├── Http/Controllers/              ← 使用 ApiResponse + AuthorizesTenantAccess
├── Routes/
│   ├── api.php                    → /api/v1/...  (auth + tenant)
│   ├── admin.php                  → /v1/admin/... (auth)
│   └── tenant.php                 → /tenant/... (auth)
└── resources/
    ├── admin/ui/element-plus/views/*.vue   → 自动发现，侧边栏显示
    └── console/ui/element-plus/views/*.vue → 自动发现，侧边栏显示
```

### 自动发现机制

- **后端**: `ModuleRegistry` 扫描 `src/Modules/*/composer.json` 的 `extra.saas` 字段
- **前端**: `module-loader.ts` 的 `getModulePageEntries()` 自动发现 Vue 文件并生成侧边栏入口
- **路由**: `ModuleServiceProvider::loadModuleRoutes()` 自动加载 `Routes/` 下的路由文件

### 多 UI 框架支持

框架支持同一页面的多个 UI 框架变体：

```
src/Modules/MyModule/resources/admin/
├── ui/
│   ├── bootstrap/views/MyPage.vue    ← Bootstrap 版本
│   └── element-plus/views/MyPage.vue ← Element Plus 版本
├── routes.ts                         ← 可选：自定义路由定义
└── views/                            ← 旧路径（自动发现仍支持）
```

构建时通过 `VITE_UI_FRAMEWORK` 环境变量选择框架变体。

### 完整示例

参考 `src/Modules/Ticket/` — 从数据库迁移、模型、服务、控制器、路由到前端页面的完整工作流。

---

## 已知问题（深度审查发现）

### 安全问题

1. **IdentifyTenant URL 参数注入** — `?tenant_id=xxx` 允许任意用户指定租户 ID，缺少对普通 User 的租户归属校验
2. **OperatorAuthController 登录锁定形同虚设** — `login_attempts` 从未在失败时递增，`locked_until` 从未被设置
3. **IdentifyOperator 中间件不阻断无效请求** — 无效 token 不会返回 401，请求继续以匿名身份执行
4. **Login.vue redirect 开放重定向** — 未验证 redirect 是否为内部路径
5. **MfaVerify.vue user_id 暴露在 URL 中** — 可被篡改绕过 MFA

### 设计问题

6. **Operator 模型 `$incrementing` 未设为 false** — 使用雪花 ID 但未声明非自增
7. **OperatorService::acceptInvite 双重 Hash** — 手动 `Hash::make()` + `hashed` cast 冲突
8. **OAuth 服务静态方法设计** — 无法通过依赖注入 mock 测试
9. **MailerService SMTP 密码明文存储** — 需确认 TenantSetting 是否支持加密
10. **admin.php 和 api.php 路由权限粒度不一致** — api 用 `setting.view`，admin 用 `webhook.view` 等

---

## 总结

- ✅ 下游有自己的 `App\Models\User`
- ✅ 通过 `MultiTenantSaas\Contracts\UserContract` 访问框架
- ✅ Service 层封装框架调用
- ✅ 通过自定义模块扩展框架功能（无需修改框架代码）
- ❌ 不直接 `use MultiTenantSaas\Modules\Auth\Models\User`
- ❌ 不继承框架模型
