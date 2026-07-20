<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Jobs\SendEmailVerificationJob;
use MultiTenantSaas\Jobs\SendPasswordResetJob;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\MfaService;
use MultiTenantSaas\Modules\Auth\Services\PasswordPolicyService;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Auth\Services\SessionService;
use MultiTenantSaas\Modules\Auth\Services\SsoService;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Scopes\TenantScope;

class AuthController extends Controller
{
    public function __construct(
        protected PasswordPolicyService $passwordPolicy,
        protected SessionService $sessionService,
        protected MfaService $mfaService,
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
            return response()->json(['success' => false, 'message' => trans('auth.invalid_credentials')], 401);
        }

        if ($this->passwordPolicy->isLocked($user)) {
            $remaining = $this->passwordPolicy->getLockRemainingSeconds($user);

            return response()->json([
                'success' => false,
                'message' => trans('auth.account_locked'),
                'retry_after' => $remaining,
            ], 423);
        }

        if (! ($user->is_active ?? true)) {
            return response()->json(['success' => false, 'message' => trans('auth.account_disabled')], 403);
        }

        $this->passwordPolicy->recordSuccessfulLogin($user);

        // MFA 检查
        if ($this->mfaService->hasMfaEnabled($user->user_id)) {
            return response()->json([
                'success' => true,
                'data' => [
                    'mfa_required' => true,
                    'user_id' => $user->user_id,
                    'available_types' => $this->mfaService->getAvailableChallengeTypes($user->user_id),
                ],
            ]);
        }

