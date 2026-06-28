# TASK-009a — 核心服务 + 翻译: [Auto-split from TASK-009]


**目标：** 新建 TenantOnboardingService，实现 5 步注册流程的状态管理、数据校验、断点续填和自动初始化逻辑，并同步新增所需的翻译 key

**只允许修改：**
- `src/Services/TenantOnboardingService.php`（新建）
- `lang/zh_CN/tenant.php`（追加 onboarding 相关翻译 key）
- `lang/en/tenant.php`（追加 onboarding 相关翻译 key）

**禁止：** 修改其他文件、新增 composer 依赖

**预估时间：** 2 小时

**依赖：** 无

**具体内容：**
- 定义 5 步注册流程常量和每步字段校验规则
- 使用 `Cache::put()` / `Cache::get()` 存储 onboarding 会话数据（key: `onboarding.{token}`）
- 实现 `startRegistration(array $data): array` — 创建会话，返回 session token + step 1
- 实现 `submitStep(string $token, int $step, array $data): array` — 校验并保存当前步，返回下一步
- 实现 `getStatus(string $token): array` — 返回当前步骤和已填数据
- 实现 `complete(string $token): Tenant` — 触发完整初始化流程：
  - 创建 Tenant 记录
  - 创建默认角色（admin / user）— 调用 RbacService 或直接创建 Role model
  - 创建默认管理员 User + TenantUser 关联
  - 调用 `provisionTenant()` 初始化 TenantSetting（复用现有模式）
  - 如选试用，调用 `TrialService::startTrial()`
  - 触发 `Event::dispatch(new TenantCreated($tenant))`（MailTemplateService 未就绪，用事件替代）
- 新增翻译 key：`onboarding.step_completed`、`onboarding.already_completed`、`onboarding.invalid_token`、`onboarding.invalid_step`、`onboarding.validation_failed`、`onboarding.completed`、`onboarding.step_names.1` ~ `onboarding.step_names.5`

---



## 状态
READY
