## Architecture

契约与 DTO 分层清晰，4 个接口覆盖 spec §4 的完整职责域。`AgentResponse`/`Tool` 作为 DTO 放在 `Services/Agent/Dto/` 下，模块边界合理。`AgentRuntimeContract` 依赖 `AgentResponse` DTO，`ToolRegistryContract` 依赖 `Tool` DTO——均为向下依赖，无循环。整体架构符合 spec §4 定义。

## Code Quality

- **命名规范**：接口名 `*Contract`、DTO 类名均为名词，与项目现有 `AiTextServiceContract`、`AiResponse`、`StreamChunk` 一致。
- **可读性**：PHPDoc 注释完整、参数说明详尽，`@param` 带结构化 type hint（`{...}`），可读性好。
- **风格一致性**：DTO 采用 `final class` + `readonly` 构造函数属性 + `fromArray()` 工厂方法，与 `AiResponse`、`StreamChunk` 模式完全一致。接口 PHPDoc 风格与 `AiTextServiceContract` 对齐。
- **`Tool::toArray()`**：其他 DTO（`AiResponse`、`StreamChunk`）没有 `toArray()`，这是额外增加的方法。不矛盾但不一致——属于合理扩展，非问题。

## Type Safety

- 接口方法返回类型和参数类型标注完整，包括 `Generator`、`Collection`、`?Tool`、`mixed`。
- `AgentResponse` 的 `fromArray()` 内有显式 `(string)`、`(array)` 强制转型，防御性合理。
- `Tool::fromArray()` 同理，所有字段有类型强制。
- `getTokenUsage()` / `getPerformanceMetrics()` 返回 `array`，无法进一步约束内部结构——这是 PHP 接口的常见限制，`@return array {...}` PHPDoc 已尽力描述形状。

## Security

接口和 DTO 为纯定义层，无 SQL、无输出渲染、无认证逻辑，无安全风险。`ToolRegistryContract::execute()` 接收 `$tenantId` 参数用于租户隔离，设计正确。`Tool::$handlerClass` 为 FQCN 字符串——安全性取决于实现层的类名白名单校验，接口层无法约束，这属于合理关注点但非本层问题。

## Performance

接口和 DTO 无运行时开销。`AgentResponse::fromArray()` 和 `Tool::fromArray()` 为轻量构造，无性能问题。

## Potential Bugs

- **`Tool::slug` 默认值风险**：构造函数无默认值（`string $slug`），但 `fromArray()` 中 `(string) ($data['slug'] ?? '')` 允许空字符串。如果实现层传入缺少 `slug` 的数组，会静默生成一个空 slug 的 Tool 对象，可能在下游注册表中产生静默冲突。不过这属于实现层的防御范畴，DTO 层可以接受。
- **`AgentResponse::isComplete()`**：仅判断 `finishReason === 'stop'`，但 `finishReason` 为空字符串时（如默认构造）也返回 `false`——逻辑正确，空对象不算 complete。

## Verdict

**PASS**

【建议改进】（非阻塞）：

1. **`Tool` 构造函数应标记 `$slug` / `$name` 为 required**：当前 `fromArray()` 对缺失 key 使用空字符串兜底，但如果 slug/name 真为空，下游注册表会出问题。可在 `fromArray()` 中对必填字段抛 `\InvalidArgumentException`，与 `AiResponse` 的宽松风格不同——Tool 是注册实体，更严格的校验更合理。
2. **`AgentResponse` 和 `AiResponse` 功能高度重叠**：两者都封装 `content`/`toolCalls`/`finishReason`/`usage`，字段映射几乎相同。未来可考虑 AgentResponse 内部组合 AiResponse 而非重复定义，但当前阶段独立 DTO 降低耦合，可接受。