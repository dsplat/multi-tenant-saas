# TASK-009: 租户引导式注册

**Sprint:** sprint-002  
**状态:** READY  
**依赖:** TASK-006（TrialService）、TASK-008（MailTemplateService）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现多步骤租户注册向导和初始化流程，使新租户可以自助完成注册、配置和初始化。

---

## 范围

**允许修改：**
- `src/Services/TenantOnboardingService.php`（新建）
- `app/Http/Controllers/Api/TenantController.php`（追加 onboarding 端点）
- `app/Http/Resources/TenantResource.php`（追加 onboarding 字段）
- `routes/api.php`（追加 onboarding 路由）
- `lang/zh_CN/tenant.php`、`lang/en/tenant.php`（新增翻译 key）
- `tests/TenantOnboardingTest.php`（新建）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除 TenantOnboardingService.php 外的其他文件

---

## 具体内容

### TenantOnboardingService

1. **注册步骤定义**: 
   - Step 1: 基础信息（租户名称、管理员邮箱、密码）
   - Step 2: 域名配置（子域名 or 自定义域名）
   - Step 3: 套餐选择（含试用选项）
   - Step 4: 支付信息（试用可跳过）
   - Step 5: 完成（自动初始化）
2. **步骤状态管理**: 每步完成后保存进度，记录当前步骤
3. **断点续填**: 用户可中断后继续，从上次步骤恢复
4. **自动初始化**: 注册完成后自动执行：
   - 创建默认角色（admin、user）
   - 创建默认管理员用户
   - 初始化租户设置
   - 发送欢迎邮件（调用 MailTemplateService）
   - 如选试用，调用 TrialService 设置试用期

### API 端点

| 方法 | 路由 | 说明 |
|------|------|------|
| POST | `/api/tenants/register` | 开始注册（Step 1） |
| GET | `/api/tenants/onboarding/status` | 查询注册进度 |
| POST | `/api/tenants/onboarding/{step}` | 提交指定步骤数据 |
| POST | `/api/tenants/onboarding/complete` | 完成注册，触发初始化 |

### TenantResource 追加

- `onboarding_step`: 当前注册步骤
- `onboarding_completed`: 是否已完成
- `trial_active`: 是否试用中（关联 TrialService）

---

## 验收标准

- [ ] 5 步注册流程完整，每步数据校验正常
- [ ] 断点续填功能正常（中途退出可恢复）
- [ ] 注册完成后自动初始化（角色、管理员、设置）
- [ ] 选择试用时正确调用 TrialService
- [ ] 欢迎邮件通过 MailTemplateService 发送
- [ ] API 端点全部可用，响应格式统一
- [ ] `php vendor/bin/phpunit` 全绿（含新测试）
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- TenantController 已存在于 `app/Http/Controllers/Api/TenantController.php`，只追加 onboarding 相关方法
- TenantResource 已存在于 `app/Http/Resources/TenantResource.php`，只追加 onboarding 字段
- routes/api.php 已有租户相关路由，在合适位置追加 onboarding 路由组
- 注册步骤数据可存储在 cache 或临时表中，不需要新建 migration
- onboarding 路由不需要认证中间件（注册前无 token），但需要限流
- TenantOnboardingService 应依赖注入 TrialService 和 MailTemplateService
- 响应格式统一用 `ApiResponse::success()` 和 `ApiResponse::error()`
