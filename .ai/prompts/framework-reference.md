# 框架核心规范（开发必读）

> **只读此文件，不要探索其他文档。如需参考已有代码模式，最多读 1 个 Model 文件。**

---

## 1. 全局 ID 规范

**所有 Model 主键必须是 16 位整数，由 HasGlobalId trait 自动生成。**

```php
// Model 中使用
class YourModel extends Model
{
    use HasGlobalId;  // 自动生成 16 位整数 ID

    protected $primaryKey = 'your_model_id';  // 主键命名：{model_snake}_id
    // 不要设置 $incrementing = false，HasGlobalId 已处理
    // 不要设置 $keyType = 'int'，HasGlobalId 已处理
}
```

**迁移文件中：**
```php
Schema::create('your_models', function (Blueprint $table) {
    $table->unsignedBigInteger('your_model_id')->primary();
    // ...
    $table->timestamps();
    $table->softDeletes();  // 可选
});
```

**外键引用：**
```php
$table->unsignedBigInteger('parent_model_id');  // 不要加 ->foreign() 约束
// 外键值也是 16 位整数，来自父模型的 HasGlobalId
```

---

## 2. 多租户隔离规范

**租户级 Model 使用 BelongsToTenant trait。**

```php
class YourModel extends Model
{
    use HasGlobalId, BelongsToTenant;  // 两者一起用

    protected $primaryKey = 'your_model_id';
    protected $fillable = ['your_model_id', 'tenant_id', ...];
}
```

**迁移文件必须有 tenant_id：**
```php
Schema::create('your_models', function (Blueprint $table) {
    $table->unsignedBigInteger('your_model_id')->primary();
    $table->unsignedBigInteger('tenant_id');  // 租户 ID（必填）
    $table->index('tenant_id');                // 加索引
    // ...
});
```

**系统级 Model（不需要租户隔离）不用 BelongsToTenant：**
```php
class SystemSetting extends Model  // 系统配置、订阅计划等
{
    use HasGlobalId;  // 只用 HasGlobalId，不用 BelongsToTenant
}
```

---

## 3. BelongsToTenant 工作原理

- `bootBelongsToTenant()`: 自动添加 TenantScope 全局作用域
- 创建时自动填充 `tenant_id`（从 TenantContext 获取）
- 查询自动过滤 `WHERE tenant_id = ?`
- 提供 `tenant()` 关联方法

---

## 4. TenantScope 工作原理

- `apply()`: 自动加 `WHERE tenant_id = currentTenantId`
- `withoutTenantScope()`: 移除过滤（仅 admin 域名可用）
- `withTenant($id)`: 指定租户查询（仅 admin 域名可用）
- `forAllTenants()`: 查所有租户（仅 admin 域名可用）

---

## 5. ID 生成器 (IdGenerator)

- 范围: 1000000000000000 ~ 9007199254740991
- JS 安全: <= Number.MAX_SAFE_INTEGER
- 全局唯一（所有表共用 ID 空间）
- 通过 `app(IdGeneratorContract::class)->generate()` 调用
- HasGlobalId trait 已自动调用，无需手动生成

---

## 6. 命名空间

```
Model:   MultiTenantSaas\Models\YourModel
Concern: MultiTenantSaas\Concerns\HasGlobalId / BelongsToTenant
Scope:   MultiTenantSaas\Scopes\TenantScope
Service: MultiTenantSaas\Services\YourService
Contract: MultiTenantSaas\Contracts\YourContract
Context: MultiTenantSaas\Context\TenantContext
```

---

## 7. 代码风格

- 使用 PHP 8.1+ 语法
- 属性类型声明: `protected function casts(): array`
- 返回类型: 所有方法都要标注返回类型
- 注释: 中文注释，`/** */` 格式
- 文件末尾不加 `?>`

---

## 8. 禁止事项

- ❌ 不要用自增 ID（`$table->id()` 或 `$table->bigIncrements()`）
- ❌ 不要用 UUID
- ❌ 不要手动设置 `tenant_id`（BelongsToTenant 会自动填充）
- ❌ 不要在非 admin 域名调用 `withoutTenantScope()`
- ❌ 不要探索过多参考文件（只读本文件 + 1 个 Model 示例即可）
