## Architecture

模块划分清晰，职责单一。`AiProvider` / `AiRequest` / `AiModelAlias` 三者边界明确，无循环依赖。

`BelongsToTenant` 的 `empty()` → `is_null()` 改动正确解决了系统级记录（`tenant_id=null`）创建问题——`is_null` 不会拦截显式传入的 `null`，允许系统级 Provider 正常创建。`TenantScope` 在 `tenantId` 为 null 时不做过滤（`if ($tenantId)`），系统级查询也能正确走通。

`AiModelAlias` 不挂 `BelongsToTenant` 合理——别名是全局配置，无需租户隔离。

**小瑕疵**：`AiProvider` 和 `AiRequest` 的 `tenant_id` 列类型是 `bigInteger()->unsigned()->nullable()`，但 `BelongsToTenant::getCurrentTenantId()` 返回 `?string`，模型 cast 为 `integer`。类型在 string/int 之间摇摆，虽不影响运行（PHP 松散比较），但建议统一。

---

## Code Quality

**优点：**
- Scope 方法全部补齐了 `Builder` 参数类型和 `Builder` 返回类型，与 `Tenant.php` 一致
- `isActive()` / `isDeprecated()` 去掉冗余 `=== true` 比较，cast 为 boolean 后直接返回即可
- 中文 docblock 质量高，关键设计决策有注释（如 api_key 加密注意事项）
- `$persist` 默认值从 `false` 改为 `true` 消除了调用方遗忘 `save()` 的隐式陷阱

**问题：**

1. **`AiRequest::user()` 缺少 `User` import** — 类中引用了 `User::class`（第 82 行），但文件头没有 `use MultiTenantSaas\Models\User;`。PHP 会将其解析为 `MultiTenantSaas\Models\User`（同命名空间），如果该类确实存在于该命名空间则没问题，但如果 User 模型在其他位置（如 `App\Models\User`）则会 fatal error。需确认 User 模型的 FQCN。

2. **`markAsSuccess` / `markAsFailed` 的 `save()` 无异常保护** — `$persist = true` 后直接调用 `$this->save()`，如果数据库写入失败，模型内存状态已变更但未持久化，调用者无感知。建议至少在 docblock 中注明可能抛出 `QueryException`。

---

## Type Safety

**基本完善**，`casts()` 方法式声明覆盖了关键字段。

**问题：**

1. **`AiRequest.cost` 精度风险**：cast 为 `decimal:6`，Laravel 的 decimal cast 在赋值时**截断而非四舍五入**。传入 `0.123456789` 会得到 `"0.123456"` 而非 `"0.123457"`。docblock 有提醒应传 string，但运行时无保护。对于计费字段，这可能导致金额偏差。

2. **`setApiKeyAttribute($value)` 无参数类型标注** — 声明为 `setApiKeyAttribute($value): void`，但 `$value` 未标注类型。虽然 Eloquent mutator 的惯例是不加类型，但对于加密字段，明确 `?string` 类型能防止非预期类型传入。

---

## Security

**✅ 良好：**
- `api_key` 使用 `Crypt::encryptString` / `decryptString`，解密失败不抛异常，返回 null + 日志记录
- `api_key` 故意不加入 `$casts`，避免 mutator 被绕过（有注释说明）
- `$fillable` 白名单模式，无 `$guarded = []` 的风险
- `TenantScope` 强制租户隔离，绕过需 admin 域名 + 显式调用

**⚠️ 关注点：**

1. **`api_key` 加密依赖 `APP_KEY`**：`APP_KEY` 泄露或轮换会导致已加密数据无法解密。这是 Laravel `Crypt` 的已知限制，建议运维文档标注。

2. **`AiRequest.error_message` 可能含敏感信息**（堆栈、内部路径）。当前模型层无法控制暴露，但 Service 层应做脱敏。

3. **无 SQL 注入风险**：全部 Eloquent ORM，scope 参数类型声明为 `string`。

---

## Performance

**✅ 良好：**
- 迁移索引设计合理：`ai_requests` 的 `(tenant_id, created_at)`、`(tenant_id, model)`、`(tenant_id, provider)` 覆盖了常见的租户+维度查询
- `ai_providers` 的 `(tenant_id, code)` 唯一索引避免重复配置
- `ai_requests` 的 `idx_status` 已改为 `(tenant_id, status)` 复合索引，选择性大幅提升

**⚠️ 关注点：**

1. **`getApiKeyAttribute` 每次访问都调用 `Crypt::decryptString`**：循环中访问 `$provider->api_key` 会反复解密。建议调用方注意缓存，或在 accessor 中做 lazy cache（解密一次后存入 `$this->attributes` 副本）。

2. **`ai_providers` 仍有 `idx_status` 单列索引**：与 `ai_requests` 的改进不一致，`status` 基数低（仅 2 个值），单列索引选择性差。建议也改为 `(tenant_id, status)` 或直接移除（已有 `(tenant_id, code)` 覆盖大部分查询路径）。

---

## Potential Bugs

1. **⚠️ `AiRequest::user()` 缺少 import（高风险）**：
   `AiRequest.php:82` 引用 `User::class`，但无 `use` 语句。PHP 会解析为当前命名空间 `MultiTenantSaas\Models\User`。如果 User 模型不在该命名空间，调用 `$request->user()` 将 fatal error。即使在同命名空间，缺少显式 import 也不符合 PSR-12 规范。

2. **⚠️ `markAsSuccess` / `markAsFailed` 的 `save()` 无事务保护（中等风险）**：
   `$persist = true` 时直接调用 `$this->save()`。如果调用者在事务中使用（如 `$request->markAsFailed()` 后又做了其他操作失败回滚），model 内存状态不会回滚。此外，save 失败时调用者无感知。

3. **`BelongsToTenant` 与 `HasGlobalId` 的 boot 顺序依赖**：
   `use BelongsToTenant, HasFactory, HasGlobalId` — Laravel 按声明顺序 boot traits。`HasGlobalId` 的 `creating` 回调先于 `BelongsToTenant` 执行，两者都操作 creating 事件但字段不同（主键 vs tenant_id），当前无冲突。但若未来任一 trait 依赖另一方的字段值，顺序敏感性会成为隐患。

---

## Verdict

**FAIL**

【必须修复】：

1. **`AiRequest.php` 缺少 `User` 类的 import 语句**：第 82 行 `return $this->belongsTo(User::class, ...)` 中的 `User` 无 import。如果 `User` 模型不在 `MultiTenantSaas\Models` 命名空间下，运行时将 fatal error。需确认 User 模型的 FQCN 并添加正确的 `use` 语句。

2. **`markAsSuccess` / `markAsFailed` 调用 `save()` 时无异常处理**：`$persist = true` 是新默认值，意味着所有现有调用路径都会自动 `save()`。建议在方法内用 `try/catch` 捕获 `QueryException` 并记录日志，或至少在 docblock 中明确标注"可能抛出数据库异常"，让调用者做好防御。
