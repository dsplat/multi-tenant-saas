<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\Rules\Password;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Jobs\SendEmailVerificationJob;
use MultiTenantSaas\Jobs\SendPasswordResetJob;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Services\MfaService;
use MultiTenantSaas\Services\SessionService;

/**
 * @OA\Tag(name="认证", description="用户认证相关接口")
 */
class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/v1/auth/login",
     *     summary="用户登录",
     *     description="使用邮箱和密码登录，返回 Bearer Token",
     *     tags={"认证"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"email","password"},
     *
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="登录成功",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="登录成功"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="token", type="string", example="1|abcdef123456"),
     *                 @OA\Property(property="user", type="object")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="认证失败", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=429, description="请求过多")
     * )
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! password_verify($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => trans('auth.login_failed')], 401);
        }

        if (! $user->is_active) {
            return response()->json(['success' => false, 'message' => trans('auth.account_suspended')], 403);
        }

        /** @var MfaService $mfaService */
        $mfaService = app(MfaService::class);

        // MFA 检查：若用户已启用 MFA，签发临时挑战令牌，需二次验证后方可换取正式令牌
        if ($mfaService->hasMfaEnabled($user->user_id)) {
            $tempToken = $user->createToken(
                'mfa-challenge',
                ['mfa-challenge'],
                now()->addMinutes(5)
            )->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => trans('auth.mfa_required'),
                'data' => [
                    'mfa_required' => true,
                    'mfa_token' => $tempToken,
                    'challenge_types' => $mfaService->getAvailableChallengeTypes($user->user_id),
                ],
            ]);
        }

        $newToken = $user->createToken('auth-token', ['*']);

        $tenantUser = TenantUser::where('user_id', $user->user_id)
            ->where('is_active', true)
            ->first();

        $this->recordSession($request, $user, $newToken->accessToken->id, $tenantUser?->tenant_id);

        Event::dispatch(new UserLoggedIn($user, $request->ip()));

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'tenant_id' => $tenantUser?->tenant_id,
                'token' => $newToken->plainTextToken,
            ],
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'platform_user',
        ]);

        if ($tenantId) {
            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'role' => 'end_user',
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }

        // 异步发送邮箱验证邮件
        SendEmailVerificationJob::dispatch($user->user_id);

        $token = $user->createToken('auth-token', ['*'])->plainTextToken;

        Event::dispatch(new UserRegistered($user, $tenantId));

        return response()->json([
            'success' => true,
            'message' => trans('auth.register_success'),
            'data' => [
                'user' => new UserResource($user),
                'tenant_id' => $tenantId,
                'token' => $token,
            ],
        ], 201);
    }

    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
        ]);

        $record = DB::table('email_verification_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $record || ! hash_equals($record->token, hash('sha256', $request->token))) {
            return response()->json(['success' => false, 'message' => trans('auth.verification_invalid')], 400);
        }

        if (now()->diffInHours($record->created_at) > 24) {
            DB::table('email_verification_tokens')->where('email', $request->email)->delete();

            return response()->json(['success' => false, 'message' => trans('auth.verification_expired')], 400);
        }

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $user->email_verified_at = now();
        $user->save();

        DB::table('email_verification_tokens')->where('email', $request->email)->delete();

        AuditService::log('verify_email', 'auth', $user->user_id);

        return response()->json(['success' => true, 'message' => trans('auth.email_verified')]);
    }

    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['success' => true, 'message' => trans('auth.verification_sent')]);
        }

        if ($user->email_verified_at) {
            return response()->json(['success' => false, 'message' => trans('auth.email_already_verified')], 400);
        }

        $this->sendEmailVerification($user);

        return response()->json(['success' => true, 'message' => trans('auth.verification_sent')]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            SendPasswordResetJob::dispatch($user->user_id);
        }

        return response()->json([
            'success' => true,
            'message' => trans('auth.password_reset_sent'),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'token' => 'required|string',
        ]);

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $resetRecord || ! hash_equals($resetRecord->token, hash('sha256', $request->token))) {
            return response()->json(['success' => false, 'message' => trans('auth.token_invalid')], 400);
        }

        if (now()->diffInMinutes($resetRecord->created_at) > 60) {
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            return response()->json(['success' => false, 'message' => trans('auth.reset_link_expired')], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $user->password = bcrypt($request->password);
        $user->save();

        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        // 删除该用户所有 token（强制重新登录）
        $user->tokens()->delete();

        AuditService::log('reset_password', 'auth', $user->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.password_reset_success'),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        $tenantUser = TenantUser::where('user_id', $user->user_id)
            ->where('is_active', true)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'tenant_id' => $tenantUser?->tenant_id,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        AuditService::log('logout', 'auth', $request->user()->user_id);

        return response()->json(['success' => true, 'message' => trans('auth.logout_success')]);
    }

    /**
     * MFA 验证端点（登录时密码校验通过后，二次验证换取正式令牌）
     *
     * 请求需携带临时挑战令牌（mfa-challenge）通过 auth:sanctum 认证。
     */
    public function mfaVerify(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'type' => 'nullable|string|in:totp,email,sms,recovery',
        ]);

        $user = $request->user();

        /** @var MfaService $mfaService */
        $mfaService = app(MfaService::class);
        $type = $request->input('type', 'totp');

        if (! $mfaService->verifyChallenge($user->user_id, $request->input('code'), $type)) {
            return response()->json([
                'success' => false,
                'message' => trans('auth.mfa_code_invalid'),
            ], 401);
        }

        // 撤销临时挑战令牌，签发正式访问令牌
        $request->user()->currentAccessToken()->delete();
        $newToken = $user->createToken('auth-token', ['*']);

        $tenantUser = TenantUser::where('user_id', $user->user_id)
            ->where('is_active', true)
            ->first();

        $this->recordSession($request, $user, $newToken->accessToken->id, $tenantUser?->tenant_id);

        Event::dispatch(new UserLoggedIn($user, $request->ip()));

        AuditService::log('mfa_verify', 'auth', $user->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.login_success'),
            'data' => [
                'user' => new UserResource($user),
                'tenant_id' => $tenantUser?->tenant_id,
                'token' => $newToken->plainTextToken,
            ],
        ]);
    }

    /**
     * 记录登录会话
     */
    private function recordSession(Request $request, User $user, int $tokenId, mixed $tenantId): void
    {
        app(SessionService::class)->recordSession(
            $user->user_id,
            $tokenId,
            $request->ip() ?: '',
            $request->userAgent() ?: '',
            $tenantId !== null ? (string) $tenantId : null
        );
    }

    /**
     * 发送邮箱验证邮件
     */
    private function sendEmailVerification(User $user): void
    {
        SendEmailVerificationJob::dispatch($user->user_id);
    }
}
