## Architecture

变更仅在 `ZhipuProvider` 的三个返回数组中追加 `'object'` 字段，使智谱响应结构与 OpenAI 格式对齐。这是合理的 provider 响应格式统一化，无架构问题。

## Code Quality

三处修改模式完全一致（`'object' => $xxx['object'] ?? null`），风格统一，使用 null coalescing 处理缺失字段，干净简洁。

## Type Safety

`$data['object']` 来自 API 响应的 `array<string, mixed>`，类型本身不确定，但 `?? null` 兜底合理，不会引入类型错误。

## Security

无安全风险。仅向内部响应数组追加只读字段，不涉及用户输入、鉴权或数据持久化。

## Performance

无影响。零开销的数组键赋值。

## Potential Bugs

无。`?? null` 正确处理了 API 响应中 `object` 字段缺失的情况。

## Verdict

**PASS**

此变更是 provider 响应格式统一的增量改进，无阻塞问题。
