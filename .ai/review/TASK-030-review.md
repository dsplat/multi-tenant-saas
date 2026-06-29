## Architecture

配置与服务层分离合理。`config/cache.php`、`config/database.php`、`config/queue.php` 的调优参数全部通过 `env()` 暴露，不硬编码敏感值，符合 12-Factor App 原则。`SubscriptionService::resolvePlanFromTenant()` 的提取是正确的重构——将缓存逻辑内聚到专用方法，同时保持 `getCurrentPlan()` 的公共接口不变。`TenantService::getFinancials()` 改为单次查询 + PHP 聚合，消除了对不存在的 `$tenant->financialRecords()` 关系的调用依赖（原代码有 `method_exists` 保护，但新方案更直接）。

队列分组（high/default/low）的设计合理，与业务优先级对齐。`WebhookService` 的 eager-load 限定列（`webhook:webhook_id,url,is_active,description`）精确控制了关联载入的数据量。

**无架构问题。**

## Code Quality

**命名规范：** 符合 PSR-12，方法名语义清晰。`resolvePlanFromTenant` 命名准确表达了"从已加载的 Tenant 模型解析计划"的意图。测试方法使用 snake_case + 中文注释描述场景，可读性好。

**注释质量：** 配置文件的区块注释充分解释了设计意图（TTL 分级策略、连接池利用率计算公式、队列分组用途）。Service 层注释简洁说明了"为什么"而非"做了什么"。

**重复代码：** `resolvePlanFromTenant()` 中三处 `Cache::remember()` 调用结构相似，但 TTL 和 key 逻辑各不相同，强行抽象反而降低可读性。可接受。

**小问题：**
- `LoadTest.php:59` 的 `assertSame($count, (int) DB::table('tenants')->count() - 1)` 依赖 setUp 创建的基础租户，硬编码 `-1` 脆弱——如果 setUp 中租户创建失败，断言会误报而非报错。建议改为先记录 count 再断言差值。
- `config/cache.php` 新增了 `metrics` TTL key，但 `CacheService::getTtlConfig()` 的合并逻辑是否覆盖了这个 key 需要确认（`getTtlConfig` 只列了 `user_profile`/`tenant_config`/`permissions`/`api_response`/`default` 五个默认 key，`metrics` 会通过 `config('cache.ttl')` 合并进入，功能上没问题，但 `getTtlConfig()` 的文档/默认值列表可能需要同步更新）。

## Type Safety

所有 Service 方法已有完整的参数和返回值类型声明。配置值通过 `(int)` / `(bool)` 显式强转，避免了 `env()` 返回 `string|null` 的类型陷阱。`block_for` 的条件转换逻辑正确处理了 `null` 与 `0` 的区别。

`SubscriptionService::resolvePlanFromTenant()` 返回 `?SubscriptionPlan`，调用方 `notifySubscriptionExpiring()` 和 `autoRenew()` 都做了 null 检查。**无类型安全问题。**

## Security

- **SQL 注入：** 所有查询使用 Query Builder 参数绑定，无原始拼接。`TenantService::getFinancials()` 使用 `whereIn` 而非字符串拼接。安全。
- **敏感数据暴露：** `config/cache.php` 的 `prefix` 使用 `Str::slug(env('APP_NAME'))` 而非暴露 APP_KEY。`Webhook` 模型的 `secret` 字段在 `hidden` 中。安全。
- **缓存键注入：** `resolvePlanFromTenant()` 的缓存键来自 `$tenant->subscription_plan_id`（int）和 `$tenant->subscription_plan`（string）。`subscription_plan` 来自数据库，理论上可信，但如果被恶意设置为含特殊字符的值（如 `../../../`），可能造成缓存键冲突。风险极低（需要数据库写入权限），但建议对 name 做 `str_replace(['{', '}', '\\', '/'], '', $name)` 清洗。
- **队列失败驱动变更：** `QUEUE_FAILED_DRIVER` 默认从 `database` 改为 `database-uuids`，这是更优选择（支持 UUID 关联），但需确认 `failed_jobs` 表 schema 包含 `uuid` 列。如果迁移未到位，生产会报错。
- **PDO 持久连接：** `PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false)` 默认关闭，合理。但持久连接在 PHP-FPM 下有连接状态泄漏风险（如 charset、SQL mode 被前一个请求修改），`MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4...'` 部分缓解了 charset 问题，但 SQL mode 未重置。

**整体安全评估：无阻塞性问题。**

## Performance

**正面优化：**
- `SessionService::revokeAllSessions()` 和 `purgeExpiredSessions()` 仅 select 必要列，减少 hydrate 开销。合理。
- `PerformanceService::getSlowRequests()` 仅 select `id/action/context/created_at`，避免全列扫描。合理。
- `TenantService::getFinancials()` 从多条 `sum()` 聚合查询改为单次查询 + PHP `Collection::sum()`，在记录数不大的场景下是净正收益。
- `SubscriptionService::resolvePlanFromTenant()` 缓存订阅计划，避免每次调用都查 DB。合理。

