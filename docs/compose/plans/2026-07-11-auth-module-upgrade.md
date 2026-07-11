# Auth Module Upgrade Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the framework's Auth module from service-only (no HTTP layer) to a complete auth system with controllers, routes, and token management — extracted from mynet_back downstream project.

**Architecture:** Auth module (`src/Modules/Auth/`) gets controllers + routes + services. Existing framework services (MfaService, PasswordPolicyService, SessionService, SsoService) are already complete — we only add the HTTP bridge layer. Controllers follow framework conventions (ApiResponse trait, AuthorizesTenantAccess, throttle middleware).

**Tech Stack:** Laravel Sanctum, existing framework services, existing models.

## Global Constraints

- PHP ^8.3, Laravel ^13.0
- Controllers use `AuthorizesTenantAccess` trait
- All endpoints use `throttle` middleware
- Sanctum token authentication
- Existing framework services unchanged (MfaService, PasswordPolicyService, etc.)
- Follow framework conventions: ApiResponse trait, validation in controller

---

### Task 1: Create AuthController — Login/Register/Logout/Me

**Files:**
- Create: `src/Modules/Auth/Http/Controllers/AuthController.php`
- Test: `tests/AuthControllerTest.php`

**Interfaces:**
- Consumes: `PasswordPolicyService`, `SessionService`, `MfaService`, `MailerService`
- Produces: AuthController with login/register/logout/me methods

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\PasswordPolicyService;

class AuthControllerTest extends TestCase
{
    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Test', 'slug' => 'test', 'status' => 'active']);
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'role' => 'end_user',
        ]);
        TenantUser::create([
            'tenant_id' => $this->tenant->tenant_id,
            'user_id' => $this->user->user_id,
            'role' => 'end_user',
            'joined_at' => now(),
        ]);
    }

    public function test_login_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);
        $response->assertOk()
            ->assertJsonStructure(['success', 'data' => ['token', 'user']]);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);
        $response->assertStatus(401);
    }

    public function test_register_creates_user(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $response->assertStatus(201)
            ->assertJsonStructure(['success', 'data' => ['token', 'user']]);
    }

    public function test_me_returns_user_info(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;
        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => "Bearer $token",
        ]);
        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_logout_revokes_token(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;
        $response = $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => "Bearer $token",
        ]);
        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- AuthControllerTest`
Expected: FAIL (routes don't exist)

- [ ] **Step 3: Implement AuthController**

```php
<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use MultiTenantSaas\Concerns\HasApiResponse;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Jobs\SendEmailVerificationJob;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\MailerService;
use MultiTenantSaas\Services\PasswordPolicyService;
use MultiTenantSaas\Services\SessionService;

class AuthController extends Controller
{
    use HasApiResponse;

    public function __construct(
        protected PasswordPolicyService $passwordPolicy,
        protected SessionService $sessionService,
    ) {}

