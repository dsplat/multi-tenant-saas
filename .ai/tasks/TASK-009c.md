# TASK-009c: [Auto-split from TASK-009]

**目标:** TenantResource 追加 onboarding 字段 + 编写 TenantOnboardingTest 完整测试
**只允许修改:**
- `app/Http/Resources/TenantResource.php`（追加 `onboarding_step`、`onboarding_completed`、`trial_active` 字段）
- `tests/TenantOnboardingTest.php`（新建，覆盖完整注册流程）
**禁止:** 修改其他文件、新增依赖
**预估时间:** 1.5 小时
**依赖:** TASK-009a, TASK-009b

**具体交付物：**
- `TenantResource::toArray()` 追加：`onboarding_step`（int|null）、`onboarding_completed`（bool）、`trial_active`（bool，调用 `TrialService::isInTrial`）
- 测试用例覆盖：
  - 完整 5 步注册流程端到端
  - 每步数据校验失败场景
  - 断点续填（中途退出后从 status 恢复）
  - 无效 step 编号处理
  - onboarding token 过期场景
  - 完成后自动初始化验证（角色、管理员、设置）
  - 试用期调用验证
  - 欢迎邮件发送验证
  - 域名冲突检测


## 状态
READY
