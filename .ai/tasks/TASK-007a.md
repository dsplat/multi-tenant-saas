# TASK-007a: [Auto-split from TASK-007]


**目标:** 创建 UsageRecord 模型与迁移，追加 SubscriptionPlan 按量计费字段，更新 TestCase schema

**只允许修改:**
- `src/Models/UsageRecord.php`（新建）
- `database/migrations/2026_06_27_000013_create_usage_records_table.php`（新建）
- `src/Models/SubscriptionPlan.php`（追加 `metered_price`/`metered_unit`/`overage_allowed`/`overage_price`/`rate_limit_rpm` 字段到 fillable 和 casts）
- `tests/TestCase.php`（追加 `usage_records` 表 schema + `subscription_plans` 表追加新列）

**具体工作:**
1. 创建 `UsageRecord` 模型：`use HasGlobalId, BelongsToTenant`，`$primaryKey = 'usage_record_id'`，fillable 含 `tenant_id`/`metric_type`/`value`/`period`/`recorded_at`，casts 含 `value => decimal:4`、`recorded_at => datetime`
2. 创建 `usage_records` 迁移：表含 `usage_record_id`(bigIncrements)、`tenant_id`(unsignedBigInteger)、`metric_type`(string 50)、`value`(decimal 18,4)、`period`(string 7，格式 YYYYMM)、`recorded_at`(timestamp)、`metadata`(json nullable)、timestamps；索引 `[tenant_id, metric_type, period]`
3. SubscriptionPlan 追加 fillable: `metered_price`(json)、`metered_unit`(string)、`overage_allowed`(bool)、`overage_price`(decimal)、`rate_limit_rpm`(integer)；casts 对应类型
4. TestCase 的 `subscription_plans` 表追加：`$table->json('metered_price')->nullable()`、`$table->string('metered_unit', 30)->nullable()`、`$table->boolean('overage_allowed')->default(false)`、`$table->decimal('overage_price', 10, 4)->default(0)`、`$table->unsignedInteger('rate_limit_rpm')->default(60)`；新增 `usage_records` 表（含上述字段）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 1 小时

**依赖:** 无

---



## 状态
READY