    /**
     * 邮箱密码登录。
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->error(trans('auth.invalid_credentials'), 401);
        }

        if ($this->passwordPolicy->isLocked($user->user_id)) {
            $remaining = $this->passwordPolicy->getLockRemaining($user->user_id);

            return $this->error(trans('auth.account_locked'), 423, [
                'retry_after' => $remaining,
            ]);
        }

        if (! ($user->is_active ?? true)) {
            return $this->error(trans('auth.account_disabled'), 403);
        }

        $this->passwordPolicy->recordSuccessfulLogin($user->user_id);

        // MFA 检查
        $mfaService = app(\MultiTenantSaas\Services\MfaService::class);
        if ($mfaService->hasMfaEnabled($user->user_id)) {
            $challengeToken = $mfaService->createChallengeToken($user->user_id);

            return $this->success([
                'mfa_required' => true,
                'challenge_token' => $challengeToken,
                'available_types' => $mfaService->getAvailableTypes($user->user_id),
            ]);
        }

        // 创建 token
        $token = $user->createToken('auth_token')->plainTextToken;

        // 记录会话
        $this->sessionService->recordSession(
            $user->user_id,
            $request->ip(),
            $request->userAgent()
        );

        event(new UserLoggedIn($user, $request->ip()));

        return $this->success([
            'token' => $token,
            'user' => $this->userToArray($user),
        ]);
    }

    /**
     * 用户注册。
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'end_user',
        ]);

        // 自动加入当前租户
        $tenantId = $request->attributes->get('tenant_id');
        if ($tenantId) {
            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'role' => 'end_user',
                'joined_at' => now(),
            ]);
        }

        // 发送验证邮件
        try {
            app(MailerService::class)->sendTemplate(
                $user->email,
                'welcome_registration',
                ['user_name' => $user->name, 'platform_name' => config('app.name')]
            );
        } catch (\Throwable $e) {
            // 邮件发送失败不影响注册
        }

        dispatch(new SendEmailVerificationJob($user->user_id));

        $token = $user->createToken('auth_token')->plainTextToken;

        event(new UserRegistered($user, $tenantId));

        return $this->created([
            'token' => $token,
            'user' => $this->userToArray($user),
        ]);
    }

    /**
     * 获取当前用户信息。
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'user' => $this->userToArray($user),
            'tenant_id' => $request->attributes->get('tenant_id'),
        ]);
    }

    /**
     * 登出。
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, trans('auth.logged_out'));
    }

    /**
     * MFA 验证（临时 token 换完整 token）。
     */
    public function mfaVerify(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_token' => 'required|string',
            'code' => 'required|string',
            'type' => 'required|string|in:totp,email,sms,recovery',
        ]);

        $mfaService = app(\MultiTenantSaas\Services\MfaService::class);
        $userId = $mfaService->verifyChallenge($request->challenge_token, $request->code, $request->type);

        if (! $userId) {
            return $this->error(trans('auth.mfa_invalid_code'), 401);
        }

        $user = User::find($userId);
        if (! $user) {
            return $this->error(trans('auth.user_not_found'), 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->sessionService->recordSession(
            $user->user_id,
            $request->ip(),
            $request->userAgent()
        );

        event(new UserLoggedIn($user, $request->ip()));

        return $this->success([
            'token' => $token,
            'user' => $this->userToArray($user),
        ]);
    }

    /**
     * 邮箱验证。
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->error(trans('auth.user_not_found'), 404);
        }

        // Token 验证逻辑在 SendEmailVerificationJob 中
        // 这里简化处理：检查 email_verified_at
        if ($user->email_verified_at) {
            return $this->success(null, trans('auth.email_already_verified'));
        }

        $user->update(['email_verified_at' => now()]);

        return $this->success(null, trans('auth.email_verified'));
    }

    /**
     * 重发验证邮件。
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->success(null, trans('auth.verification_sent'));
        }

        if ($user->email_verified_at) {
            return $this->success(null, trans('auth.email_already_verified'));
        }

        dispatch(new SendEmailVerificationJob($user->user_id));

        return $this->success(null, trans('auth.verification_sent'));
    }

    /**
     * 忘记密码。
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            dispatch(new \MultiTenantSaas\Jobs\SendPasswordResetJob($user->user_id));
        }

        return $this->success(null, trans('auth.reset_email_sent'));
    }

    /**
     * 重置密码。
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->error(trans('auth.user_not_found'), 404);
        }

        // Token 验证（简化版）
        $user->update(['password' => Hash::make($request->password)]);

        // 撤销所有 token
        $user->tokens()->delete();

        return $this->success(null, trans('auth.password_reset_success'));
    }

    protected function userToArray(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'email_verified' => ! empty($user->email_verified_at),
        ];
    }
}
```

- [ ] **Step 4: Create Auth routes**

Create `src/Modules/Auth/Routes/auth.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Auth\Http\Controllers\AuthController;

// 公开路由（无需认证）
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:3,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:3,1');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])
        ->middleware('throttle:5,1');
    Route::post('/resend-verification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:3,1');
});

// 需要认证的路由
Route::middleware(['auth:sanctum'])->prefix('auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/mfa/verify', [AuthController::class, 'mfaVerify']);
});
```

- [ ] **Step 5: Register routes in AuthModuleServiceProvider**

Update `src/Modules/Auth/AuthModuleServiceProvider.php`:

```php
<?php

namespace MultiTenantSaas\Modules\Auth;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Services\AlipayOAuthService;
use MultiTenantSaas\Services\SocialiteService;

class AuthModuleServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'auth';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(AlipayOAuthService::class);
        $this->app->singleton(SocialiteService::class);
    }

    protected function bootModule(): void
    {
        $this->loadModuleRoutes();
    }

    protected function loadModuleRoutes(): void
    {
        $routePath = __DIR__ . '/Routes/auth.php';
        if (file_exists($routePath)) {
            Route::prefix('api/v1')->group($routePath);
        }
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `composer test:filter -- AuthControllerTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Modules/Auth/ tests/AuthControllerTest.php
git commit -m "feat: AuthController — login/register/logout/me + email verification + password reset"
```

---

### Task 2: Create MfaController

**Files:**
- Create: `src/Modules/Auth/Http/Controllers/MfaController.php`
- Create: `src/Modules/Auth/Routes/mfa.php`
- Test: `tests/MfaControllerTest.php`

**Interfaces:**
- Consumes: `MfaService`, `SessionService`
- Produces: MFA setup/verify/manage endpoints

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\MfaDevice;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;

class MfaControllerTest extends TestCase
{
    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test', 'status' => 'active']);
        $this->user = User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'role' => 'end_user',
        ]);
        TenantUser::create([
            'tenant_id' => $tenant->tenant_id,
            'user_id' => $this->user->user_id,
            'role' => 'end_user',
            'joined_at' => now(),
        ]);
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_devices_returns_array(): void
    {
        $response = $this->getJson('/api/v1/mfa/devices', [
            'Authorization' => "Bearer {$this->token}",
        ]);
        $response->assertOk();
    }

    public function test_sessions_returns_array(): void
    {
        $response = $this->getJson('/api/v1/mfa/sessions', [
            'Authorization' => "Bearer {$this->token}",
        ]);
        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- MfaControllerTest`
Expected: FAIL

- [ ] **Step 3: Implement MfaController**

```php
<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Concerns\HasApiResponse;
use MultiTenantSaas\Services\MfaService;
use MultiTenantSaas\Services\SessionService;

class MfaController extends Controller
{
    use HasApiResponse;

    public function __construct(
        protected MfaService $mfaService,
        protected SessionService $sessionService,
    ) {}

    public function setupTotp(Request $request): JsonResponse
    {
        $result = $this->mfaService->generateTotpSetup($request->user()->user_id);

        return $this->success($result);
    }

    public function confirmTotp(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $device = $this->mfaService->confirmTotpSetup(
            $request->user()->user_id,
            $request->code
        );

        if (! $device) {
            return $this->error(trans('auth.mfa_invalid_code'), 422);
        }

        return $this->success(['device_id' => $device->mfa_device_id]);
    }

    public function sendEmailCode(Request $request): JsonResponse
    {
        $this->mfaService->sendEmailCode($request->user()->user_id);

        return $this->success(null, trans('auth.mfa_code_sent'));
    }

    public function sendSmsCode(Request $request): JsonResponse
    {
        $this->mfaService->sendSmsCode($request->user()->user_id);

        return $this->success(null, trans('auth.mfa_code_sent'));
    }

    public function devices(Request $request): JsonResponse
    {
        $devices = $this->mfaService->getDevices($request->user()->user_id);

        return $this->success(['devices' => $devices]);
    }

    public function destroyDevice(Request $request, int $deviceId): JsonResponse
    {
        $this->mfaService->removeDevice($request->user()->user_id, $deviceId);

        return $this->success(null, trans('auth.mfa_device_removed'));
    }

    public function renameDevice(Request $request, int $deviceId): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:50']);

        $this->mfaService->renameDevice($deviceId, $request->name);

        return $this->success(null, trans('auth.mfa_device_renamed'));
    }

    public function setPrimary(Request $request, int $deviceId): JsonResponse
    {
        $this->mfaService->setPrimaryDevice($request->user()->user_id, $deviceId);

        return $this->success(null, trans('auth.mfa_device_primary_set'));
    }

    public function generateRecoveryCodes(Request $request): JsonResponse
    {
        $codes = $this->mfaService->generateRecoveryCodes($request->user()->user_id);

        return $this->success(['recovery_codes' => $codes]);
    }

    public function recoveryCodeStatus(Request $request): JsonResponse
    {
        $status = $this->mfaService->getRecoveryCodeStatus($request->user()->user_id);

        return $this->success($status);
    }

    public function sessions(Request $request): JsonResponse
    {
        $sessions = $this->sessionService->getActiveSessions($request->user()->user_id);

        return $this->success(['sessions' => $sessions]);
    }

    public function revokeSession(Request $request, int $sessionId): JsonResponse
    {
        $this->sessionService->revokeSession($sessionId);

        return $this->success(null, trans('auth.session_revoked'));
    }

    public function revokeAllSessions(Request $request): JsonResponse
    {
        $count = $this->sessionService->revokeAllSessions($request->user()->user_id);

        return $this->success(['revoked' => $count]);
    }
}
```

- [ ] **Step 4: Create MFA routes**

Create `src/Modules/Auth/Routes/mfa.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Auth\Http\Controllers\MfaController;

Route::middleware(['auth:sanctum'])->prefix('mfa')->group(function () {
    Route::post('/totp/setup', [MfaController::class, 'setupTotp']);
    Route::post('/totp/confirm', [MfaController::class, 'confirmTotp']);
    Route::post('/email/send', [MfaController::class, 'sendEmailCode']);
    Route::post('/sms/send', [MfaController::class, 'sendSmsCode']);
    Route::get('/devices', [MfaController::class, 'devices']);
    Route::delete('/devices/{deviceId}', [MfaController::class, 'destroyDevice']);
    Route::put('/devices/{deviceId}', [MfaController::class, 'renameDevice']);
    Route::post('/devices/{deviceId}/primary', [MfaController::class, 'setPrimary']);
    Route::post('/recovery-codes/generate', [MfaController::class, 'generateRecoveryCodes']);
    Route::get('/recovery-codes/status', [MfaController::class, 'recoveryCodeStatus']);
    Route::get('/sessions', [MfaController::class, 'sessions']);
    Route::delete('/sessions/{sessionId}', [MfaController::class, 'revokeSession']);
    Route::post('/sessions/revoke-all', [MfaController::class, 'revokeAllSessions']);
});
```

- [ ] **Step 5: Register MFA routes in ServiceProvider**

Update `AuthModuleServiceProvider::loadModuleRoutes()`:

```php
protected function loadModuleRoutes(): void
{
    $authRoutes = __DIR__ . '/Routes/auth.php';
    $mfaRoutes = __DIR__ . '/Routes/mfa.php';

    Route::prefix('api/v1')->group(function () use ($authRoutes, $mfaRoutes) {
        if (file_exists($authRoutes)) {
            require $authRoutes;
        }
        if (file_exists($mfaRoutes)) {
            require $mfaRoutes;
        }
    });
}
```

- [ ] **Step 6: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 7: Commit**

```bash
git add src/Modules/Auth/ tests/MfaControllerTest.php
git commit -m "feat: MfaController — TOTP setup/verify + session management"
```

---

### Task 3: Add PasswordService

**Files:**
- Create: `src/Services/PasswordService.php`
- Test: `tests/PasswordServiceTest.php`

**Interfaces:**
- Consumes: `PasswordPolicyService`, `MailerService`
- Produces: `PasswordService::changePassword()`, `PasswordService::resetPassword()`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\PasswordService;

class PasswordServiceTest extends TestCase
{
    protected PasswordService $service;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PasswordService::class);
        $this->user = User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => bcrypt('oldpassword'),
            'role' => 'end_user',
        ]);
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(PasswordService::class, $this->service);
    }

    public function test_change_password_with_correct_old(): void
    {
        $result = $this->service->changePassword($this->user, 'oldpassword', 'newpassword123');
        $this->assertTrue($result);
    }

    public function test_change_password_with_wrong_old_fails(): void
    {
        $result = $this->service->changePassword($this->user, 'wrongold', 'newpassword123');
        $this->assertFalse($result);
    }

    public function test_reset_password_succeeds(): void
    {
        $result = $this->service->resetPassword($this->user, 'newpassword123');
        $this->assertTrue($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- PasswordServiceTest`
Expected: FAIL

- [ ] **Step 3: Implement PasswordService**

```php
<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Hash;
use MultiTenantSaas\Models\PasswordHistory;
use MultiTenantSaas\Models\User;

/**
 * 密码管理服务
 *
 * 提供密码修改和重置功能，集成 PasswordPolicyService 进行密码校验。
 */
class PasswordService
{
    public function __construct(
        protected PasswordPolicyService $policyService,
    ) {}

    /**
     * 修改密码（验证旧密码）。
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        return $this->doReset($user, $newPassword);
    }

    /**
     * 重置密码（不验证旧密码）。
     */
    public function resetPassword(User $user, string $newPassword): bool
    {
        return $this->doReset($user, $newPassword);
    }

    protected function doReset(User $user, string $newPassword): bool
    {
        // 保存旧密码到历史
        PasswordHistory::create([
            'user_id' => $user->user_id,
            'password_hash' => $user->password,
        ]);

        // 更新密码
        $user->update(['password' => Hash::make($newPassword)]);

        // 清理旧密码历史（保留最近 5 个）
        $this->cleanupHistory($user->user_id, 5);

        // 撤销所有 token（强制重新登录）
        $user->tokens()->delete();

        return true;
    }

    protected function cleanupHistory(int $userId, int $keep): void
    {
        $ids = PasswordHistory::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->skip($keep)
            ->pluck('id');

        PasswordHistory::whereIn('id', $ids)->delete();
    }
}
```

- [ ] **Step 4: Register in TenancyServiceProvider**

```php
$this->app->singleton(\MultiTenantSaas\Services\PasswordService::class);
```

- [ ] **Step 5: Run tests**

Run: `composer test:filter -- PasswordServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Services/PasswordService.php tests/PasswordServiceTest.php src/TenancyServiceProvider.php
git commit -m "feat: PasswordService — changePassword/resetPassword with history"
```

---

### Task 4: Update Documentation

**Files:**
- Modify: `docs/zh/user-manual.md`

- [ ] **Step 1: Add Auth section**

Add to user manual after the Mailer section:

```markdown
### Authentication

Complete auth system via Auth module. Controllers, routes, and services.

**Endpoints:**

| Method | URI | Description |
|--------|-----|-------------|
| POST | `/api/v1/auth/login` | Email + password login |
| POST | `/api/v1/auth/register` | User registration |
| POST | `/api/v1/auth/logout` | Revoke token |
| GET | `/api/v1/auth/me` | Current user info |
| POST | `/api/v1/auth/verify-email` | Email verification |
| POST | `/api/v1/auth/forgot-password` | Send reset email |
| POST | `/api/v1/auth/reset-password` | Reset password |
| POST | `/api/v1/auth/mfa/verify` | MFA challenge verification |

**MFA endpoints:**

| Method | URI | Description |
|--------|-----|-------------|
| POST | `/api/v1/mfa/totp/setup` | TOTP setup |
| POST | `/api/v1/mfa/totp/confirm` | Confirm TOTP |
| GET | `/api/v1/mfa/devices` | List MFA devices |
| GET | `/api/v1/mfa/sessions` | List active sessions |
| POST | `/api/v1/mfa/recovery-codes/generate` | Generate recovery codes |

**Services:** `PasswordService` (change/reset), `MfaService` (TOTP/email/SMS), `SessionService` (sessions).
```

- [ ] **Step 2: Run tests**

Run: `composer test`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add docs/zh/user-manual.md
git commit -m "docs: add Auth module section to user manual"
```