**潜在问题：**
- `TenantService::getFinancials()` 的优化假设 `financial_records` 表中每租户记录数有限。如果某租户有数万条财务记录，单次全量 `get()` 会占用大量内存。原方案的 `sum()` 在 DB 侧完成，不加载行数据。这是一个 tradeoff——大多数场景下新方案更优，但极端场景下可能退化。建议保留 `whereIn` 过滤但考虑对大租户使用 `select('type', DB::raw('SUM(amount) as total'))->groupBy('type')` 替代全量加载。
- `SubscriptionService::resolvePlanFromTenant()` 使用全局缓存键 `"plan:{$id}"`，未加租户前缀。如果多租户共享同一 Redis，不同租户的同 ID 计划不会冲突（因为 `subscription_plan_id` 是全局唯一的），但如果计划被更新，缓存不会自动失效。需要确认是否有计划变更时清除缓存的逻辑。

**无 N+1 问题，eager-load 配置正确。**

## Potential Bugs

1. **`LoadTest::test_bulk_tenant_creation_stability()` 第 59 行：** `$this->assertSame($count, (int) DB::table('tenants')->count() - 1)` — 这里假设 setUp 已创建恰好 1 条基础租户。但 `assertSame` 是严格类型比较，`(int) DB::table('tenants')->count()` 返回 int，`$count` 是 int（1000），`-1` 后也是 int。类型没问题，但逻辑脆弱。如果其他测试在同一事务中插入数据（虽然 Orchestra Testbench 默认回滚），计数可能偏移。

2. **`SubscriptionService::resolvePlanFromTenant()` 缓存一致性：** 当管理员通过后台更新订阅计划（如修改价格、功能限制）时，缓存中的旧计划对象会继续被使用，直到 TTL 过期（默认 30 分钟）。`SubscriptionService` 中的 `subscribe()`、`upgrade()`、`downgrade()` 方法是否在变更后清除了对应缓存？如果没有，用户升级后可能仍看到旧计划的配额。**这是一个功能性 bug 风险。**

3. **`config/queue.php` 的 `block_for` 转换：** `env('REDIS_QUEUE_BLOCK_FOR', null) !== null ? (int) env('REDIS_QUEUE_BLOCK_FOR') : null` — 当 `.env` 中设置 `REDIS_QUEUE_BLOCK_FOR=0` 时，`env()` 返回字符串 `"0"`，`"0" !== null` 为 true，`(int) "0"` 为 `0`。这是正确行为（`block_for=0` 表示不阻塞）。但如果用户期望"不设置"语义，可能误解。注释已说明，可接受。

4. **`PerformanceTest::test_baseline_connection_pool_utilization()` 第 103-108 行：** 这个测试仅验证了 `config()` 读取和简单除法，没有实际测试连接池行为。`$activeConnections = 30` 是硬编码的模拟值，断言 `$activeConnections / 50 < 0.8` 恒成立（`30/50 = 0.6`）。这个测试的价值有限，更像是配置验证而非性能基线测试。

5. **`LoadTest::test_no_n_plus_one_on_webhook_deliveries()` 第 216 行：** 使用 `DB::enableQueryLog()` / `DB::getQueryLog()` 检测查询数。但在 Orchestra Testbench 中，如果其他服务（如 `StructuredLogService`、`AuditService`）在 `getDeliveries()` 执行期间产生了额外查询，计数可能超过 3。断言 `assertLessThanOrEqual(3, $queryCount)` 容忍了这一点，但可能掩盖真正的 N+1（如果预加载失败但恰好只有 3 条记录的话）。测试用 20 条记录是合理的——如果 N+1 存在，查询数会远超 3。

## Verdict

**PASS**

【建议改进】（非阻塞）：

1. **缓存失效机制：** `resolvePlanFromTenant()` 的计划缓存应在 `subscribe()`/`upgrade()`/`downgrade()` 等变更操作后主动清除对应 key，避免最长 30 分钟的脏读窗口。建议在 `SubscriptionService` 的计划变更方法中添加 `Cache::forget("plan:{$planId}")` 和 `Cache::forget("plan:name:{$planName}")`。

2. **`TenantService::getFinancials()` 大租户退化：** 当 `financial_records` 行数较多时，全量 `get()` + PHP `sum()` 的内存开销可能超过原方案的多条 `sum()` 查询。建议对 `whereIn('type', ...)->get()` 改为 `select('type', DB::raw('SUM(amount) as total'))->groupBy('type')->get()`，在 DB 侧完成聚合，兼顾单次查询和内存效率。

3. **`LoadTest::test_bulk_tenant_creation_stability()` 计数断言：** 建议改为先记录 `DB::table('tenants')->count()` 为 `$before`，插入后断言 `DB::table('tenants')->count() === $before + $count`，消除对 setUp 中单条记录的隐式依赖。

4. **`PerformanceTest::test_baseline_connection_pool_utilization()` 实质性有限：** 硬编码 `30/50 < 0.8` 的断言不验证任何真实行为。建议要么移除该测试，要么改为读取实际连接池指标（如通过 `SHOW STATUS LIKE 'Threads_connected'`）。

5. **`config/queue.php` 失败驱动变更：** `database-uuids` 需要 `failed_jobs` 表包含 `uuid` 列。确认迁移已到位，否则生产环境 `queue:failed` 会报错。
