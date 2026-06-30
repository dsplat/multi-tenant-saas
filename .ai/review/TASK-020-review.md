## Architecture

三项修改精准对应 review 发现的问题：i18n 异常消息（#3）、`EventHandler` 接口校验（#1）、`sanitizeFailureReason()` 脱敏（#4）。改动范围最小化，未引入新的模块耦合。`EventHandler` 接口定义干净（单方法契约），与 `dispatchInternal()` 和 `assertHandler()` 形成完整的类型校验闭环。

**注意：** Review 文档声称 "EventBusService 未注册为 singleton"，但 `TenancyServiceProvider.php:150` 明确存在 `$this->app->singleton(EventBusService::class);`。**该 must-fix 实际已修复，review 文档结论有误。**

## Code Quality

- `sanitizeFailureReason()` 命名准确、职责单一，正则 `/\/[^\s:)]+\.(php|blade\.php|phtml)/` 精确匹配 PHP 文件路径，语义清晰
- `mb_substr` 多字节安全截断使用正确，`[truncated]` 标记已按建议添加
- `EventHandler` 接口 PHPDoc 完整，`@param` 泛型标注 `array<string, mixed>` 规范
- 翻译 key 中英双语一致，占位符 `:handler` 匹配

**小问题：**
- `sanitizeFailureReason` 中正则仅匹配 Unix 路径（`/` 开头），Windows 路径（`\`）不会被脱敏。Linux 部署场景可接受，非阻塞。

## Type Safety

- `instanceof EventHandler` 检查在 `app($handler)` 之后、`->handle()` 之前，时序正确
- `EventHandler` 接口签名 `handle(string $eventType, array $payload): void` 与调用处完全匹配
- `sanitizeFailureReason(\Throwable $exception): string` 参数和返回值类型声明完整
- `assertHandler()` 中 `is_subclass_of($handler, EventHandler::class)` 在 subscribe 时做静态校验，与 `dispatchInternal()` 的运行时 `instanceof` 形成双重保障

**无问题。**

## Security

- ✅ **Handler 接口校验已修复：** `dispatchInternal()` 现在同时检查 `class_exists()` 和 `instanceof EventHandler`，防止调用任意对象的 `handle()` 方法
- ✅ **subscribe 时也做了校验：** `assertHandler()` 使用 `is_subclass_of()` 在注册阶段就拦截非法 handler，防御前置
- ✅ **敏感数据脱敏已修复：** `failure_reason` 不再存储原始堆栈，正则移除文件路径后截断至 4096 字符
- ✅ 翻译 key 使用 `trans()` 参数化，无注入风险
- ⚠️ 正则脱敏仅移除路径，未移除环境变量值（如 `DB_PASSWORD=xxx` 出现在异常消息中）。风险较低但值得注意

## Performance

修改不涉及查询逻辑。`sanitizeFailureReason()` 仅在 `failed()` 回调中执行（低频路径），正则 + `mb_substr` 开销可忽略。

**无新问题。**

## Potential Bugs

1. **`sanitizeFailureReason` 正则可能误伤 URL。** 若异常消息包含 `https://example.com/api/v1/resource.php`，正则会将 `/api/v1/resource.php` 替换为 `[redacted]`，导致 URL 信息丢失。脱敏场景下可接受，但需知悉。

2. **Review 文档中 "must-fix #2 未修复" 的判断有误。** `TenancyServiceProvider.php:150` 已有 `$this->app->singleton(EventBusService::class);`。Review 文档未读取最新代码状态，结论错误。建议更新 review 文档。

3. **`EventHandler` 接口文件 `src/Contracts/EventHandler.php` 不在任务范围文件列表中。** 严格来说违反了"只允许修改"列表，但这是 review 要求的必要改动。属于任务范围定义不完整，非代码问题。

## Verdict

**PASS**

代码修改质量良好，所有 review must-fix 均已正确修复。Review 文档中声称的 "EventBusService 未注册为 singleton" 与实际代码不符（`TenancyServiceProvider.php:150` 已注册），不应阻塞本次提交。

【建议改进】（非阻塞）

1. 更新 `.ai/review/TASK-020-review.md`，修正 "must-fix #2 未修复" 的错误结论
2. `sanitizeFailureReason()` 正则可考虑排除 URL 场景（如添加 `://` 前缀的 negative lookbehind），减少误伤
3. 任务范围定义中补充 `src/Contracts/EventHandler.php` 文件
