## Architecture

**整体设计合理，模块边界清晰，职责划分正确。** `FeatureFlagService` → `FeatureFlag` Model → Migration 的分层符合 Laravel 惯例。中间件独立、职责单一。Service 已在 `TenancyServiceProvider` 第 157 行正确注册为 singleton。`seedPresets()` 正确读取 `config('tenancy.feature_flags.presets')`，`find()` 正确读取 `config('tenancy.feature_flags.cache_ttl')`，config 不是摆设。

**问题：**

1. **Model 上的 `history()` 与 Service 的 `getHistory()` 重复。** `FeatureFlag.php:85-93` 和 `FeatureFlagService.php:351-364` 逻辑完全相同（查询 `audit_logs`），违反 DRY。建议删除 Model 上的 `history()`，统一走 Service。

2. **`conditions` JSON 字段承载过多职责。** 一个字段塞入了 `ab_groups`、`tenant_overrides`、`user_overrides` 三种语义数据。随着覆盖数量增长 JSON blob 会膨胀，且无法用数据库索引查询单个覆盖。当前规模可接受，但需注意后续扩展时考虑拆表。

3. **config 的 presets 使用字符串字面量而非 Model 常量。** `config/tenancy.php:116` 写 `'scope' => 'global'` 而非引用 `FeatureFlag::SCOPE_GLOBAL`，如果常量值变更会不同步。虽然 config 文件无法直接引用类常量（PHP 数组字面量限制），但应在 `seedPresets()` 中做校验或在文档中注明。

## Code Quality

**命名规范、中文注释+PHPDoc 一致，方法命名清晰，整体可读性好。**

**问题：**

4. **测试中 `AuditLog` import 未使用。** `tests/FeatureFlagServiceTest.php:8` 导入了 `AuditLog` 但从未直接引用（测试用的是 `assertDatabaseHas('audit_logs', ...)` 字符串形式）。

5. **测试 setUp 中 singleton 注册冗余。** `FeatureFlagServiceTest.php:37-38` 注册了 `$this->app->singleton(FeatureFlagService::class)` 并注释说"TenancyServiceProvider 不在本任务修改范围内"，但实际上 TenancyServiceProvider 已经注册了（第 157 行）。冗余但无害。

6. **`clearCache(null)` 全量清除效率低。** 遍历 `FeatureFlag::withTrashed()->pluck('name')` 逐个 `Cache::forget()`。开关数量增长时性能退化。可考虑使用 cache tag 或 key 前缀批量清除（取决于底层缓存驱动）。

## Type Safety

**方法参数和返回值类型声明完整，PHPDoc 泛型标注到位。**

**问题：**

7. **`setAbGroups` 百分比之和未校验。** 接受 `array<string,int>` 但不验证百分比之和是否为 100。如果之和 < 100，`getAbGroup()` 中 `cumulative` 循环结束后尾部用户返回 `null`（落入无分组）；如果 > 100，尾部逻辑也不会报错但语义错误。缺少业务层断言。

8. **`getAbGroup` 返回 `null` 有两种语义但无区分。** "开关未启用" 和 "百分比之和不足 100 导致未命中任何分组" 都返回 `null`，调用方无法区分。可考虑抛出异常或返回包含原因的值对象。

9. **`conditions` 和 `dependencies` 内部结构无类型保障。** cast 为 `array` 后，内部结构（`ab_groups` 必须是 `string→int`，`tenant_overrides` 必须是 `string→bool`）依赖运行时数据正确性，无静态保障。

## Security

**无 SQL 注入风险（全量使用 Eloquent Query Builder）。无 XSS 风险（JSON API，无 HTML 渲染）。**

**问题：**

10. **写操作方法无权限控制。** `FeatureFlagService` 的 `enable()`、`disable()`、`setRolloutPercentage()`、`setTenantOverride()` 等方法均为 public，无鉴权。任何能注入该 Service 的代码均可静默修改任意开关。建议在 Service 或路由层面增加权限校验（如 admin 权限检查），或在文档中明确说明调用方需自行鉴权。

