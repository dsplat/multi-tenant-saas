# Code Review — 已确认问题清单

> 审查日期: 2026-07-30
> 审查范围: 全框架 (src/, app/, routes/, database/, resources/js/)
> 状态标记: [BUG] = 实际缺陷, [SMELL] = 代码异味/设计债务, [TODO] = 未完成项

---

## P0 — 需要修复

### [BUG-001] 支付回调路由同时注册 GET 方法

**文件**: `routes/api.php:14-15`

```php
Route::get('/v1/pay/wechat/notify', [TenantPaymentController::class, 'wechatNotify']);
Route::get('/v1/pay/alipay/notify', [TenantPaymentController::class, 'alipayNotify']);
```

**问题**: 微信/支付宝服务端回调均为 POST 请求。GET 路由暴露了回调端点，可能被浏览器预取、爬虫触发或 CSRF 攻击利用。控制器内无请求方法校验。

**影响**: 安全风险 — 恶意 GET 请求可能触发支付状态变更逻辑。

**建议**: 移除 GET 路由，仅保留 POST。如需兼容微信签名验证（GET challenge），应在控制器内显式区分处理。

---

### [BUG-002] `export_tasks.error` 字段语义不清且丢失错误信息

**文件**: `database/migrations/2025_01_01_000000_framework_core.php:115`, `src/Modules/Infrastructure/Services/ExportService.php:164-165`

```sql
`error` tinyint(1) NOT NULL DEFAULT '0',
```

```php
if ($status === self::STATUS_FAILED) {
    $update['error'] = true;
}
```

**问题**: 字段名 `error` 暗示存储错误信息，实际是布尔标志。任务失败时仅标记 `error=1`，不记录失败原因（异常消息、堆栈等），无法排查问题。

**影响**: 运维困难 — 导出任务失败后无法定位原因。

**建议**: 将字段重命名为 `has_error`，新增 `error_message TEXT NULL` 列存储失败详情。

---

### [BUG-003] `personal_access_tokens` 迁移包含开发环境 AUTO_INCREMENT 残留

**文件**: `database/migrations/2025_01_01_000000_framework_core.php:178`