        return $this->createTokenResponse($user, $request);
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
            'password' => $request->password,  // Model casts auto-hashes
        ]);

        // 自动加入当前租户
        $tenantId = $request->attributes->get('tenant_id');
        if ($tenantId) {
            $endUserRoleId = \DB::table('roles')
                ->where('name', 'end_user')
                ->whereNull('tenant_id')
                ->value('role_id');

            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'role_id' => $endUserRoleId,
                'joined_at' => now(),
            ]);
        }

        // 发送验证邮件
        dispatch(new SendEmailVerificationJob($user->user_id));

        event(new UserRegistered($user, $tenantId));

        $newToken = $user->createToken('auth_token');
        $token = $newToken->plainTextToken;
        $tokenId = $newToken->accessToken->id;

        $this->sessionService->recordSession(
            $user->user_id,
            $tokenId,
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->userToArray($user),
                'tenant_id' => $tenantId,
                'auth_token' => $token,
                'refresh_token' => Str::random(60),
                'auth_token_expires_in' => 1800,
                'refresh_token_expires_in' => 604800,
            ],
        ], 201);
    }

    /**
     * 获取当前用户信息。
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->userToArray($request->user()),
                'tenant_id' => $request->attributes->get('tenant_id'),
                'permissions' => RbacService::getCurrentUserPermissions(),
            ],
        ]);
    }

    /**
     * 登出。
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => trans('auth.logged_out')]);
    }

    /**
     * 管理员登录（仅 platform scope operator）。
     *
     * 直接认证 Operator，生成 Operator token。
     */
    public function adminLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $operator = Operator::where('email', $request->email)->first();

        if (! $operator || ! Hash::check($request->password, $operator->password)) {
            return response()->json(['success' => false, 'message' => trans('auth.invalid_credentials')], 401);
        }

        if (! $operator->is_active) {
            return response()->json(['success' => false, 'message' => trans('auth.account_disabled')], 403);
        }

        if (! $operator->isPlatform()) {
            return response()->json(['success' => false, 'message' => trans('common.super_admin_only')], 403);
        }

        // 检查账户锁定
        if ($operator->locked_until && Carbon::parse($operator->locked_until)->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => trans('auth.account_locked'),
                'retry_after' => Carbon::parse($operator->locked_until)->diffInSeconds(now()),
            ], 423);
        }

        // 更新登录状态
        $operator->update([
            'login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ]);

        return $this->createOperatorTokenResponse($operator, $request);
    }

    /**
     * 管理员登出。
     */
    public function adminLogout(Request $request): JsonResponse
    {
        return $this->logout($request);
    }

    /**
     * 获取当前管理员信息（Operator）。
     */
    public function adminUser(Request $request): JsonResponse
    {
        $tokenable = $request->user();

        if ($tokenable instanceof Operator) {
            return response()->json([
                'success' => true,
                'data' => $this->operatorToArray($tokenable),
            ]);
        }

        // 向后兼容：老 token 仍然是 User
        return response()->json([
            'success' => true,
            'data' => $this->userToArray($tokenable),
        ]);
    }

    /**
     * 租户管理员登录（通过 Operator 认证，关联租户）。
     *
     * 直接认证 Operator，生成 Operator token。
     * 如果 Operator 无租户关联，返回 403 引导申请。
     */
    public function consoleLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $operator = Operator::where('email', $request->email)->first();

        if (! $operator || ! Hash::check($request->password, $operator->password)) {
            return response()->json(['success' => false, 'message' => trans('auth.invalid_credentials')], 401);
        }

        if (! $operator->is_active) {
            return response()->json(['success' => false, 'message' => trans('auth.account_disabled')], 403);
        }

        // 检查账户锁定
        if ($operator->locked_until && Carbon::parse($operator->locked_until)->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => trans('auth.account_locked'),
                'retry_after' => Carbon::parse($operator->locked_until)->diffInSeconds(now()),
            ], 423);
        }

        $tenantId = $request->header('X-Tenant-ID') ?? $request->attributes->get('tenant_id');

        // 优先使用显式提供的 tenantId 查找映射
        $operatorTenant = null;
        if ($tenantId) {
            $operatorTenant = OperatorTenant::withoutGlobalScope(TenantScope::class)
                ->where('operator_id', $operator->operator_id)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
        }

        // 若未找到，自动从 operator_tenants 查找任一活跃映射
        if (! $operatorTenant) {
            $operatorTenant = OperatorTenant::withoutGlobalScope(TenantScope::class)
                ->where('operator_id', $operator->operator_id)
                ->where('is_active', true)
                ->first();
        }

        // 无租户关联：返回成功但不带 token，前端引导申请
        if (! $operatorTenant) {
            // 更新登录状态
            $operator->update(['last_login_at' => now()]);

            return response()->json([
                'success' => true,
                'data' => [
                    'operator' => $this->operatorToArray($operator),
                    'tenants' => [],
                    'no_tenant' => true,
                ],
            ]);
        }

        // 更新 request 的 tenant_id，确保后续中间件/控制器使用正确的租户
        $request->attributes->set('tenant_id', $operatorTenant->tenant_id);

        // 更新登录状态
        $operator->update([
            'login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ]);

        return $this->createOperatorTokenResponse($operator, $request);
    }

    /**
     * 租户管理员登出。
     */
    public function consoleLogout(Request $request): JsonResponse
    {
        return $this->logout($request);
    }

    /**
     * 获取当前租户用户信息（Operator 或 User）。
     */
    public function consoleUser(Request $request): JsonResponse
    {
        $tokenable = $request->user();
        $tenantId = $request->attributes->get('tenant_id');

        if ($tokenable instanceof Operator) {
            return response()->json([
                'success' => true,
                'data' => array_merge($this->operatorToArray($tokenable), [
                    'tenant_id' => $tenantId,
                ]),
            ]);
        }

        // 向后兼容：老 token 仍然是 User
        return response()->json([
            'success' => true,
            'data' => array_merge($this->userToArray($tokenable), [
                'tenant_id' => $tenantId,
            ]),
        ]);
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

        $userId = $this->mfaService->verifyChallenge(
            $request->challenge_token,
            $request->code,
            $request->type
        );

        if (! $userId) {
            return response()->json(['success' => false, 'message' => trans('auth.mfa_invalid_code')], 401);
        }

        $user = User::find($userId);
        if (! $user) {
            return response()->json(['success' => false, 'message' => trans('auth.user_not_found')], 401);
        }

        return $this->createTokenResponse($user, $request);
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
            return response()->json(['success' => false, 'message' => trans('auth.user_not_found')], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['success' => true, 'message' => trans('auth.email_already_verified')]);
        }

        // 验证 token（存储时已 hash，查询时也需 hash）
        $tokenRecord = DB::table('email_verification_tokens')
            ->where('email', $request->email)
            ->where('token', hash('sha256', $request->token))
            ->first();

        if (! $tokenRecord) {
            return response()->json(['success' => false, 'message' => trans('auth.invalid_token')], 400);
        }

        // 检查 token 是否过期（24小时）
        if (Carbon::parse($tokenRecord->created_at)->addHours(24)->isPast()) {
            DB::table('email_verification_tokens')
                ->where('id', $tokenRecord->id)
                ->delete();

            return response()->json(['success' => false, 'message' => trans('auth.token_expired')], 400);
        }

        // 标记已验证并删除 token
        $user->update(['email_verified_at' => now()]);
        DB::table('email_verification_tokens')
            ->where('id', $tokenRecord->id)
            ->delete();

        return response()->json(['success' => true, 'message' => trans('auth.email_verified')]);
    }

    /**
     * 重发验证邮件。
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user && ! $user->email_verified_at) {
            dispatch(new SendEmailVerificationJob($user->user_id));
        }

        // 始终返回成功，防止邮箱枚举
        return response()->json(['success' => true, 'message' => trans('auth.verification_sent')]);
    }

    /**
     * 忘记密码。
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            dispatch(new SendPasswordResetJob($user->user_id));
        }

        return response()->json(['success' => true, 'message' => trans('auth.reset_email_sent')]);
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
            return response()->json(['success' => false, 'message' => trans('auth.user_not_found')], 404);
        }

        // 验证 token（存储时已 hash，查询时也需 hash）
        $tokenRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', hash('sha256', $request->token))
            ->first();

        if (! $tokenRecord) {
            return response()->json(['success' => false, 'message' => trans('auth.invalid_token')], 400);
        }

        // 检查 token 是否过期（1小时）
        if (Carbon::parse($tokenRecord->created_at)->addHour()->isPast()) {
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            return response()->json(['success' => false, 'message' => trans('auth.token_expired')], 400);
        }

        // 重置密码并删除 token
        // User 模型有 'password' => 'hashed' cast，无需手动 Hash::make
        $user->update(['password' => $request->password]);
        $user->tokens()->delete();
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json(['success' => true, 'message' => trans('auth.password_reset_success')]);
    }

    /**
     * SSO 登录重定向。
     */
    public function ssoRedirect(Request $request, string $provider): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $ssoService = app(SsoService::class);
        $ssoProvider = $ssoService->getProvider($tenantId, $provider);

        if (! $ssoProvider) {
            return response()->json(['success' => false, 'message' => trans('auth.sso_provider_not_found')], 404);
        }

        $acsUrl = url("/api/v1/auth/sso/{$provider}/callback");
        $result = $ssoService->initiate($ssoProvider, $acsUrl);

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * SSO 回调。
     */
    public function ssoCallback(Request $request, string $provider): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $ssoService = app(SsoService::class);
        $ssoProvider = $ssoService->getProvider($tenantId, $provider);

        if (! $ssoProvider) {
            return response()->json(['success' => false, 'message' => trans('auth.sso_provider_not_found')], 404);
        }

        $acsUrl = url("/api/v1/auth/sso/{$provider}/callback");
        $result = $ssoService->handleCallback($ssoProvider, $request->all(), $acsUrl);
        $attributes = $result['attributes'] ?? [];

        $userResult = $ssoService->findOrCreateUser($ssoProvider, $attributes);
        $user = User::find($userResult['user_id'] ?? null);

        if (! $user) {
            return response()->json(['success' => false, 'message' => trans('auth.sso_failed')], 401);
        }

        return $this->createTokenResponse($user, $request);
    }

    /**
     * SAML SP 元数据。
     */
    public function samlMetadata(Request $request): JsonResponse
    {
        $ssoService = app(SsoService::class);
        $spEntityId = url('/api/v1/sso/saml/metadata');
        $acsUrl = url('/api/v1/sso/saml/callback');
        $metadata = $ssoService->buildSpMetadata($spEntityId, $acsUrl);

        return response($metadata)->header('Content-Type', 'application/xml');
    }

    /**
     * 列出租户 SSO 提供方。
     */
    public function ssoProviders(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $ssoService = app(SsoService::class);
        $providers = $ssoService->listProviders($tenantId);

        return response()->json(['success' => true, 'data' => $providers]);
    }

    /**
     * 创建/更新 SSO 提供方。
     */
    public function storeSsoProvider(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();

        $data = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string|in:saml,oidc',
            'config' => 'required|array',
        ]);

        $data['tenant_id'] = $tenantId;
        $ssoService = app(SsoService::class);

        $existing = $ssoService->getProvider($tenantId, $data['name']);
        if ($existing) {
            $ssoService->updateProvider($existing->sso_provider_id, $data);
        } else {
            $ssoService->createProvider($data);
        }

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    /**
     * 删除 SSO 提供方。
     */
    public function destroySsoProvider(Request $request, string $name): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $ssoService = app(SsoService::class);
        $provider = $ssoService->getProvider($tenantId, $name);

        if (! $provider) {
            return response()->json(['success' => false, 'message' => trans('auth.sso_provider_not_found')], 404);
        }

        $ssoService->deleteProvider($provider->sso_provider_id);

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    protected function createTokenResponse(User $user, Request $request): JsonResponse
    {
        $newToken = $user->createToken('auth_token');
        $token = $newToken->plainTextToken;
        $tokenId = $newToken->accessToken->id;

        $this->sessionService->recordSession(
            $user->user_id,
            $tokenId,
            $request->ip(),
            $request->userAgent()
        );

        event(new UserLoggedIn($user, $request->ip()));

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->userToArray($user),
                'tenant_id' => $request->attributes->get('tenant_id'),
                'auth_token' => $token,
                'refresh_token' => Str::random(60),
                'auth_token_expires_in' => 1800,
                'refresh_token_expires_in' => 604800,
            ],
        ]);
    }

    protected function userToArray(User $user): array
    {
        $role = null;

        $tenantId = request()->attributes->get('tenant_id');
        if ($tenantId) {
            $tenantUser = TenantUser::where('user_id', $user->user_id)
                ->where('tenant_id', $tenantId)
                ->first();
            $role = $tenantUser?->role;
        }

        return [
            'user_id' => $user->user_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role,
            'email_verified' => ! empty($user->email_verified_at),
        ];
    }

    /**
     * 基于 Operator 生成 Sanctum token 响应。
     */
    protected function createOperatorTokenResponse(Operator $operator, Request $request): JsonResponse
    {
        $newToken = $operator->createToken('operator_auth_token');
        $token = $newToken->plainTextToken;

        $tenants = $operator->tenants()
            ->where('operator_tenants.is_active', true)
            ->get()
            ->map(fn ($tenant) => [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'role' => $tenant->pivot->role,
            ])
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'operator' => [
                    'operator_id' => $operator->operator_id,
                    'name' => $operator->name,
                    'email' => $operator->email,
                    'scope' => $operator->scope,
                    'email_verified' => ! empty($operator->email_verified_at),
                ],
                'tenants' => $tenants,
                'tenant_id' => $request->attributes->get('tenant_id'),
                'auth_token' => $token,
                'auth_token_expires_in' => 1800,
            ],
        ]);
    }

    /**
     * Operator 转数组。
     */
    protected function operatorToArray(Operator $operator): array
    {
        $tenants = $operator->tenants()
            ->where('operator_tenants.is_active', true)
            ->get()
            ->map(fn ($tenant) => [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'role' => $tenant->pivot->role,
            ])
            ->toArray();

        return [
            'operator_id' => $operator->operator_id,
            'name' => $operator->name,
            'email' => $operator->email,
            'scope' => $operator->scope,
            'email_verified' => ! empty($operator->email_verified_at),
            'tenants' => $tenants,
        ];
    }
}
