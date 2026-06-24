<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\AuditService;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !password_verify($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => '邮箱或密码错误'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => '账号已被禁用'], 403);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        $tenantUser = TenantUser::where('user_id', $user->user_id)
            ->where('is_active', true)
            ->first();

        AuditService::log('login', 'auth', $user->user_id, null, [
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'tenant_id' => $tenantUser?->tenant_id,
                'token' => $token,
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

        // 发送邮箱验证邮件
        $this->sendEmailVerification($user);

        $token = $user->createToken('auth-token')->plainTextToken;

        AuditService::log('register', 'auth', $user->user_id, null, [
            'email' => $user->email,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'message' => '注册成功，请查收邮箱完成验证',
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

        if (!$record || !hash_equals($record->token, hash('sha256', $request->token))) {
            return response()->json(['success' => false, 'message' => '验证链接无效'], 400);
        }

        if (now()->diffInHours($record->created_at) > 24) {
            DB::table('email_verification_tokens')->where('email', $request->email)->delete();
            return response()->json(['success' => false, 'message' => '验证链接已过期，请重新申请'], 400);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => '用户不存在'], 404);
        }

        $user->email_verified_at = now();
        $user->save();

        DB::table('email_verification_tokens')->where('email', $request->email)->delete();

        AuditService::log('verify_email', 'auth', $user->user_id);

        return response()->json(['success' => true, 'message' => '邮箱验证成功']);
    }

    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['success' => true, 'message' => '如果该邮箱已注册，您将收到验证邮件']);
        }

        if ($user->email_verified_at) {
            return response()->json(['success' => false, 'message' => '该邮箱已验证'], 400);
        }

        $this->sendEmailVerification($user);

        return response()->json(['success' => true, 'message' => '验证邮件已发送']);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            $token = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => hash('sha256', $token),
                    'created_at' => now(),
                ]
            );

            // 发送密码重置邮件
            Mail::to($user->email)->send(new PasswordResetMail($token, $user->email));
        }

        return response()->json([
            'success' => true,
            'message' => '如果该邮箱已注册，您将收到重置密码邮件',
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

        if (!$resetRecord || !hash_equals($resetRecord->token, hash('sha256', $request->token))) {
            return response()->json(['success' => false, 'message' => '重置链接无效或已过期'], 400);
        }

        if (now()->diffInMinutes($resetRecord->created_at) > 60) {
            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();
            return response()->json(['success' => false, 'message' => '重置链接已过期，请重新申请'], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => '用户不存在'], 404);
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
            'message' => '密码已重置，请重新登录',
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

        return response()->json(['success' => true, 'message' => '已退出登录']);
    }

    /**
     * 发送邮箱验证邮件
     */
    private function sendEmailVerification(User $user): void
    {
        $token = Str::random(64);

        DB::table('email_verification_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => hash('sha256', $token),
                'created_at' => now(),
            ]
        );

        try {
            Mail::to($user->email)->send(new EmailVerificationMail($token, $user->email));
        } catch (\Throwable $e) {
            \Log::warning('邮箱验证邮件发送失败', ['email' => $user->email, 'error' => $e->getMessage()]);
        }
    }
}
