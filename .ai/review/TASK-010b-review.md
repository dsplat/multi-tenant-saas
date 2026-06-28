## Architecture

三个模型职责清晰，边界合理：
- `AiProvider` — 租户级 / 系统级 AI 提供商配置，`tenant_id` nullable 实现双层覆盖（系统默认 + 租户覆盖），设计得当。
- `AiRequest` — 请求日志/审计表，独立于 Provider 存储，便于归档和分析。
- `AiModelAlias` — 全局模型别名映射，不挂 `BelongsToTenant`，正确——模型别名是平台级配置，不应租户隔离。

迁移索引设计合理：`ai_requests` 的复合索引 `(tenant_id, created_at)`, `(tenant_id, model)`, `(tenant_id, provider)` 覆盖了按租户聚合查询的典型场景。`ai_providers` 的 `(tenant_id, code)` 唯一约束也正确。

**小问题：** `AiProvider` 与 `AiRequest` 之间缺少 Eloquent relationship（如 `$provider->requests()` 或 `$request->provider()`）。当前 `AiRequest.provider` 是 string 字段（存 code），没有外键关联，这在日志表中是合理设计（避免级联删除影响历史记录），但缺少便利的关系方法可能是后续任务的工作。

---

## Code Quality

- **命名规范：** PSR-12 合规，属性/方法命名清晰，常量命名统一（`STATUS_*`, `TYPE_*`）。
- **可读性：** 每个模型都添加了类级别 PHPDoc 说明用途，关键逻辑（如 `api_key` 加密/解密）有内联注释。
- **重复代码：** `AiProvider` 和 `AiModelAlias` 都有 `STATUS_ACTIVE` / `is_active` 概念，但语义不同（一个是字符串状态字段，一个是布尔字段），可以接受。`AiRequest` 的 `markAsSuccess()` / `markAsFailed()` 封装了状态转换，简洁实用。
- **复杂度：** 整体低，每个模型文件 ~80-120 行，职责单一。

---

## Type Safety

- 所有 cast 声明完整，`cost` 使用 `decimal:6` 精度合适。
- `AiProvider::decryptApiKey()` 返回 `?string`，在解密失败时返回 null 并记录日志，正确。
- `AiModelAlias::toModelEnum()` 返回 `?AiModelEnum`，处理了自定义模型不在枚举中的情况。
- **潜在问题：** `AiRequest::$fillable` 中包含 `cost`，但 `cost` 的 cast 是 `decimal:6`。Eloquent 的 decimal cast 在赋值时会做字符串转换，但如果传入 float 可能有精度问题。建议文档中注明应传入 string 类型的金额值。

---

## Security

- ✅ **敏感数据保护：** `api_key` 使用 `Crypt::encryptString` / `decryptString` 加密存储，这是正确的做法。解密失败时降级返回 null 而非暴露错误详情。
- ✅ **无 SQL 注入风险：** 全部使用 Eloquent ORM，无原生查询。
- ✅ **无 XSS 风险：** 纯模型层，不涉及输出渲染。
- ⚠️ **api_key 在 fillable 中：** `AiProvider::$fillable` 包含 `api_key`，意味着可通过 `create()` / `fill()` 批量赋值。虽然有 mutator 加密，但如果上层未做输入校验，可能被注入恶意内容。这是 Laravel 的常见模式，风险低，但建议在 Service 层（后续任务）做长度/格式校验。
- ✅ `AiRequest` 的 `prompt_summary` 是摘要字段而非完整 prompt，避免了敏感数据暴露。

---

## Performance

- ✅ 迁移索引设计合理，覆盖了主要查询路径。
- ✅ `AiRequest` 的 `response_time_ms`、`input_tokens`、`output_tokens` 为 unsigned integer，适合聚合查询。
- ✅ `AiProvider` 的 `priority` 为 smallInteger，足够且节省空间。
- **潜在 N+1：** 如果后续查询 `AiProvider` 时需要关联 `AiModelAlias`，但当前没有这种关系定义，暂无问题。
- **无内存泄漏风险：** 无循环引用、无静态属性存储。

---

## Potential Bugs

- ⚠️ **`AiProvider` 加密与 `$casts` 冲突：** `api_key` 不在 `$casts` 中，但通过 mutator（`setApiKeyAttribute` / `getApiKeyAttribute`）处理。这是正确的模式——mutator 和 cast 不能同时作用于同一字段。但如果未来有人误加 `'api_key' => 'string'` 到 casts 中，mutator 会被绕过，数据将明文存储。建议在 mutator 上方加注释说明。
- ⚠️ **`AiRequest.markAsFailed()` 不保存：** `markAsFailed()` 只设置属性，不调用 `save()`。调用者需要手动 `save()`。与 `markAsSuccess()` 行为一致（也是只设属性），所以这是有意设计，但容易被误用——调用者可能期望它自动持久化。
- ✅ 边界条件处理良好：nullable 字段、默认值都已覆盖。

---

## Verdict

**PASS**

【建议改进】（非阻塞）：

1. **`AiProvider::$api_key` mutator 加注释：** 在 `setApiKeyAttribute` / `getApiKeyAttribute` 方法上方明确标注"不要将 api_key 加入 $casts，否则 mutator 会被覆盖导致明文存储"。
2. **`AiRequest::markAsSuccess()` / `markAsFailed()` 考虑提供自动保存的便捷方法：** 例如 `markAsSuccessAndSave()` 或在方法内加可选参数 `$persist = false`，减少调用方忘记 `save()` 的风险。
3. **`AiRequest.cost` 精度文档：** 在 PHPDoc 中注明 cost 字段应传入 string 类型以避免 float 精度问题。
4. **缺少 `$primaryKeyType` 声明：** 三个模型都使用非标准主键名（`provider_id`, `request_id`, `alias_id`），应在模型中显式声明 `protected $keyType = 'string'` 或确认它们是 `int` 类型（当前是 unsignedBigInteger，所以默认 int 没问题，但显式声明更清晰）。
