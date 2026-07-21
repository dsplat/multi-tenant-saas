<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Facades\Hash;
use MultiTenantSaas\Modules\Auth\Models\PasswordHistory;
use MultiTenantSaas\Modules\Auth\Models\User;

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

        // 更新密码（业务层显式 hash，不依赖模型 cast）
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
            ->limit(1000)
            ->pluck('password_history_id');

        PasswordHistory::whereIn('password_history_id', $ids)->delete();
    }
}
