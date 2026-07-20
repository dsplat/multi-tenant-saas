# 框架测试 Bug 分析与最佳实践

> 从权限模型重构中发现的 150+ 个测试失败，提炼出框架开发的核心教训
> **最后更新**: 2026-07-20

---

## 一、发现的 Bug 模式

### 1.1 命名空间缺失（31 个错误）

**现象**：模块化后，Service 引用其他模块的类时缺少 `use` 语句。

**根因**：模块化时只移动了文件，没有更新所有引用。

**示例**：
```php
// src/Modules/Monitoring/Services/SlaService.php
// 缺少: use MultiTenantSaas\Modules\Infrastructure\Services\AlertService;
app(AlertService::class)->trigger(...);  // 运行时找不到类
```

**教训**：模块化是系统工程，不是简单的文件移动。

---

### 1.2 种子数据冲突（15 个错误）

**现象**：多个测试类同时插入相同权限数据，导致 UNIQUE 约束失败。

**根因**：种子数据不是幂等的，使用 `insert()` 而非 `updateOrInsert()`。

**示例**：
```php
// 错误：直接插入
DB::table('permissions')->insert(['name' => 'tenant.view', ...]);

// 正确：幂等插入
DB::table('permissions')->updateOrInsert(
    ['name' => 'tenant.view'],
    ['display_name' => '查看租户', ...]
);
```

**教训**：所有种子数据必须幂等。

---

### 1.3 RBAC 权限未配置（142 个 403 错误）

**现象**：添加 RBAC 中间件后，所有测试返回 403。

**根因**：测试用户没有创建 Operator 映射，无法通过权限检查。

**示例**：
```php
// 错误：只创建用户
$user = User::create([...]);

// 正确：创建用户 + Operator + 映射
$user = User::create([...]);
$operator = Operator::create(['email' => $user->email, ...]);
OperatorTenant::create([
    'operator_id' => $operator->operator_id,
    'tenant_id' => $tenantId,
    'user_id' => $user->user_id,
    'role' => 'tenant_admin',
    'role_id' => $roleId,
]);
```

**教训**：权限测试必须配置完整的权限链路。

---

### 1.4 硬编码 ID 冲突（5 个错误）

**现象**：测试使用硬编码的 `permission_id`，与种子数据冲突。

**根因**：测试假设 ID 是固定的，但种子数据可能使用不同的 ID。

**示例**：
```php
// 错误：硬编码 ID
$role->grantPermission(1);  // ID 1 可能不是 tenant.view

// 正确：动态查找
$permId = DB::table('permissions')
    ->where('name', 'tenant.view')
    ->value('permission_id');
$role->grantPermission($permId);
```

**教训**：永远不要硬编码 ID，使用名称查找。

---

### 1.5 SQL 列名歧义（3 个错误）

**现象**：JOIN 查询时 `tenant_id` 列名歧义。

**根因**：users 和 tenant_users 表都有 `tenant_id` 列。

**示例**：
```php
// 错误：列名歧义
TenantUser::where('tenant_id', $tenantId)
    ->join('users', ...)
    ->get();

// 正确：限定表名
TenantUser::where('tenant_users.tenant_id', $tenantId)
    ->join('users', ...)
    ->get();
```

**教训**：JOIN 查询必须限定列名。

---

### 1.6 SQLite PRAGMA 冲突（3 个错误）

**现象**：`DatabaseTransactions` trait 与 SQLite PRAGMA 冲突。

**根因**：PRAGMA 不能在事务中执行。

**示例**：
```php
// 错误：直接执行
$pdo->exec('PRAGMA synchronous=OFF');

// 正确：捕获异常
try {
    $pdo->exec('PRAGMA synchronous=OFF');
} catch (\Throwable $e) {
    // PRAGMA 失败是安全的，可以忽略
}
```

**教训**：测试环境的特殊处理需要考虑事务上下文。

---

### 1.7 命名空间路径错误（5 个错误）

**现象**：模块化后，类引用了错误的命名空间。

**根因**：批量替换时遗漏或路径计算错误。

**示例**：
```php
// 错误：引用了不存在的命名空间
use MultiTenantSaas\Modules\Operator\Services\IdGenerator;

// 正确：IdGenerator 在 Infrastructure 模块
use MultiTenantSaas\Modules\Infrastructure\Services\IdGenerator;
```

**教训**：模块化后需要全面验证命名空间引用。

---

### 1.8 租户隔离缺失（1 个错误）

**现象**：`getTemplates()` 返回所有租户的数据。

**根因**：查询没有添加 `tenant_id` 过滤。

