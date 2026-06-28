# TASK-008a: [Auto-split from TASK-008]


**目标:** 创建 MailTemplate 数据模型、数据库迁移和配置文件，奠定邮件模板系统的数据基础

**只允许修改:**
- `src/Models/MailTemplate.php`（新建）
- `database/migrations/2026_06_27_000013_create_mail_templates_table.php`（新建）
- `config/tenancy.php`（追加 `mail_templates` 配置段）

**禁止:** 修改其他文件、新增依赖

**预估时间:** 1.5 小时

**依赖:** 无

**具体交付物:**
1. **MailTemplate 模型：**
   - 使用 `HasGlobalId` trait，主键 `template_id`
   - 使用 `BelongsToTenant` trait，但需自定义 scope 逻辑：查询时同时返回当前租户模板 + `tenant_id IS NULL` 的系统默认模板
   - `$fillable`: `tenant_id`, `type`, `name`, `subject`, `html_body`, `text_body`, `variables`, `status`
   - `casts()`: `variables` → `array`, `html_body` → 留空（LONGTEXT 不需 cast）
   - `type` 枚举: `billing`, `notification`, `welcome`, `reset`
   - `status` 枚举: `activated`, `disabled`
   - 关系: `tenant()` BelongsTo
   - 自定义 scope: `scopeOfType($query, $type)`, `scopeActivated($query)`, `scopeForTenant($query, $tenantId)` — 后者返回该租户专属模板 + 系统默认模板

2. **Migration：**
   - 表名 `mail_templates`
   - 字段: `template_id` (bigint PK), `tenant_id` (bigint nullable, FK→tenants), `type` (string), `name` (string), `subject` (string), `html_body` (longText), `text_body` (text nullable), `variables` (json nullable), `status` (string default 'activated'), `created_at`, `updated_at`, `deleted_at`
   - 索引: `(tenant_id, type)`, `(type, status)`

3. **Config 追加：**
   - `config/tenancy.php` 追加 `mail_templates` 段：
     ```php
     'mail_templates' => [
         'default_from_address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
         'default_from_name' => env('MAIL_FROM_NAME', 'Tenant SaaS'),
         'cache_ttl' => 3600,
     ],
     ```

---



## 状态
READY
