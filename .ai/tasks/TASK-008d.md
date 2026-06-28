# TASK-008d: [Auto-split from TASK-008]


**目标:** 编写完整的 PHPUnit 测试，覆盖 MailTemplateService CRUD、变量替换、租户覆盖、TenantMail 渲染，并在 TestCase 中补充 mail_templates 表 schema

**只允许修改:**
- `tests/MailTemplateServiceTest.php`（新建）
- `tests/TestCase.php`（追加 `mail_templates` 表 Schema::create）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 1.5 小时

**依赖:** TASK-008a, TASK-008b, TASK-008c

**具体交付物:**
1. **TestCase.php 追加：**
   - 在 `setUp()` 中追加 `Schema::create('mail_templates', ...)` ，字段定义与 Migration 一致
   - 在 `setUp()` 中调用 `MailTemplateService::seedDefaultTemplates()` 插入 6 个预置模板（或在测试中手动创建）

2. **MailTemplateServiceTest 测试用例：**
   - `test_create_template` — 创建模板并验证字段
   - `test_get_template` — 通过 ID 获取模板
   - `test_update_template` — 更新模板字段
   - `test_delete_template` — 软删除模板
   - `test_variable_replacement` — 验证 `{{var}}` 替换逻辑，包括缺失变量的处理
   - `test_find_template_tenant_override` — 租户自定义模板优先于系统默认
   - `test_find_template_fallback_to_default` — 无租户模板时 fallback 到系统默认
   - `test_template_type_filter` — 按类型过滤
   - `test_toggle_status` — 激活/停用切换
   - `test_seed_default_templates` — 验证 6 个预置模板已插入
   - `test_tenant_mail_renders_template` — TenantMail 能正确渲染模板
   - `test_tenant_mail_with_attachments` — 附件功能
   - `test_tenant_scope_isolation` — 租户 A 看不到租户 B 的模板，但能看到系统默认模板

---

## 总览

| 子任务 | 文件数 | 预估时间 | 依赖 | 核心职责 |
|--------|--------|----------|------|----------|
| TASK-008a | 3 | 1.5h | 无 | 数据层：Model + Migration + Config |
| TASK-008b | 3 | 2h | 008a | 业务层：Service + Lang + 预置模板 |
| TASK-008c | 1 | 1.5h | 008b | 发送层：TenantMail Mailable |
| TASK-008d | 2 | 1.5h | 008a/b/c | 测试层：TestCase schema + 全量测试 |

**⚠️ 遗留风险：** `TenancyServiceProvider.php` 不在允许修改范围内，但 Service 需要注册为 singleton。建议在 TASK-008b 中将此文件加入允许列表，或在测试中通过手动实例化绕过。


## 状态
READY