**示例**：
```php
// 错误：无租户过滤
return SmsTemplate::query()->get();

// 正确：添加租户过滤
$tenantId = TenantContext::getId();
return SmsTemplate::where('tenant_id', $tenantId)->get();
```

**教训**：所有租户数据查询必须包含租户过滤。

---

## 二、框架开发最佳实践

### 2.1 模块化原则

1. **文件移动后必须更新所有引用**：使用 IDE 的重构工具或批量搜索替换
2. **共享代码放在 Infrastructure 模块**：被多个模块使用的类放在 Infrastructure
3. **模块间依赖通过命名空间引用**：不要使用相对路径或硬编码路径

### 2.2 测试数据管理

1. **所有种子数据必须幂等**：使用 `updateOrInsert()` 而非 `insert()`
2. **不要硬编码 ID**：使用名称或条件查找
3. **测试数据与生产数据分离**：测试使用专门的种子数据
4. **清理测试数据**：每个测试后清理或使用事务回滚

### 2.3 权限系统设计

1. **权限检查必须完整链路**：用户 → Operator → 角色 → 权限
2. **测试必须配置权限**：不要假设测试用户有所有权限
3. **权限中间件必须有回退**：处理权限未配置的情况
4. **租户隔离必须强制**：所有数据查询必须包含租户过滤

### 2.4 数据库查询

1. **JOIN 查询必须限定列名**：避免歧义
2. **使用参数化查询**：防止 SQL 注入
3. **索引优化**：为常用查询添加索引
4. **事务管理**：批量操作使用事务

### 2.5 错误处理

1. **捕获特定异常**：不要捕获所有异常
2. **记录错误日志**：便于调试
3. **返回友好错误**：不要暴露内部错误
4. **测试错误路径**：不仅测试正常路径

---

## 三、测试策略建议

### 3.1 测试金字塔

```
       /\
      /  \  E2E 测试（少量）
     /----\
    /      \  集成测试（适量）
   /--------\
  /          \  单元测试（大量）
 /____________\
```

### 3.2 测试覆盖要求

| 类型 | 覆盖率 | 说明 |
|------|--------|------|
| 单元测试 | 80%+ | 核心业务逻辑 |
| 集成测试 | 60%+ | 模块间交互 |
| E2E 测试 | 关键路径 | 用户完整流程 |

### 3.3 测试数据管理

```php
// 好的做法：使用工厂和种子数据
class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->createTestUser();
    }
    
    private function seedRolesAndPermissions(): void
    {
        // 幂等种子数据
        Role::updateOrCreate(['name' => 'admin'], [...]);
    }
}
```

### 3.4 权限测试模式

```php
// 好的做法：完整的权限链路
public function test_admin_can_access(): void
{
    $user = User::create([...]);
    $operator = Operator::create([...]);
    OperatorTenant::create([...]);
    
    $token = $user->createToken('test')->plainTextToken;
    
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/resource');
    
    $response->assertSuccessful();
}
```

---

## 四、框架架构启示

### 4.1 模块化架构的价值

1. **独立开发**：每个模块可以独立开发和测试
2. **清晰边界**：模块间依赖关系明确
3. **易于维护**：修改一个模块不影响其他模块
4. **可扩展性**：新功能可以作为新模块添加

### 4.2 权限系统设计

1. **统一权限来源**：所有权限检查走同一路径
2. **细粒度控制**：支持角色和权限的细粒度管理
3. **租户隔离**：每个租户的权限独立
4. **审计日志**：所有权限变更记录日志

### 4.3 测试驱动开发

1. **先写测试**：测试定义了代码的行为
2. **小步迭代**：每次只实现一个功能点
3. **持续重构**：测试保护重构不引入错误
4. **测试即文档**：测试描述了代码的使用方式

---

## 五、总结

| 类别 | 数量 | 教训 |
|------|------|------|
| 命名空间缺失 | 31 | 模块化需要系统工程 |
| 种子数据冲突 | 15 | 种子数据必须幂等 |
| RBAC 权限 | 142 | 权限测试需要完整链路 |
| 硬编码 ID | 5 | 永远不要硬编码 ID |
| SQL 歧义 | 3 | JOIN 必须限定列名 |
| SQLite PRAGMA | 3 | 测试环境特殊处理 |
| 命名空间错误 | 5 | 模块化后全面验证 |
| 租户隔离 | 1 | 数据查询必须租户过滤 |

**核心教训**：框架开发需要系统性思维，不是简单的代码堆砌。每个设计决策都会影响整个生态系统的稳定性和可维护性。
