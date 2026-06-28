Now I have a complete picture. Here is my review:

---

## Architecture

本次变更包含两部分：修复上一轮 review 指出的问题，以及补充缺失的测试文件。模块边界未变，架构合理性维持原评。

`CheckIpWhitelist::resolveScope()` 的修复精准——移除 `str_contains` 分支，仅保留 `$request->is('admin/*')` 精确匹配路径开头，不引入新依赖、不改变模块结构。`TrustedDeviceServiceTest` 独立新建，遵循项目测试组织规范。

**无新增架构问题。**

## Code Quality

**CheckIpWhitelist.php (line 66):**
修复干净利落，单行删除，无副作用。注释未更新（`resolveScope` 的 PHPDoc 仍写"根据请求解析白名单 scope"），但不影响可读性。

**TrustedDeviceServiceTest.php:**
- 命名规范：测试方法名 `test_verb_noun_expected_behavior`，清晰。
- 结构：按功能分组（指纹生成 → trustDevice → isDeviceTrusted → list → revoke → rename → extend → purge → find），逻辑流畅。
- 辅助方法 `makeRequest()` 封装合理，避免重复代码。
- setUp 创建 Tenant + User 作为测试前置，符合项目惯例。

**小问题：**
- `test_trust_current_device` (line 104) 断言了 `device_name` 和 `ip_address`，但未验证 `user_agent` 存储是否正确。不阻塞，但可加强。

## Type Safety

无新增类型问题。测试文件中变量类型均由 PHPUnit 断言隐式覆盖。

## Security

**`resolveScope()` 修复确认：**
原代码 `str_contains($request->getPathInfo(), '/admin')` 会将 `/api/v1/admin-users` 误判为 `SCOPE_ADMIN`，破坏 scope 隔离。修复后仅 `$request->is('admin/*')` 精确匹配路径段开头，`/api/v1/admin-users` 不再误命中。**安全缺陷已修复。**

原 review 提到的其他安全问题（审计日志仅记录放行、IPv6 不支持）属于建议改进项，不在本轮必须修复范围内。

## Performance

无新增性能问题。测试文件使用 SQLite 内存数据库（TestCase 惯例），执行速度快。

## Potential Bugs

1. **`TrustedDeviceServiceTest` 未测试 `TenantContext` 隔离**：测试在 `setUp()` 中设置了 `TenantContext::setTenantId('1001')`，但未创建第二个租户并验证设备列表不交叉。`TrustedDevice` 不使用 `BelongsToTenant` trait（设计决策），所以实际上 `listDevices()` 按 `user_id` 查询而非 `tenant_id`，隔离靠业务层保证。测试覆盖了单租户场景，跨租户隔离由上层负责。不阻塞。

2. **`test_extend_trust` 依赖 `now()` 精度**：`trustDevice` 用 `now()->addDays(1)` 设置过期时间，`extendTrust` 用 `now()->addDays(60)`。如果两行代码在同一秒执行，`expires_at` 可能相等导致 `greaterThan` 断言失败。实际上 `Carbon::greaterThan` 比较到微秒，且 `addDays(60)` 远大于 `addDays(1)`，**无实际风险**。

## Verdict

**PASS**

本轮修复正确解决了上一轮 review 的两个必须修复项：
- ✅ `resolveScope()` 安全缺陷已修复（移除 `str_contains`，改用 `$request->is('admin/*')`）
- ✅ `TrustedDeviceServiceTest` 已补充，覆盖所有公开方法（17 个测试用例）

【建议改进】（非阻塞）：

1. **IpWhitelistService 缺少返回值类型声明**：`list()` (line 32) 返回 `Collection` 但未标注，违反编码规范"所有方法必须有返回值类型声明"。`TrustedDeviceService` 的 `listDevices()` / `listActiveDevices()` 同样如此。
2. **审计日志仅记录放行**：`isAllowed()` 命中白名单时写 `ip_whitelist.allow`，被拦截时无记录。建议在 `isAllowed()` 返回 false 时增加 `ip_whitelist.deny` 审计。
3. **IPv6 不支持**：`ip2long()` 对 IPv6 返回 false，应在代码或文档中明确标注此限制。
4. **`TrustedDeviceService` 未读取 `config('tenancy.trusted_device_days')`**：配置项已声明但 `DEFAULT_TRUST_DAYS = 30` 硬编码，建议改为从配置读取。
