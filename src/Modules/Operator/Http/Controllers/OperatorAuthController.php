<?php

namespace MultiTenantSaas\Modules\Operator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Jobs\SendOperatorPasswordResetJob;
use MultiTenantSaas\Jobs\SendOperatorVerificationJob;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * Operator 认证控制器
 *
 * 提供 Operator（运营/管理身份）的注册、登录、邮箱验证、密码重置等功能。
 * 与 AuthController（终端用户 User）并行，互不影响。
 */
class OperatorAuthController extends Controller
{
    public function __construct(
        protected MailerService $mailer,
    ) {}

    /**
     * Operator 注册。
     *
     * POST /operator-auth/register
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:operators,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $idGenerator = app(IdGeneratorContract::class);

        $operator = Operator::create([
            'operator_id' => $idGenerator->generate(),
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        // 异步发送验证邮件
        dispatch(new SendOperatorVerificationJob($operator->operator_id));

        return response()->json([
            'success' => true,
            'data' => [
                'operator' => $this->operatorToArray($operator),
                'message' => trans('auth.registration_success'),
            ],
        ], 201);
    }

    /**
     * Operator 登录。
     *
     * POST /operator-auth/login
     */
    public function login(Request $request): JsonResponse
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

        // 成功登录，重置登录尝试
        $operator->update([
            'login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ]);

        return $this->createOperatorTokenResponse($operator, $request);
    }

    /**
     * 获取当前 Operator 信息。
     *
     * GET /operator-auth/me（需认证）
     */
    public function me(Request $request): JsonResponse
    {
        $operator = $request->user();

        if (! $operator instanceof Operator) {
            return response()->json(['success' => false, 'message' => 'Not authenticated as operator'], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $this->operatorToArray($operator),
        ]);
    }

    /**
     * Operator 登出。
     *
     * POST /operator-auth/logout（需认证）
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => trans('auth.logged_out')]);
    }

    /**
     * 邮箱验证。
     *
     * POST /operator-auth/verify-email
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        $operator = Operator::where('email', $request->email)->first();

        if (! $operator) {
            return response()->json(['success' => false, 'message' => trans('auth.user_not_found')], 404);
        }

        if ($operator->email_verified_at) {
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
        $operator->update(['email_verified_at' => now()]);
        DB::table('email_verification_tokens')
            ->where('id', $tokenRecord->id)
            ->delete();

        return response()->json(['success' => true, 'message' => trans('auth.email_verified')]);
    }

    /**
     * 重发验证邮件。
     *
     * POST /operator-auth/resend-verification
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $operator = Operator::where('email', $request->email)->first();

        if ($operator && ! $operator->email_verified_at) {
            dispatch(new SendOperatorVerificationJob($operator->operator_id));
        }

        // 始终返回成功，防止邮箱枚举
        return response()->json(['success' => true, 'message' => trans('auth.verification_sent')]);
    }

    /**
     * 忘记密码。
     *
     * POST /operator-auth/forgot-password
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $operator = Operator::where('email', $request->email)->first();

        if ($operator) {
            dispatch(new SendOperatorPasswordResetJob($operator->operator_id));
        }

        return response()->json(['success' => true, 'message' => trans('auth.reset_email_sent')]);
    }

    /**
     * 重置密码。
     *
     * POST /operator-auth/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $operator = Operator::where('email', $request->email)->first();

        if (! $operator) {
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
        $operator->update(['password' => $request->password]);
        $operator->tokens()->delete();
        DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->delete();

        return response()->json(['success' => true, 'message' => trans('auth.password_reset_success')]);
    }

    /**
     * 基于 Operator 生成 Sanctum token 响应。
     */
    protected function createOperatorTokenResponse(Operator $operator, Request $request): JsonResponse
    {
        $newToken = $operator->createToken('operator_auth_token');
        $token = $newToken->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'operator' => $this->operatorToArray($operator),
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
