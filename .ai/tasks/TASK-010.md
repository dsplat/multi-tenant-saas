# TASK-010: 全局ID规范强制合规

**Sprint:** sprint-002  
**状态:** READY  
**依赖:** TASK-009  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

修复所有违反全局ID生成器规范的模型和迁移文件，确保所有主键使用 `IdGenerator` 生成的 16 位整数 ID，并创建 git pre-commit hook 防止未来违规。

---

## 背景

项目使用 `HasGlobalId` trait 为所有模型自动生成 16 位整数 ID（范围 1000000000000000 ~ 9007199254740991，JavaScript 安全）。但部分早期代码和迁移文件未遵循此规范：

### 违规清单

**模型级违规（3 个）：**

| 模型 | 问题 | 应改为 |
|------|------|--------|
| `PaymentOrder` | `primaryKey = 'id'` | `payment_order_id` |
| `UserApiToken` | `primaryKey = 'id'`，无 `HasGlobalId` | `user_api_token_id` + HasGlobalId |
| `UserApiTokenHistory` | `primaryKey = 'id'`，无 `HasGlobalId` | `user_api_token_history_id` + HasGlobalId |

**迁移级违规（14 个文件，约 19 张表）：**

全部使用 `$table->id()`（自增），应改为 `$table->unsignedBigInteger('{model}_id')->primary()`：

| 迁移文件 | 涉及表 |
|----------|--------|
| `create_payment_orders_table` | payment_orders |
| `create_invoices_table` | invoices |
| `create_invoice_items_table` | invoice_items |
| `create_tax_rules_table` | tax_rules |
| `create_rbac_tables` | role_permissions |
| `create_user_preferences_table` | user_preferences |
| `create_alert_tables` | alert_rules, alerts |
| `create_export_tasks_table` | export_tasks |
| `create_plugins_tables` | plugins, plugin_dependencies |
| `create_rate_limit_rules_table` | rate_limit_rules |
| `create_api_versions_table` | api_versions |
| `create_payment_security_tables` | payment_logs, user_payment_passwords |
| `create_user_api_tokens_table` | user_api_tokens, user_api_token_histories |
| `create_email_verification_tokens_table` | email_verification_tokens |

**豁免项：**
- `personal_access_tokens` — Laravel Sanctum 内置表
- `customers` — 示例/演示模型

---

## 范围

**允许修改：**
- `src/Models/PaymentOrder.php`
- `src/Modules/ApiToken/Models/UserApiToken.php`
- `src/Modules/ApiToken/Models/UserApiTokenHistory.php`
- `database/migrations/` 下上述 14 个文件
- `tests/TestCase.php`（测试数据库 schema 同步更新）
- `.git/hooks/pre-commit`（新建）
- `.ai/scripts/check-id-compliance.sh`（新建）

**禁止修改：**
- `src/Models/` 下已合规的模型
- `.ai/scripts/loop-run.sh` 等执行脚本
- `.ai/prompts/` 下所有文件
- `resources/`、`public/`

---

## 具体内容

### 1. 模型修复

**PaymentOrder.php：**
```php
// 修改前
protected $primaryKey = 'id';

// 修改后
use MultiTenantSaas\Concerns\HasGlobalId;
protected $primaryKey = 'payment_order_id';
// 确保 use HasGlobalId;
```

**UserApiToken.php / UserApiTokenHistory.php：**
```php
// 添加
use MultiTenantSaas\Concerns\HasGlobalId;
protected $primaryKey = 'user_api_token_id'; // 或 user_api_token_history_id
// 确保 use HasGlobalId;
```

### 2. 迁移文件修复

每个迁移文件中：
```php
// 修改前
$table->id();

// 修改后
$table->unsignedBigInteger('{model}_id')->primary();
```

### 3. TestCase.php 同步

测试数据库 schema 需要同步更新主键定义。

### 4. pre-commit hook

创建 `.git/hooks/pre-commit`，在每次 commit 前自动检查：
- 新增/修改的模型文件是否包含 `HasGlobalId`
- 新增/修改的迁移文件是否使用 `$table->id()`
- 违规时阻止 commit 并输出具体文件和行号

### 5. 合规检查脚本

创建 `.ai/scripts/check-id-compliance.sh`，可独立运行检查整个项目。

---

## 验收标准

- [ ] 3 个违规模型修复为正确的 `primaryKey` + `HasGlobalId`
- [ ] 14 个迁移文件修复为 `unsignedBigInteger` 主键
- [ ] `tests/TestCase.php` 测试 schema 同步更新
- [ ] `.git/hooks/pre-commit` 正常工作，能阻止违规代码提交
- [ ] `.ai/scripts/check-id-compliance.sh` 可独立运行
- [ ] `php vendor/bin/phpunit` 全量测试通过
- [ ] 手动测试 pre-commit hook：尝试提交违规代码被阻止

---

## 给 AI 的补充说明

- 所有 ID 必须是 16 位整数，由 `IdGenerator` 生成
- 主键命名规范：`{model_name}_id`（如 `payment_order_id`、`user_api_token_id`）
- 外键引用必须使用 `{model}_id` 字段（整数类型），不能用字符串名称
- `HasGlobalId` trait 位于 `src/Concerns/HasGlobalId.php`
- 迁移文件修改时注意保持其他字段和索引不变
- pre-commit hook 使用 bash 脚本，检查 git staged 文件
