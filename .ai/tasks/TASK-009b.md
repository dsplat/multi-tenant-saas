# TASK-009b: [Auto-split from TASK-009]

**目标:** 追加 onboarding API 端点到 TenantController 并注册路由
**只允许修改:**
- `app/Http/Controllers/Api/TenantController.php`（追加 4 个 onboarding 方法）
- `routes/api.php`（追加 onboarding 路由组，无需认证，带 throttle）
**禁止:** 修改其他文件、新增依赖
**预估时间:** 1 小时
**依赖:** TASK-009a

**具体交付物：**
- Controller 新增方法：`register(Request)`、`onboardingStatus(Request)`、`onboardingStep(Request, int $step)`、`onboardingComplete(Request)`
- 路由组（`v1/tenants/onboarding/`，无 `auth:sanctum`，`throttle:20,1`）：
  - `POST /register` → `register`
  - `GET /status` → `onboardingStatus`
  - `POST /{step}` → `onboardingStep`
  - `POST /complete` → `onboardingComplete`
- 响应格式遵循现有 `response()->json(['success' => ..., 'data' => ..., 'message' => ...])` 模式

---



## 状态
READY
