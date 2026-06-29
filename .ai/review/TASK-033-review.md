Let me first find and read the actual implementation files for this review.
## Architecture

**分层合理，职责清晰。** Contract → Service → Driver 三层抽象正确：`AiTextServiceContract` 定义面向调用方的 API，`AiDriverContract` 定义面向后端的 SPI，`AiTextService` 作为中间编排层处理 driver 选择与重试。`AiResponse` DTO 归一化各后端差异。`config/ai.php` 配置驱动可插拔，`MockAiDriver` 支持脚本队列模拟多轮调用，设计意图明确。

**致命问题：`TenancyServiceProvider` 未绑定 `AiTextServiceContract`。** diff 只新增了 `AgentServiceContract` 的 import（来自 TASK-040），但完全没有绑定 `AiTextServiceContract` 单例。调用方无法通过容器 resolve 该契约，整个模块无法使用。违反任务要求"仅 register 追加 `AiTextServiceContract` 单例绑定"。

## Code Quality

- 命名规范：PHP 8.1 readonly properties、snake_case DTO 方法 `fromArray`、camelCase 方法名，符合 PSR-12 与项目既有风格。
- `AiTextService::driver()` 方法未声明返回类型中包含 `?` 但 `$name` 参数可为 null，逻辑正确但注释 `@param string|null` 与签名一致，无问题。
- `OpenAiCompatibleDriver` 的 `chat()` 和 `complete()` 存在重复的 provider 解析 + options 属性提取模式（`resolveProvider` + `array_key_exists` 块 x2），可抽取为 `buildPayload` 基础方法，但当前规模可接受。
- `MockAiDriver::nextResponse()` 中 `$this->callIndex` 在 empty script 和非 empty 路径各递增一次，逻辑正确但 `callIndex` 语义从"当前消费位置"变为"总调用次数"，两种用途混用，测试断言时需注意。

## Type Safety

- `AiDriverContract` 的 `$options` phpdoc 列出了结构但未定义为 shape type，PHPStan/Larastan 无法静态验证 options 内部 key，这是 PHP 生态的已知限制，可接受。
- `AiTextService::driver()` 接受 `?string $name` 但 `chat()`/`complete()` 中 `$options['driver']` 的类型注解为 `AiDriverContract|string`，实际传入 `AiDriverContract` 实例时 `driver()` 方法会收到非 null string 或 `AiDriverContract` 对象，但方法签名只接受 `?string`。**当 `$options['driver']` 传入 `AiDriverContract` 实例时会类型错误。** Contract phpdoc 声明了 `driver?: AiDriverContract|string` 但实现不支持。

## Security

- `OpenAiCompatibleDriver::send()` 将 `$resp->body()` 直接写入 `Log::error` 并拼入 `RuntimeException` 消息。如果后端返回的 body 包含敏感信息（如 API key 轮换提示、内部错误详情），会被记录到日志或传播到上层。建议仅记录 status code，body 在非 debug 环境脱敏。
- `$provider['api_key']` 通过 `withToken()` 传递，不会泄露到 URL 或 payload，正确。
- `$payload` 中的 `$messages` 未经净化即发送到外部 API，但这是正常的 API 消费行为（调用方负责内容安全），无注入风险。
- **无 SQL 注入、XSS 风险**——本模块纯 HTTP 客户端，不涉及数据库或视图渲染。

## Performance

- 重试使用 `usleep()` 阻塞当前进程。在 Laravel Queue Worker 中这是可接受的，但在 HTTP 请求上下文中会阻塞 PHP-FPM worker。当前 `sleep_ms` 默认 200ms，影响有限，但建议未来支持指数退避。
- `AiTextService::driver()` 缓存已实例化的 driver，避免重复创建，良好。
- `OpenAiCompatibleDriver` 每次请求创建新的 `Http` 请求链，无连接池问题。
- `AiResponse::$raw` 存储完整后端响应，在高吞吐场景下可能增加内存占用，但当前非流式场景可接受。

## Potential Bugs

1. **`$options['driver']` 传入 `AiDriverContract` 实例时无法工作。** Contract 声明 `driver?: AiDriverContract|string`，但 `AiTextService::driver()` 只接受 `?string`。传入实例时会静默忽略或产生意外行为。
2. **`AiTextService::driver()` 的 fallback 默认值逻辑不一致。** 当 `config('ai')` 返回空数组且未传 `$name` 时，fallback 到 `'mock'`；但 `$this->config['drivers']` 为空时又硬编码了 `mock` 和 `openai-compatible` 的 class。如果 `config/ai.php` 的 `drivers` 数组中删除了 `mock`，硬编码 fallback 仍会创建它——这可能是有意的也可能是 bug。
3. **`OpenAiCompatibleDriver::parseToolCalls` 中 `json_decode` 失败时静默返回空数组。** 如果 API 返回畸形 JSON arguments，调用方无法感知解析失败，可能导致 Agent 丢失工具调用参数。
4. **`AiResponse::fromArray()` 中 `static` 关键字。** 如果有人继承 `AiResponse` 并调用 `fromArray`，返回的是子类实例。当前 `AiResponse` 是 final-like 设计（无 abstract 方法），但未标记 `final`，存在意外继承风险。
5. **无单元测试。** 任务范围内未要求测试，但 `MockAiDriver` 的脚本消费逻辑（callIndex 边界、`fromArray` 路径）应有测试覆盖。

## Verdict

**FAIL**

【必须修复】：

1. **`TenancyServiceProvider` 必须添加 `AiTextServiceContract` 的单例绑定**——这是任务明确要求的核心交付物，当前完全缺失。应添加：
   - `use MultiTenantSaas\Contracts\AiTextServiceContract;`
   - `$this->app->singleton(AiTextServiceContract::class, AiTextService::class);`
   - 同时移除不属于本任务的 `AgentServiceContract` import（它属于 TASK-040）。

2. **`AiTextService::driver()` 必须支持 `AiDriverContract` 实例直传**——Contract phpdoc 声明 `driver?: AiDriverContract|string`，实现必须匹配。当 `$options['driver']` 为实例时应直接返回，跳过名称解析。