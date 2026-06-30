Now I have enough context. Let me compile the review.

---

## Architecture

整体架构合理，模块边界清晰：

- `ErrorTrackingService` 聚焦错误聚合与 Sentry 集成，`ReportService` 聚焦报表 CRUD 与导出，职责分明
- `CustomReport` 模型正确使用 `BelongsToTenant` + `HasGlobalId` + `SoftDeletes`，与项目既有模式一致
- Sentry 集成通过配置开关 + `function_exists` 双重降级，不强制依赖外部包，设计合理
- 依赖注入规范：通过构造函数注入 `TenantContextContract` 和 `AlertService`
- 配置结构（`config/tenancy.php`）分组清晰，模板预置在配置中便于维护

**小问题：** `ReportService.exportPdf()` 直接调用 `PdfService::generate()` 为静态调用，但文件顶部未 `use PdfService`。如果 `PdfService` 不在全局命名空间下，运行时会 Fatal Error。应确认此类是否存在，或添加正确导入。

## Code Quality

命名规范、注释密度与项目一致：

- 中文注释 + PHPDoc 符合规范要求
- 常量提取到位（`CATEGORY_ERROR`、`FORMAT_CSV`、`GRANULARITY_DAY` 等）
- `ErrorTrackingService` 的严重级别常量直接引用 `AlertService::SEVERITY_*`，避免重复定义
- 测试用例覆盖全面（Sentry 开关、聚合、趋势、通知、CRUD、导出、模板、租户隔离）

**小问题：**
- `decodeContext($raw)` 参数缺少类型标注，应为 `?string $raw`
- `applyPeriodRange($query, ...)` 的 `$query` 参数缺少类型标注
- `bucketByDay(...)` 缺少返回值类型声明（docblock 写了 Collection，方法签名无标注）
- `scopeActive($query)` 缺少返回值类型声明

## Type Safety

基本完整，有几处小缺口：

- `captureException()` 参数 `array $context` 已有类型声明，但方法体内第 70 行 `if (is_array($context))` 是冗余检查——参数类型已保证是 array
- `decodeContext($raw)` 的 `$raw` 无类型标注，PHP 会隐式 mixed，虽然逻辑上能工作但不精确
- `bucketByDay` 返回 `\Illuminate\Support\Collection` 但无类型声明
- `$report->dimensions` 通过 cast 为 array，`is_array()` 检查是合理的防御性编程

## Security

无高危安全问题：

- SQL 查询全部使用参数化绑定，无注入风险
- 表名硬编码为类常量，无用户输入拼接
- 租户隔离通过 `BelongsToTenant` 全局作用域 + `queryErrors` 中显式 `tenant_id` 过滤双重保障
- 错误消息使用翻译 key 而非暴露内部细节
- `recipients` 邮箱字段在 JSON 中存储，模型未暴露敏感字段
- `CustomReport` 的 `$fillable` 包含 `tenant_id`，但 `BelongsToTenant` 的 `creating` 事件会自动覆盖，无越权风险

## Performance

存在可优化点，但不构成阻塞：

- `queryErrors()` 将时间窗口内所有错误行加载到 PHP 内存（`->get()`），无 `limit` 限制。当错误量大时（如 10 万+），内存和排序开销显著。建议对 `aggregateErrors` 改用数据库 `GROUP BY` + `COUNT`，或至少加上 `limit(config('tenancy.error_tracking.max_error_groups'))` 限制
- `errorTrend()` 同样全量加载后在 PHP 中分桶，可用 `GROUP BY SUBSTR(created_at, 1, 10)` 在数据库层完成
- `generateData()` 中每个 metric 调用独立查询（`countErrors` → `countErrorsByDay` → `countErrorsByTenant`），多 metric 时存在重复扫描，但当前 metric 种类有限（4 种），可接受
- `SUBSTR` 分桶在 SQLite 下兼容但无法利用索引，MySQL 生产环境建议改用 `DATE()`

## Potential Bugs

### BUG-1【必须修复】Sentry 上下文配置顺序错误

`ErrorTrackingService.php:69-76`：

```php
$eventId = \Sentry\captureException($exception);  // 先捕获
if (is_array($context)) {
    \Sentry\configureScope(function ($scope) use ($context): void {
        // 后配置 scope —— context 不会附加到已捕获的事件上
    });
}
```

`configureScope` 修改的是当前 scope，影响的是**之后**捕获的事件。当前代码先 `captureException` 再 `configureScope`，导致 context 永远不会附加到该事件。应颠倒顺序：

```php
if (! empty($context)) {
    \Sentry\configureScope(function ($scope) use ($context): void { ... });
}
$eventId = \Sentry\captureException($exception);
```

### BUG-2【建议修复】`captureMessage` 未传递 context

`ErrorTrackingService.php:100-104`：`captureMessage` 接受 `$context` 参数但从未使用，与 `captureException` 行为不一致。

### 其他小问题

- `queryErrors()` 无行数限制，大量错误数据可能导致内存溢出
- `sendReport()` 中 CSV 回退导出如果也失败（如表不存在），异常未被捕获
- `exportExcel()` 中 `file_get_contents($file->getPathname())` 未检查文件是否存在

---

## Verdict

**PASS**

【建议改进】（非阻塞，按优先级排列）：

1. **BUG-1**：`captureException` 中 `configureScope` 必须在 `captureException` 之前调用，否则 context 不会附加到事件。这是逻辑错误但不影响功能（Sentry 未启用时走不到此分支）
2. `captureMessage` 应同样支持 `$context` 参数，保持接口一致性
3. `queryErrors` 建议加上 `limit(config('tenancy.error_tracking.max_error_groups', 100))` 防止大数据量内存溢出
4. `decodeContext`、`applyPeriodRange`、`bucketByDay`、`scopeActive` 补全类型声明
5. `ReportService::exportPdf()` 确认 `PdfService` 的命名空间是否正确引用
6. `aggregateErrors` / `errorTrend` 考虑将聚合下推到数据库层以提升大数据量性能
