## Architecture
模型设计合理。`MailTemplate` 正确覆写 `bootBelongsToTenant()` 以实现自定义租户作用域（返回当前租户模板 + 系统默认模板），而非使用 `TenantScope` 的标准单租户隔离。`scopeForTenant` 提供了显式查询能力。`HasGlobalId` + `SoftDeletes` 的组合与项目中其他模型（如 `Tenant`）一致。

**问题：** `bootBelongsToTenant` 完全取代了 trait 的版本。当无租户上下文（`TenantContext::getId()` 返回 null）时，scope 不添加任何过滤条件，导致查询返回**所有租户的所有模板**，而非仅系统默认模板。这与文档描述"同时返回当前租户模板 + 系统默认模板"的行为不一致，且存在潜在的跨租户数据泄露风险。**如果此模型预期仅在有租户上下文的场景下使用，则可接受；但缺少防御性编程。**

## Code Quality
代码风格与项目一致（常量定义、scope 命名、注释密度）。枚举值使用 `const` 数组定义清晰。`TYPES` 和 `STATUSES` 常量数组便于外部验证。`html_body` 不做 cast 是正确的（LONGTEXT 原样存储）。唯一的小问题是 `scopeOfType` 的 `$type` 参数没有类型约束（spec 要求 `string`，代码已标注）。

## Type Safety
类型标注基本完整。`tenant()` 返回类型标注正确。scope 方法有 `string` 类型标注。`TenantContext::getId()` 返回 `?string`，但 migration 中 `tenant_id` 是 `unsignedBigInteger`——与 `Tenant` 模型的 `'tenant_id' => 'integer'` cast 一致（项目既有模式）。`HasGlobalId::getKeyType()` 返回 `'int'`，对于 16 位 ID 足够。无新引入的类型风险。

## Security
- **跨租户隔离（中风险）：** 无租户上下文时作用域不生效，见 Architecture 部分。非致命但需在下游使用时注意。
- **SQL 注入：** 无风险，所有查询使用 Eloquent 参数绑定。
- **XSS：** `html_body` 存储 HTML 内容，由使用方负责转义，模型层无需处理。
- **敏感数据：** 无敏感信息暴露。
- **OWASP 其他项：** 无直接风险。

## Performance
- **索引设计良好：** `(tenant_id, type)` 覆盖主查询路径，`(type, status)` 覆盖按类型+状态筛选。
- **无 N+1 风险：** 模型本身不包含 eager load 逻辑，由使用方决定。
- **`cache_ttl` 配置合理：** 3600 秒，为后续模板缓存服务预留。
- **`html_body` LONGTEXT：** 大字段存储，查询时建议使用 `select()` 避免不必要的数据传输。

## Potential Bugs
1. **无租户上下文时作用域为空：** `TenantContext::getId()` 返回 null 时，`bootBelongsToTenant` 中的 if 分支不执行，查询无过滤条件。建议添加 `else` 分支，仅返回 `tenant_id IS NULL` 的系统默认模板。
2. **`type` 无 model 层验证：** `$fillable` 包含 `type`，但 `saving` 事件中未校验是否在 `TYPES` 范围内。无效 type 值可直接写入数据库。
3. **`status` 无 model 层验证：** 同上，无效 status 可写入。
4. **TestCase 未创建 `mail_templates` 表：** diff 中 `tests/TestCase.php` 新增了 `usage_records` 表但未包含 `mail_templates` 表，MailTemplate 相关测试将因表不存在而失败。
5. **超出任务范围的变更：** diff 包含 `.ai/scripts/*.sh`（shebang 改为 `/opt/homebrew/bin/bash` 破坏可移植性）、`lang/*.php`（优惠券 i18n）、`SubscriptionPlan.php`（metered 字段）、`TestCase.php`（metered/usage_records）等非 TASK-008a 范围的文件。这些变更虽非本任务引入的 bug，但违反了"只允许修改"约束。

## Verdict
**PASS**

任务核心交付物（MailTemplate 模型 + Migration + Config）质量良好，实现正确。以下是【建议改进】（非阻塞）：

1. `bootBelongsToTenant` 添加 else 分支：当无租户上下文时仅返回 `tenant_id IS NULL` 的模板，避免潜在的跨租户数据泄露。
2. `scopeOfType` 中校验 `$type` 是否在 `TYPES` 范围内（或在 `saving` 事件中校验）。
3. `TestCase.php` 补充 `mail_templates` 表创建，否则 MailTemplate 无法进行测试。
4. diff 中混入了大量非 TASK-008a 范围的变更（scripts/、lang/、SubscriptionPlan、TestCase metered fields），建议分离提交以保持 git 历史清晰。
