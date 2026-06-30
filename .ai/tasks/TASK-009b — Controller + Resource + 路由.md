# TASK-009b — Controller + Resource + 路由: [Auto-split from TASK-009]


**目标：** 在 TenantController 中新增 onboarding 相关方法，扩展 TenantResource 追加 onboarding 字段，并在 api.php 中注册路由

**只允许修改：**
- `app/Http/Controllers/Api/TenantController.php`（追加方法）
- `app/Http/Resources/TenantResource.php`（追加字段）
- `routes/api.php`（追加路由组）

**禁止：** 修改其他文件、新增 composer 依赖

**预估时间：** 1.5 小时

**依赖：** TASK-009a

**具体内容：**

**TenantController 追加方法：**
- `register(Request $request)` — 调用 `TenantOnboardingService::startRegistration()`，返回 session token
  - 校验：name（required|string|max:255）、admin_email（required|email）、password（required|min:8）
  - 限流：throttle middleware
- `onboardingStatus(Request $request)` — 查询 `?token=xxx`，调用 `getStatus()`
  - 无需认证，仅限流
- `onboardingStep(Request $request, int $step)` — 提交指定步骤数据
  - 无需认证，仅限流
  - body 中携带 `token` 字段
- `onboardingComplete(Request $request)` — 完成注册
  - 无需认证，仅限流
  - body 中携带 `token` 字段
- 需新增 import：`use MultiTenantSaas\Services\TenantOnboardingService;`

**TenantResource 追加字段：**
- `onboarding_step` — `$this->onboarding_step ?? null`
- `onboarding_completed` — `$this->onboarding_completed ?? false`
- `trial_active` — 调用 `TrialService::isInTrial($this->resource)`（需 import TrialService）

**routes/api.php 追加：**
- 在 auth 中间件组**之外**新增路由组：
```php
Route::middleware(['throttle:api'])->prefix('v1')->group(function () {
    Route::post('/tenants/register', [TenantController::class, 'register']);
    Route::get('/tenants/onboarding/status', [TenantController::class, 'onboardingStatus']);
    Route::post('/tenants/onboarding/{step}', [TenantController::class, 'onboardingStep']);
    Route::post('/tenants/onboarding/complete', [TenantController::class, 'onboardingComplete']);
});
```
- 需新增 import：`use App\Http\Controllers\Api\TenantController;`（如尚未导入）

---



## 状态
READY
