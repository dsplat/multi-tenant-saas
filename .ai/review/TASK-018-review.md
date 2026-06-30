## Architecture

服务分层清晰，三个 Service 职责边界明确：GdprService（导出/擦除）、RetentionService（保留策略/清理）、ConsentService（同意管理），符合单一职责原则。

模型设计合理：Consent 故意不使用租户隔离（注释说明了理由——用户级合规数据），DataRetentionPolicy 支持系统级（`tenant_id=null`）和租户级策略并有回退逻辑。

GdprService 使用原始 DB 查询绕过模型事件/Observer 进行擦除，在 GDPR 擦除场景下是正确选择。

`config/tenancy.php` 的 `gdpr.export_types` 配置与 `GdprService::exportUserData()` 的动态方法调用机制配合良好——配置驱动导出类型，方法存在性检查作为安全网。

**不足：** 配置中 `export_types` 包含 `sso_providers`，但 GdprService 中无 `exportSsoProviders()` 方法，该类型会被静默跳过，无日志警告。

## Code Quality

PSR-12 规范遵守良好，PHPDoc 完整，中文注释清晰。命名遵循 Laravel 惯例。

13 个 `export*` 方法结构重复但各自逻辑简单，提取抽象反而降低可读性，当前可接受。

`exportUserData()` 使用 `str_replace('_', '', ucwords($type, '_'))` 动态转换方法名，设计巧妙但存在大小写陷阱（见 Potential Bugs）。

`lang/en/tenant.php` 新增了与 `lang/zh_CN/tenant.php` 一致的 14 个翻译 key，中英对齐无缺失。

**不足：** 翻译 key `gdpr_processing_activity_logged`、`consent_granted`、`consent_revoked`、`consent_needs_acceptance` 在 Service 代码中均未使用，属死翻译。

## Type Safety

类型标注整体完整，方法参数和返回值均有类型声明。PHPDoc 泛型标注使用得当。

**不足：**
- `RetentionService::buildExpiredQuery()` 返回类型使用了完整类名 `\Illuminate\Database\Query\Builder` 而非 import 的短名，可读性略差。
- `ConsentService::grantConsent()` 中 `$tenantId ?? TenantContext::getId()` 混合了 `int|null` 和 `string` 类型。MySQL 隐式转换使其在实践中工作，但类型不严谨。

## Security

- 敏感字段过滤正确：`$sensitiveFields` 覆盖了 password、token、secret 等关键字段
- `eraseUser` 匿名化策略合理：name→`[erased]`、email→`erased_{id}@deleted.local`、phone→null、password→随机 64 字符
- API 令牌和会话记录物理删除，同意记录标记撤回，符合 GDPR 要求
- `recordProcessingActivity` 记录 IP 和 User-Agent 用于审计追踪
- SQL 全部使用参数化查询，无注入风险
- 擦除操作在事务内执行，保证原子性
- `tokenable_type` 使用精确的 `\MultiTenantSaas\Models\User::class` 匹配（已修复早期 review 中 `like '%User%'` 的问题）

**无 OWASP Top 10 高危问题。**

## Performance

- `GdprService::exportUserData()` 对 13 个关联表各执行独立查询，但通过 `method_exists()` 跳过未实现的类型，实际查询数可控。
- `RetentionService::cleanupExpiredData()` 按策略逐条执行，策略数量少（通常 <10），可接受。
- `ConsentService::getConsentStatus()` 使用 `selectRaw` + `groupBy` 聚合查询，避免了 N+1。

**无明显性能问题。**

## Potential Bugs

**1. `mfa_devices` 导出因方法名大小写不匹配而静默失败（高）**

`GdprService::exportUserData()` 中，`'mfa_devices'` 经过 `str_replace('_', '', ucwords('mfa_devices', '_'))` 转换后得到 `'MfaDevices'`，因此调用 `exportMfaDevices()`。但实际方法名是 `exportMfaDevices()`（小写 `f`）。

```
ucwords('mfa_devices', '_')  → 'Mfa_Devices'
str_replace('_', '', ...)    → 'MfaDevices'
拼接 'export'               → 'exportMfaDevices'
实际方法名                    → 'exportMfaDevices'  ← 不匹配
```

`method_exists()` 返回 false，MFA 设备数据将从导出中**静默丢失**，用户拿到的导出文件缺少 MFA 设备信息，且无任何错误提示。

**修复：** 将方法名改为 `exportMfaDevices()`，或将 config 中 `mfa_devices` 改为 `mfadevices`。

**2. `acceptTerms()` 允许空 IP 和 User-Agent（中）**

`ConsentService::acceptTerms()` 的 `$ip` 和 `$userAgent` 参数默认值为空字符串。同意记录具有法律效力，空 IP/UA 会削弱审计追踪的法律价值。应至少记录为 `request()?->ip()` 或抛出异常要求必须提供。

**3. 配置与实现不一致——`sso_providers` 无对应导出方法（低）**

`config/tenancy.php` 的 `export_types` 包含 `'sso_providers'`，但 GdprService 中无 `exportSsoProviders()` 方法。该类型被静默跳过，无日志。如果 SSO 数据确实需要导出，应补全方法；否则应从配置中移除。

**4. 翻译 key 未使用（低）**

`gdpr_processing_activity_logged`、`consent_granted`、`consent_revoked`、`consent_needs_acceptance` 在 Service 代码中未被引用。

## Verdict

**PASS**（附建议改进）

之前的 review 文件声称 TestCase 中 `data_retention_policies` 缺少 unique 约束，经核实这是**错误的**——TestCase 第 849 行 `$table->unique(['tenant_id', 'data_type'], 'uniq_retention_tenant_type')` 与迁移完全一致。

【建议改进】（非阻塞）

1. **`exportMfaDevices` 方法名大小写问题** — 虽然当前 config 中恰好没有 `mfa_devices` 条目（config 的 export_types 列表中无此项），但 `$sensitiveFields` 中定义了 `mfa_devices` 的敏感字段过滤。如果将来在 config 中添加 `mfa_devices`，导出将静默失败。建议统一方法命名为 `exportMfaDevices()` 以保持一致性。
2. **`acceptTerms()` 的 `$ip`/`$userAgent` 默认值** — 建议改为从 `request()` 自动获取或移除默认值强制传入。
3. **配置中 `sso_providers` 无对应实现** — 补全 `exportSsoProviders()` 方法或从配置中移除。
4. **未使用的翻译 key** — 清理或在 Service 中补全对应的 `trans()` 调用。