```sql
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

**问题**: 迁移文件中的 `AUTO_INCREMENT=75` 是开发环境数据残留。新项目执行迁移后，自增 ID 从 75 开始而非 1。

**影响**: 轻微 — 功能无影响，但不专业，且暴露了开发环境数据。

**建议**: 移除 `AUTO_INCREMENT=75`，让数据库使用默认值。

---

## P1 — 建议修复

### [SMELL-001] `routes/api.php` 大量闭包路由

**文件**: `routes/api.php:27-143`

**问题**: 站内通知中心（in-app-notifications）的 11 个路由使用内联闭包，合计 ~120 行业务逻辑直接写在路由文件中。框架其他模块均使用 Controller 模式。

**影响**:
- 无法使用 Form Request 验证（部分路由已手动调用 `$request->validate()`）
- 无法被 `php artisan route:cache` 缓存
- 无法复用中间件（如 rbac.permission）
- 与框架"面向接口"设计理念不一致

**建议**: 提取为 `InAppNotificationController`，统一使用标准 Controller 模式。

---

### [SMELL-002] 安全头缺少 Content-Security-Policy

**文件**: `app/Http/Middleware/AddSecurityHeaders.php`

**问题**: 已设置 X-Content-Type-Options、X-Frame-Options、Referrer-Policy、HSTS，但缺少 CSP 头。SPA 应用尤其需要 CSP 防止 XSS 注入。

**影响**: 安全防御不完整 — 缺少 XSS 的最后一道防线。

**建议**: 添加合理的 CSP 策略，至少限制 `script-src` 和 `style-src`。

---

### [SMELL-003] 前端 Token 存储在 localStorage

**文件**: `resources/js/admin/stores/user.ts:15,34`

```typescript
const token = ref<string | null>(localStorage.getItem('admin_token'))
localStorage.setItem('admin_token', newToken)
```

**问题**: localStorage 对同源下的所有 JS 代码可读，XSS 攻击可直接窃取 Token。

**影响**: 安全风险 — XSS 演变为完整的账户劫持。

**建议**: 改用 httpOnly cookie（后端设置）或至少在生产环境使用 sessionStorage + 短过期 Token。

---

### [SMELL-004] `__callStatic` 静态代理模式广泛使用

**文件**: 23 个 Service 类（Billing/, Auth/, Infrastructure/, Notification/, etc.）

**问题**: 框架有 23 个 Service 保留了 `__callStatic` 向后兼容代理，允许 `SubscriptionService::method()` 静态调用。该模式:
- 隐藏实际依赖注入，不利于测试 mock
- IDE 无法跳转到方法定义
- 静态分析工具无法检测调用链
- 所有类都标记了 `@deprecated` 但无迁移计划

**影响**: 技术债务 — 阻碍代码质量和可维护性提升。

**建议**: 制定迁移计划，逐步移除 `__callStatic`，统一使用构造器注入。

---

### [SMELL-005] BillingServiceProvider Tool 注册过于冗长

**文件**: `src/Modules/Billing/BillingServiceProvider.php:69-75`

**问题**: 7 个 `register()` 调用均为单行超长语句（120-180 字符），参数重复度高。

**建议**: 使用配置数组 + 循环注册，或定义 PHP 8 Attribute 注解自动发现。

---

### [SMELL-006] 前端无任何测试

**路径**: `resources/js/`

**问题**: 整个前端目录（admin SPA + console SPA + ui-core）无任何 `.test.ts` / `.spec.ts` 文件，无 `__tests__/` 目录。无 vitest/jest 配置。

**影响**: 前端回归风险高 — Vue 组件、Store、路由逻辑无测试保障。

**建议**: 引入 vitest，优先覆盖 Store 和路由守卫。

---

## P2 — 低优先级改进

### [TODO-001] 自动扣款未实现（2 处）

**文件**:
- `src/Modules/Billing/Console/Commands/ProcessCreditExpiry.php:169`
- `src/Modules/Billing/Services/SubscriptionService.php:353`

```php
// TODO: 调用 PayService 发起自动扣款
```

**现状**: 仅创建订单记录，不实际发起支付。自动续费和自动充值功能不完整。

---

### [TODO-002] API Token 用量查询未实现

**文件**: `src/Modules/ApiToken/Services/ApiTokenService.php:382`

```php
// TODO: 当 New API 提供 /api/usage?token_id=&date= 时，在此实现
return null;
```

---

### [TODO-003] 模型废弃追踪未实现

**文件**: `src/Enums/AiModelEnum.php:103`

```php
// TODO: implement deprecation tracking — 当前所有模型均返回 false
```

---

### [SMELL-007] 迁移文件大量使用原始 SQL

**文件**: `database/migrations/2025_01_01_000000_framework_core.php`

**问题**: 14 张表使用 `DB::statement(<<<'SQL' ... SQL)` 原始建表，仅 `jobs` 和 `failed_jobs` 使用 Schema Builder。硬编码了 `ENGINE=InnoDB`、`COLLATE=utf8mb4_unicode_ci` 等 MySQL 特定语法。

**影响**:
- 跨数据库兼容性差（无法切换到 PostgreSQL/SQLite）
- 无法利用 Laravel 迁移的状态管理和回滚功能
- 代码可读性低于 Schema Builder

**注意**: 由于这是框架核心迁移且已在线上运行，重构需谨慎评估迁移兼容性。

---

### [SMELL-008] `import_jobs` 表主键非标准命名

**文件**: `database/migrations/2025_01_01_000000_framework_core.php:128`

```sql
`import_job_id` bigint unsigned NOT NULL,
...
PRIMARY KEY (`import_job_id`),
```

**问题**: 使用 `import_job_id` 而非 Laravel 惯例 `id` 作为主键。Eloquent Model 需要额外声明 `protected $primaryKey = 'import_job_id'`，否则查询会失败。

**影响**: 轻微 — 但增加了 Model 配置的出错概率。

---

## 统计

| 级别 | 数量 |
|------|------|
| BUG (实际缺陷) | 3 |
| SMELL (代码异味) | 8 |
| TODO (未完成项) | 3 |
| **合计** | **14** |

## 不构成问题的项（审查后排除）

以下项目在初审中被提出，经验证后**不作为问题记录**:

- **迁移使用原始 SQL**: 确认存在（SMELL-007），但考虑到这是框架级迁移且已在线上运行，标记为低优先级 SMELL 而非 BUG。
- **`export_tasks.error` 字段类型**: 确认作为布尔使用（BUG-002），字段名有误导性但功能上可用。
- **支付 GET 路由兼容性**: 微信支付 v2 旧版确实有 GET 验证场景，但 v3 回调统一为 POST，且控制器未做方法区分，仍标记为 BUG。
