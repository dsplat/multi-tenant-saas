# TASK-009a: [Auto-split from TASK-009]

**目标:** 实现 TenantOnboardingService 核心业务逻辑（5步注册流程、状态管理、断点续填、自动初始化）
**只允许修改:**
- `src/Services/TenantOnboardingService.php`（新建）
- `lang/zh_CN/tenant.php`（追加 onboarding 相关翻译 key）
- `lang/en/tenant.php`（追加 onboarding 相关翻译 key）
**禁止:** 修改其他文件、新增依赖、引入不存在的 ApiResponse 类
**预估时间:** 2 小时
**依赖:** 无（TrialService 和 MailTemplateService 已在 Wave 2 完成）

**具体交付物：**
- `TenantOnboardingService` 类，构造函数注入 `TrialService` 和 `MailTemplateService`
- `getStepRules(int $step): array` — 每步验证规则
- `saveStep(string $token, int $step, array $data): array` — 保存步骤进度（使用 `Cache::put` 存储，TTL 24h）
- `getProgress(string $token): ?array` — 查询当前进度
- `complete(string $token): Tenant` — 执行自动初始化（创建租户、默认角色、管理员用户、默认设置、发送欢迎邮件、按需启动试用）
- `generateOnboardingToken(): string` — 生成唯一 onboarding token
- 翻译 key 追加：`onboarding.step_completed`、`onboarding.completed`、`onboarding.invalid_step`、`onboarding.expired`、`onboarding.domain_taken`

---



## 状态
READY
