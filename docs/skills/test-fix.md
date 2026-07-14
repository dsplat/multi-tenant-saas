---
name: test-fix
description: 测试修复流程：运行测试、定位失败、修复、验证
triggers:
  - /test-fix
  - 测试失败
  - 修复测试
  - test fail
  - phpunit
---

# 测试修复流程

## 快速开始

```bash
# 运行所有测试
php vendor/bin/phpunit

# 只看失败
php vendor/bin/phpunit --failures
```

---

## 标准流程

### 1. 运行测试，收集失败信息

```bash
# 完整输出
php vendor/bin/phpunit --testdox 2>&1 | tee /tmp/test-results.txt

# 统计
php vendor/bin/phpunit 2>&1 | tail -5
```

### 2. 分析失败类型

| 类型 | 特征 | 处理方式 |
|------|------|---------|
| 断言失败 | `Failed asserting that...` | 检查预期值 vs 实际值 |
| 异常 | `Exception: ...` | 修复代码逻辑 |
| 类不存在 | `Class ... not found` | 检查命名空间/导入 |
| 方法不存在 | `Method ... does not exist` | 检查方法签名 |
| 数据库错误 | `SQLSTATE...` | 检查迁移/数据 |
| Mock 错误 | `Expected ... but received` | 检查 Mock 设置 |

### 3. 逐个修复

```bash
# 运行单个测试类
php vendor/bin/phpunit tests/Path/To/Test.php

# 运行单个测试方法
php vendor/bin/phpunit --filter testMethodName tests/Path/To/Test.php
```

### 4. 验证修复

```bash
# 运行修复的测试
php vendor/bin/phpunit tests/Path/To/Test.php

# 运行所有测试确认无回归
php vendor/bin/phpunit
```

---

## 常见问题及修复

### Schema/迁移问题

```bash
# 症状：表不存在、列不存在
# 修复：检查测试 Schema 是否包含所需表

# 查看测试 Schema
grep -r "Schema::" tests/Schema/
```

**模式**：在 `tests/Schema/` 下找到对应的 SchemaModule，确保包含所需表。

### 命名空间/导入问题

```bash
# 症状：Class not found
# 修复：检查 use 语句和 composer autoload

# 查找类位置
grep -r "class ClassName" src/
```

### Mock 问题

```bash
# 症状：Expected method but not called
# 修复：检查 Mock 设置和方法调用
```

**模式**：
- `expects($this->once())` → 确保方法只调用一次
- `expects($this->never())` → 确保方法不被调用
- `with(...)` → 参数必须完全匹配

### 数据库状态问题

```bash
# 症状：测试间数据污染
# 修复：确保使用 RefreshDatabase 或 DatabaseTransactions
```

**模式**：
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

class MyTest extends TestCase
{
    use RefreshDatabase;
}
```

### 权限/RBAC 问题

```bash
# 症状：403 Forbidden
# 修复：检查测试是否设置了正确的角色和权限
```

**模式**：
```php
// 在测试 setUp 中
$this->role = Role::create([...]);
$this->permission = Permission::create([...]);
$this->role->permissions()->attach($this->permission);
```

### 路由问题

```bash
# 症状：404 Not Found
# 修复：检查路由是否注册、中间件是否正确
```

**模式**：
- 确保测试包含正确的 Module（在 Schema 中）
- 检查路由前缀和中间件

---

## 批量修复策略

### 按模块修复

```bash
# 运行特定模块的测试
php vendor/bin/phpunit tests/Modules/Auth/
php vendor/bin/phpunit tests/Modules/Billing/
```

### 按类型修复

```bash
# 只运行单元测试
php vendor/bin/phpunit --testsuite Unit

# 只运行功能测试
php vendor/bin/phpunit --testsuite Feature
```

### 并行运行（加速）

```bash
# 使用 paratest
vendor/bin/paratest --processes=4
```

---

## 修复后检查清单

- [ ] 修复的测试通过
- [ ] 所有测试通过（无回归）
- [ ] Laravel Pint 通过：`vendor/bin/pint --test`
- [ ] 提交修复：`git commit -m "fix(test): ..."`

---

## 调试技巧

### 查看完整错误

```bash
php vendor/bin/phpunit --verbose tests/Path/To/Test.php
```

### 使用 dump()

```php
public function testSomething()
{
    $result = $this->get('/api/endpoint');
    dump($result->json()); // 查看响应
    $result->assertOk();
}
```

### 使用 Laravel Telescope（本地）

```bash
# 访问 http://your-app.test/telescope
# 查看 Queries、Requests、Exceptions
```

### 数据库调试

```php
public function testSomething()
{
    DB::enableQueryLog();
    // ... 执行操作
    dd(DB::getQueryLog()); // 查看执行的 SQL
}
```
