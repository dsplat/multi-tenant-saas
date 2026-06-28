# TASK-008b: [Auto-split from TASK-008]


**目标:** 实现 MailTemplateService 业务逻辑层，包含 CRUD、变量替换、默认模板与租户覆盖、以及 6 个预置系统模板

**只允许修改:**
- `src/Services/MailTemplateService.php`（新建）
- `lang/zh_CN/notification.php`（追加邮件模板相关翻译 key）
- `lang/en/notification.php`（追加邮件模板相关翻译 key）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 2 小时

**依赖:** TASK-008a

**具体交付物:**
1. **MailTemplateService：**
   - 注册为 TenancyServiceProvider 中的 singleton
   - `create(array $data): MailTemplate` — 创建模板
   - `get(int $id): MailTemplate` — 获取单个模板
   - `update(int $id, array $data): MailTemplate` — 更新模板
   - `delete(int $id): bool` — 软删除模板
   - `findTemplate(string $type, ?int $tenantId = null): ?MailTemplate` — 查找模板，优先返回租户自定义，fallback 到系统默认（`tenant_id IS NULL`）
   - `render(string $type, array $data, ?int $tenantId = null): array` — 查找模板并执行变量替换，返回 `['subject' => ..., 'html' => ..., 'text' => ...]`
   - `replaceVariables(string $content, array $data): string` — 用正则 `{{(\w+)}}` 匹配，从 `$data` 数组取值替换
   - `toggleStatus(int $id, string $status): MailTemplate` — 切换激活/停用
   - `seedDefaultTemplates(): void` — 插入 6 个系统默认模板（`tenant_id = null`）
   - 6 个预置模板内容需包含合理的 HTML 结构和 `{{var}}` 占位符

2. **Lang 追加：**
   - `lang/zh_CN/notification.php` 追加 `mail_templates` 段，包含模板名称、类型的中文翻译
   - `lang/en/notification.php` 追加对应英文翻译

3. **TenancyServiceProvider 注册：**
   - 在 `register()` 方法中追加 `$this->app->singleton(MailTemplateService::class)`（注意：此修改在 TASK-008a 的 config 文件之外，但 TenancyServiceProvider 不在允许修改列表中...）

   ⚠️ **注意：** `TenancyServiceProvider.php` 不在 TASK-008 的允许修改范围内。Service 的 singleton 注册需要在测试中通过 `defineEnvironment()` 或直接 `new` 来绕过。测试子任务中处理此问题。

---



## 状态
READY
