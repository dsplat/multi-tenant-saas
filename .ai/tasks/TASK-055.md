# TASK-055: 修复 TestCase 缺失的 26 个数据库表

## 背景
当前测试套件有 551 个测试失败，原因是 TestCase.php 的 `setUpDatabase()` 方法没有创建以下 26 个表：
- mfa_devices, mfa_recovery_codes
- feature_flags
- user_sessions
- ai_tenant_configs, ai_model_aliases, ai_requests, ai_prompts, ai_usage_quotas
- branding_configs
- broadcast_events
- consents
- cost_allocations
- sandbox_environments
- event_subscriptions, dead_letters
- trusted_devices
- in_app_notifications
- ip_whitelists
- metrics_snapshots
- password_histories
- custom_reports
- data_retention_policies
- sla_events
- sso_providers
- tenant_keys

## 任务
1. 读取 `database/migrations/` 中对应的迁移文件
2. 在 `tests/TestCase.php` 的 `setUpDatabase()` 方法中添加这些表的创建逻辑
3. 确保所有表结构与迁移文件一致
4. 运行 `php artisan test` 验证所有测试通过

## 验收标准
- 所有 26 个缺失表在测试环境中正确创建
- 现有测试不再因 "no such table" 错误失败
- 测试套件整体通过率达到 100%
