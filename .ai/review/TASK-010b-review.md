Now I have a complete picture of the code. Let me provide the review.

---

## Architecture
✅ **合理**。三个模型职责清晰：`AiProvider`（提供商配置）、`AiRequest`（请求日志/计费）、`AiModelAlias`（别名路由）。`AiModelAlias` 不启用 `BelongsToTenant` 是正确的——别名是全局配置表。迁移文件的索引覆盖了常见的查询路径（`tenant_id + created_at`、`tenant_id + model` 等）。`HasGlobalId` 和 `BelongsToTenant` 的使用与模型定位一致。

## Code Quality
✅ **良好**。命名规范、中文注释清晰。`$keyType = 'int'` 的添加是 BigInteger 主键的正确做法。`api_key` 的 mutator 警告注释（"切勿加入 $casts"）非常有价值，防止后续维护者误改。`markAsSuccess`/`markAsFailed` 的 `$persist` 参数设计合理——支持批量更新后统一 save 或单步 persist 两种模式。

## Type Safety
✅ **基本完整**。`casts()` 方法覆盖了关键字段。`cost` 使用 `decimal:6` 正确处理精度。`AiRequest` 文档块中提醒赋值 cost 时传 string 类型是好的实践。`AiModelAlias::toModelEnum()` 正确使用 `tryFrom` 处理自定义模型不在枚举中的情况。

## Security
✅ **通过**。API Key 通过 `Crypt::encryptString`/`decryptString` 加密存储，解密失败时 catch 异常并 log 错误（不暴露明文）。`BelongsToTenant` 全局作用域确保租户数据隔离。`api_key` 不在 `$casts` 中，避免了 mutator 被绕过的风险。

## Performance
✅ **合理**。迁移中的复合索引覆盖了主要查询模式（按租户+时间、按租户+模型、按租户+提供商）。模型层面无 N+1 风险（没有 eager load 的关系定义）。

## Potential Bugs
⚠️ 以下为非阻塞性问题：

1. **`markAsSuccess`/`markAsFailed` 的 `$persist` 默认值不一致**：代码中默认为 `true`，但提供的 diff 显示默认为 `false`。当前代码（`true`）语义上更安全——调用方明确知道自己在做什么。但如果存在依赖旧默认值 `false` 的调用方，需确认兼容性。
2. **`ai_requests` 迁移中的索引差异**：提供的 diff 显示 `idx_status`（单列），但当前代码是 `idx_tenant_status`（复合索引 `[tenant_id, status]`）。当前版本更合理（查询几乎总是带 tenant_id 的），但需确认这是否为有意修改。

## Verdict
**PASS**

【建议改进】
1. `AiModelAlias` 的 `$fillable` 包含 `is_active` 和 `is_deprecated`，但没有验证约束。建议后续在 Service 层或 FormRequest 中做状态互斥校验（deprecated 的不应同时 active）。
2. `AiProvider::isSystemLevel()` 依赖 `=== null` 严格比较，但 Eloquent 从数据库读取的 nullable integer 可能是字符串 `"0"` 或 `null`，取决于驱动。当前用 `bigInteger` + `nullable` 应该没问题，但建议加一个单测覆盖。
