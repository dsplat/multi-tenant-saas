<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;

class UserProfileController extends Controller
{
    /**
     * 更新个人资料。
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:20',
            'avatar' => 'sometimes|nullable|string|max:500',
        ]);

        $user->update($data);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'email_verified_at' => $user->email_verified_at?->toISOString(),
                'phone_verified_at' => $user->phone_verified_at?->toISOString(),
            ],
            'message' => trans('common.updated'),
        ]);
    }

    /**
     * 修改密码。
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => trans('auth.current_password_incorrect'),
            ], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // 撤销所有其他 token（保留当前会话）
        $currentTokenId = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'success' => true,
            'message' => trans('auth.password_changed'),
        ]);
    }

    /**
     * 获取已绑定的 OAuth 账号列表。
     */
    public function oauthBindings(Request $request): JsonResponse
    {
        $user = $request->user();

        $bindings = OauthAccount::where('user_id', $user->user_id)
            ->get()
            ->map(fn (OauthAccount $account) => [
                'id' => $account->oauth_account_id,
                'provider' => $account->getBaseProvider(),
                'provider_user_id' => $account->provider_id,
                'nickname' => $account->provider_name,
                'avatar' => $account->provider_avatar,
                'created_at' => $account->created_at?->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $bindings,
        ]);
    }

    /**
     * 解绑 OAuth 账号。
     */
    public function unbindOAuth(Request $request, string $provider): JsonResponse
    {
        $user = $request->user();

        $account = OauthAccount::where('user_id', $user->user_id)
            ->where('provider', 'LIKE', "{$provider}%")
            ->first();

        if (! $account) {
            return response()->json([
                'success' => false,
                'message' => trans('auth.oauth_not_bound'),
            ], 404);
        }

        // 安全检查：如果用户没有密码且只有这一个 OAuth，不允许解绑
        $hasPassword = ! empty($user->password);
        $oauthCount = OauthAccount::where('user_id', $user->user_id)->count();

        if (! $hasPassword && $oauthCount <= 1) {
            return response()->json([
                'success' => false,
                'message' => trans('auth.cannot_unbind_last'),
            ], 422);
        }

        $account->delete();

        return response()->json([
            'success' => true,
            'message' => trans('auth.oauth_unbound'),
        ]);
    }
}
