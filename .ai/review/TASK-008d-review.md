All 13 tests pass. Now I have a complete picture of the code. Here's my review:

---

## Architecture

**评价：优秀**

- `TestCase.php` 的 schema 定义与 Migration `2026_06_27_000013_create_mail_templates_table.php` 完全一致，字段、类型、索引、外键均对齐。
- 测试文件职责边界清晰：只测试 `MailTemplateService`、`MailTemplate` Model 和 `TenantMail` Mailable 的公开 API，不依赖内部实现。
- 测试结构按维度组织（CRUD → 变量替换 → 租户覆盖/回退 → 类型过滤 → 状态切换 → 播种 → TenantMail 渲染 → 附件 → 隔离），逻辑递进合理。
- `seedDefaultTemplates()` 在 setUp 中调用，利用 `TenantContext::clear()` 确保系统默认模板 `tenant_id = null`，与生产逻辑一致。
- MailTemplate 的 `bootBelongsToTenant()` 覆写了 `BelongsToTenant` 的默认 TenantScope，用自定义 `mailTemplateTenant` scope 替代，隔离机制自洽。

**无问题。**

## Code Quality

**评价：优秀**

- 命名规范：测试方法名 `test_xxx` 风格统一，语义明确，符合任务要求的 13 个用例全覆盖。
- 可读性：每个测试方法聚焦单一职责，注释恰到好处（中文注释与项目风格一致）。
- 无重复代码：公共 setUp 提取了租户创建和模板播种，避免了每个测试方法的重复初始化。
- 复杂度低：最长的测试方法 `test_seed_default_templates` 约 30 行，逻辑清晰。

**无问题。**

## Type Safety

**评价：良好**

- `TenantContext::setTenantId()` 接受 `?string`，测试中传 `'1001'`（字符串），类型正确。
- `MailTemplateService::create()` 的 `tenant_id` 在 Model `creating` 事件中由 `TenantContext::getId()` 填充为字符串 `'1001'`，而数据库列是 `bigInteger`，PHP 的松散类型转换在此场景下是安全的。
- `variables` 字段通过 `casts(): array` 转为 `array` 类型，`test_create_template` 中 `$this->assertSame(['user_name'], $template->variables)` 验证了这一点。

**建议改进（非阻塞）：**
- `test_create_template` 中 `$this->assertEquals(1001, $template->tenant_id)` 使用了 `assertEquals`（松散比较），而其他地方使用 `assertSame`。Model 的 `tenant_id` 从数据库读出后是字符串 `'1001'`，`assertEquals(1001, '1001')` 通过是因为松散比较。建议统一为 `$this->assertSame('1001', (string) $template->tenant_id)` 或保持 `assertEquals` 但加注释说明意图。

## Security

**评价：优秀**

- **租户隔离**：`test_tenant_scope_isolation` 完整验证了租户 A 无法看到租户 B 的模板，同时能看到系统默认模板。隔离逻辑通过 `mailTemplateTenant` 全局作用域实现。
- **XSS 防护**：`test_variable_replacement` 验证了 `escape = true` 时 HTML 特殊字符被转义（`<b>Alice</b>` → `&lt;b&gt;Alice&lt;/b&gt;`）。
- **SQL 注入**：全部使用 Eloquent ORM，无原始 SQL 拼接。
- **敏感数据暴露**：测试中无密码、token 等敏感信息硬编码。

**无问题。**

## Performance

**评价：优秀**

- 测试使用 SQLite 内存数据库，无 I/O 开销。
- 无 N+1 查询问题：测试方法中每次操作都是单次查询或创建，不涉及循环内的数据库调用。
- `TenantMail` 的 `rendered` 缓存机制避免了 `envelope()`/`content()` 的重复模板查询。

**无问题。**

## Potential Bugs

**评价：良好，有 1 个值得关注的点**

1. **`test_seed_default_templates` 中的 `DB::table('mail_templates')->delete()` 绕过了 Model 层**：直接用 Query Builder 删除，不触发 Eloquent 事件，不走软删除。这在测试中是可以接受的（因为是清空表后重新播种），但如果将来 Model 层添加了删除事件监听器（如清理关联缓存），此测试可能不会覆盖到。当前无实际影响。

2. **`test_find_template_fallback_to_default` 依赖 `password_reset` 模板的 `name_key`**：断言 `$this->assertSame('password_reset', $found->name_key)` 假设 `findTemplate('reset', 1001)` 返回的系统默认模板的 `name_key` 恰好是 `'password_reset'`。这在当前数据集下是正确的（`reset` 类型只有一个系统默认模板），但如果未来添加更多 `reset` 类型的系统默认模板，此测试可能因 `orderBy('template_id')` 的顺序而失败。这是脆弱测试（fragile test）的风险，非当前 bug。

3. **`test_tenant_mail_with_attachments` 的 `file_put_contents` 无权限检查**：在 CI 环境中 `sys_get_temp_dir()` 通常可写，但如果 `/tmp` 不可用会导致测试失败。当前无实际影响。

**无阻塞性 bug。**

## Verdict

**PASS**

所有 13 个测试通过（54 assertions），TestCase schema 与 Migration 一致，测试覆盖了任务要求的全部场景。

**【建议改进】（非阻塞）：**

1. `test_create_template` 中 `$this->assertEquals(1001, $template->tenant_id)` 的松散比较可改为严格比较，或加注释说明数据库返回字符串与整数的预期比较行为。
2. `test_find_template_fallback_to_default` 断言了具体的 `name_key`，如果未来扩展 `reset` 类型模板可能需要调整。可考虑改为只断言 `type === 'reset'` 和 `tenant_id === null`。
3. `test_tenant_mail_with_attachments` 中临时文件的清理放在 `finally` 块中是正确的，但可考虑使用 PHPUnit 的 `@` 抑制符（当前是 `@unlink`）——这已经是最佳实践，无需改动。
