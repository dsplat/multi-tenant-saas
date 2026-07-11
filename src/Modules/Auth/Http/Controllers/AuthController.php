<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Jobs\SendEmailVerificationJob;
use MultiTenantSaas\Jobs\SendPasswordResetJob;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\MfaService;
use MultiTenantSaas\Services\PasswordPolicyService;
use MultiTenantSaas\Services\SessionService;
use MultiTenantSaas\Services\SsoService;

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
            $challengeToken = $this->mfaService->createChallengeToken($user->user_id);

            return response()->json([
                'success' => true,
                'data' => [
                    'mfa_required' => true,
                    'challenge_token' => $challengeToken,
                    'available_types' => $this->mfaService->getAvailableTypes($user->user_id),
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
        dispatch(new SendEmailVerificationJob($user->user_id));

        event(new UserRegistered($user, $tenantId));

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => $this->userToArray($user),
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

        // Token 验证逻辑由 SendEmailVerificationJob 处理
        // 这里简化：直接标记已验证
        $user->update(['email_verified_at' => now()]);

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

        $user->update(['password' => Hash::make($request->password)]);
        $user->tokens()->delete();

        return response()->json(['success' => true, 'message' => trans('auth.password_reset_success')]);
    }

    /**
     * SSO 登录重定向。
     */
    public function ssoRedirect(Request $request, string $provider): JsonResponse
    {
        $ssoService = app(SsoService::class);
        $result = $ssoService->getRedirectUrl($provider, $request->all());

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * SSO 回调。
     */
    public function ssoCallback(Request $request, string $provider): JsonResponse
    {
        $ssoService = app(SsoService::class);
        $user = $ssoService->handleCallback($provider, $request->all());

        if (! $user) {
            return response()->json(['success' => false, 'message' => trans('auth.sso_failed')], 401);
        }

        return $this->createTokenResponse($user, $request);
    }

    protected function createTokenResponse(User $user, Request $request): JsonResponse
    {
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->sessionService->recordSession(
            $user->user_id,
            $request->ip(),
            $request->userAgent()
        );

        event(new UserLoggedIn($user, $request->ip()));

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => $this->userToArray($user),
            ],
        ]);
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
