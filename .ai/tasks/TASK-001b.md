# TASK-001b: 租户隔离与安全绕过修复

**Sprint:** sprint-001
**状态:** READY
**预估时间:** 2 小时
**依赖:** 无（可与 TASK-001a / TASK-001c 并行）
**来源:** TASK-001 ESCALATE 拆分

---

## 目标

修复租户隔离逻辑错误、OAuth state 校验绕过、QueueService 硬依赖和 ExportService 越权下载，消除 5 个 High/Medium 问题。

---

## 范围

**只允许修改：**
- `app/Services/SocialiteService.php`
- `app/Services/AlertService.php`
- `app/Services/RateLimitService.php`
- `app/Services/PluginService.php`
- `app/Services/QueueService.php`
- `app/Services/ExportService.php`

**禁止：**
- 修改其他任何文件
- 修改 Model 层或数据库 Schema
- 修改路由或 Middleware

---

## 验收标准

- [ ] `SocialiteService.php:249` — `$state` 为 null 时拒绝请求（return 或 abort(403)），不再跳过校验
- [ ] `AlertService` / `RateLimitService` / `PluginService` — 将 `orWhereNull('tenant_id')` 移入 `where()` 闭包内，防止跨租户数据泄露
- [ ] `QueueService.php:8-10` — 移除顶部 `use Horizon\...` import，改用全限定类名 + `class_exists()` 条件判断
- [ ] `ExportService.php:195` — 下载前增加用户级权限检查（验证当前用户是否有权访问该 export 记录）

---

## 给 AI 的补充说明

- `orWhereNull` 闭包写法示例：
  ```php
  // 错误：->orWhereNull('tenant_id')
  // 正确：
  ->where(function ($q) {
      $q->where('tenant_id', $this->tenantId)->orWhereNull('tenant_id');
  })
  ```
- ExportService 权限检查：验证 `export->user_id === auth()->id()` 或使用项目已有的 Policy

---

## 状态流转记录

| 时间 | 状态 | 备注 |
|------|------|------|
| 2026-06-27 | READY | 从 TASK-001 ESCALATE 拆分 |