11. **中间件返回 404 而非 403。** `CheckFeatureFlag` 在功能开关未启用时返回 `HTTP_NOT_FOUND`（404）。从语义上看，资源存在但用户无权访问更应返回 403。不过任务验收标准明确要求"未启用返回 404"，符合要求。**非问题，仅标注设计意图。**

## Performance

**核心查询链路：`isEnabled` → `find` (cached) → `checkEnabled` 递归。缓存降级策略合理（Redis → 数组缓存 → 直查 DB）。**

**问题：**

12. **`seedPresets()` 中的 `findByName` 每次查 DB。** 5 个预置开关 = 5 次 DB 查询（首次）。可以先批量查询已存在的 name 集合，再只创建缺失的。

13. **依赖链缓存放大效应。** 如果开关 A 依赖 B、C、D，每次 `isEnabled('A')` 会触发 4 次 `Cache::remember()` 调用。深层依赖链会导致 N 次缓存查询。当前规模可接受，但建议对依赖检查结果做短 TTL 级联缓存。

14. **`conditions` JSON 字段的每次覆写是 read-modify-write 模式。** 虽然已使用 `DB::transaction` + `lockForUpdate()` 保证了单行原子性，但 JSON 反序列化→修改→序列化的开销在高并发写入场景下仍然可观。当前实现已足够，但需注意不要将此模式用于高频写入路径。

## Potential Bugs

15. **`scope` 字段无枚举校验。** `create()` 中 `$data['scope'] ?? 'global'` 不验证是否为 `global/tenant/user` 之一，可以写入任意字符串。建议在 `create()` 中加入 `in_array` 校验。

16. **`hashBucket` 在 `tenantId` 和 `userId` 均为 null 时使用 `seed=0`。** 所有无上下文的调用会落在同一个桶，灰度行为不可预测。在 `getAbGroup` 中尤为突出——如果调用方不传 tenantId/userId 且无租户上下文，所有请求都归入同一分组。建议对无上下文场景抛出异常或返回默认值。

17. **`FeatureFlag` 的 `feature_flag_id` 在 `$fillable` 中。** 允许外部传入主键值。虽然 `HasGlobalId` 仅在 `empty()` 时生成，但在生产环境中应禁止客户端指定主键，避免 ID 冲突或预测。建议从 `$fillable` 中移除 `feature_flag_id`，测试中如需指定 ID 可用 `forceCreate()`。

18. **`dependencies` 不校验被依赖开关是否存在。** `addDependency()` 接受任意字符串作为 `dependsOn`，不验证目标开关是否实际存在。可以添加一个不存在的开关作为依赖，导致 `checkEnabled` 在递归时因 `find()` 返回 null 而返回 false，间接禁用当前开关。

---

## Verdict

**PASS**

整体实现质量高，架构合理，核心功能（全局/租户/用户开关、灰度、A/B、依赖、中间件、预置开关、审计、缓存）均已覆盖且有充分测试。前一轮 review 指出的 singleton 未注册和 config 未读取的问题实际**不存在**——`TenancyServiceProvider:157` 已注册 singleton，`seedPresets()` 和 `find()` 均正确读取 config。

【建议改进】（非阻塞）：

1. 删除 `FeatureFlag::history()` 方法，消除与 `FeatureFlagService::getHistory()` 的重复。
2. 从 `FeatureFlag::$fillable` 中移除 `feature_flag_id`，防止外部指定主键。
3. `setAbGroups()` 增加百分比之和校验（可选：允许不等于 100 但需文档说明）。
4. `create()` 增加 `scope` 字段的枚举校验（`in_array` 检查 `global/tenant/user`）。
5. 清理测试中未使用的 `use AuditLog` import 和冗余的 singleton 注册。
6. `seedPresets()` 改为批量查询已存在 name 集合，减少 DB 查询次数。
