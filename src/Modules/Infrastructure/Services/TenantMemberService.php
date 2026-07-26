<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use MultiTenantSaas\Mail\TenantInvitationMail;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;

/**
 * 租户用户管理服务
 * 用于 Console 后台管理租户的 User（被服务用户）。
 *
 * 注意：User 不拥有角色（角色仅属 Operator，经 operator_tenants 关联）。
 * 本服务仅管理用户与租户的关联及积分，不涉及任何角色逻辑。
 */
class TenantMemberService
{
    /**
     * 获取租户用户列表
     *
     * @param  int  $tenantId  租户ID
     * @param  array  $options  选项 ['search' => string, 'perPage' => int]
     */
    public function getMembers(int $tenantId, array $options = []): LengthAwarePaginator
    {
        $query = TenantUser::where('tenant_id', $tenantId)
            ->with(['user:user_id,name,email,created_at']);

        // 搜索
        if (! empty($options['search'])) {
            $search = $options['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->search($search);
            });
        }

        $perPage = $options['perPage'] ?? 15;

        return $query->orderBy('joined_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * 邀请新用户加入租户（User 不拥有角色）
     *
     * @param  int  $tenantId  租户ID
     * @param  string  $email  邮箱
     * @param  int  $credits  初始积分
     * @param  int  $invitedBy  邀请人ID
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function inviteMember(int $tenantId, string $email, int $credits, int $invitedBy): array
    {
        DB::beginTransaction();
        try {
            // 检查用户是否已存在
            $user = User::where('email', $email)->first();

            if ($user) {
                // 用户已存在，检查是否已经是该租户成员
                $existingMember = TenantUser::where('tenant_id', $tenantId)
                    ->where('user_id', $user->user_id)
                    ->first();

                if ($existingMember) {
                    DB::rollBack();

                    return [
                        'success' => false,
                        'message' => trans('tenant.member_already_exists'),
                    ];
                }

                // 添加到租户
                $tenantUser = TenantUser::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->user_id,
                    'credits' => $credits,
                    'joined_at' => now(),
                ]);
            } else {
                // 创建新用户（密码使用随机值，需要通过邮件重置）
                $password = Str::random(16);
                $user = User::create([
                    'name' => explode('@', $email)[0], // 临时名称
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => 'platform_user', // 平台用户类型（非租户角色）
                ]);

                // 添加到租户
                $tenantUser = TenantUser::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->user_id,
                    'credits' => $credits,
                    'joined_at' => now(),
                ]);
            }

            // 发送邀请邮件
            try {
                $tenant = Tenant::find($tenantId);
                $inviter = User::find($invitedBy);
                Mail::to($email)->send(new TenantInvitationMail(
                    email: $email,
                    tenantName: $tenant?->name ?? '',
                    inviterName: $inviter?->name ?? 'System',
                    inviteUrl: url("/invite?tenant={$tenantId}&email=" . urlencode($email)),
                ));
            } catch (\Throwable $e) {
                Log::warning('[TenantMemberService] Failed to send invitation email', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => trans('tenant.member_invited'),
                'data' => [
                    'user' => $user,
                    'tenant_user' => $tenantUser,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => trans('tenant.invite_failed') . ': ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 调整成员积分
     *
     * @param  int  $tenantId  租户ID
     * @param  int  $userId  用户ID
     * @param  int  $credits  新积分值
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateMemberCredits(int $tenantId, int $userId, int $credits): array
    {
        $tenantUser = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (! $tenantUser) {
            return [
                'success' => false,
                'message' => trans('tenant.member_not_found'),
            ];
        }

        $tenantUser->update(['credits' => $credits]);

        return [
            'success' => true,
            'message' => trans('credit.update_success'),
        ];
    }

    /**
     * 移除成员
     *
     * @param  int  $tenantId  租户ID
     * @param  int  $userId  用户ID
     * @return array ['success' => bool, 'message' => string]
     */
    public function removeMember(int $tenantId, int $userId): array
    {
        $tenantUser = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (! $tenantUser) {
            return [
                'success' => false,
                'message' => trans('tenant.member_not_found'),
            ];
        }

        $tenantUser->delete();

        return [
            'success' => true,
            'message' => trans('tenant.member_removed'),
        ];
    }

    /**
     * 获取成员详情
     *
     * @param  int  $tenantId  租户ID
     * @param  int  $userId  用户ID
     */
    public function getMember(int $tenantId, int $userId): ?TenantUser
    {
        return TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->with('user')
            ->first();
    }

    /**
     * 获取成员统计信息
     *
     * @param  int  $tenantId  租户ID
     */
    public function getMemberStats(int $tenantId): array
    {
        $total = TenantUser::where('tenant_id', $tenantId)->count();
        $active = TenantUser::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        return [
            'total' => $total,
            'active' => $active,
        ];
    }
}
