# TASK-009c — 测试: [Auto-split from TASK-009]


**目标：** 新建 TenantOnboardingTest，覆盖完整注册流程、断点续填、异常场景和自动初始化验证

**只允许修改：**
- `tests/TenantOnboardingTest.php`（新建）

**禁止：** 修改其他文件、新增 composer 依赖

**预估时间：** 1.5 小时

**依赖：** TASK-009a、TASK-009b

**具体内容：**
- 继承 `TestCase`，遵循现有测试风格（SQLite 内存库、Sanctum token）
- 测试用例：
  - `test_can_start_registration` — POST /api/v1/tenants/register 返回 token 和 step=1
  - `test_register_validates_required_fields` — 缺少必填字段返回 422
  - `test_can_submit_step2_domain` — 提交域名配置步骤
  - `test_can_submit_step3_plan_with_trial` — 选择试用套餐
  - `test_can_submit_step4_skip_payment_for_trial` — 试用跳过支付
  - `test_can_get_onboarding_status` — GET /api/v1/tenants/onboarding/status?token=xxx
  - `test_can_resume_from_interrupted_step` — 断点续填：提交 step1 后查询 status，确认从 step2 恢复
  - `test_cannot_skip_steps` — 跳步提交返回错误
  - `test_cannot_complete_without_all_steps` — 未完成全部步骤时 complete 返回错误
  - `test_complete_creates_tenant_with_defaults` — 完成后验证 Tenant、Role、User、TenantSetting 全部创建
  - `test_complete_with_trial_calls_trial_service` — 验证 `TrialService::startTrial()` 被调用，tenant 的 trial_ends_at 非空
  - `test_complete_fires_tenant_created_event` — 用 `Event::fake()` 验证事件触发
  - `test_duplicate_registration_rejected` — 重复邮箱注册返回错误
  - `test_expired_token_rejected` — 过期 token 返回错误

---

## 任务依赖总览

```
TASK-009a (Service + 翻译)
    │
    ▼
TASK-009b (Controller + Resource + 路由)
    │
    ▼
TASK-009c (测试)
```

三个子任务严格串行，每步完成后下一步才有意义。总预估时间：2 + 1.5 + 1.5 = **5 小时**。


## 状态
READY
