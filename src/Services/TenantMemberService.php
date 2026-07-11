<?php

namespace MultiTenantSaas\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use MultiTenantSaas\Mail\TenantInvitationMail;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\User;

/**
 * 租户成员管理服务
 * 用于 Console 后台的企业成员管理
 */
class TenantMemberService
{
    /**
     * 获取租户成员列表
     *
     * @param  int  $tenantId  租户ID
     * @param  array  $options  选项 ['search' => string, 'role' => string, 'perPage' => int]
     */
    public function getMembers(int $tenantId, array $options = []): LengthAwarePaginator
    {
        $query = TenantUser::where('tenant_id', $tenantId)
            ->with(['user:user_id,name,email,created_at']);

        // 搜索
        if (! empty($options['search'])) {
            $search = $options['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // 角色筛选
        if (! empty($options['role'])) {
            $query->where('role', $options['role']);
        }

        $perPage = $options['perPage'] ?? 15;

        return $query->orderBy('joined_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * 邀请新成员加入租户
     *
     * @param  int  $tenantId  租户ID
     * @param  string  $email  邮箱
     * @param  string  $role  角色（tenant_admin / end_user）
     * @param  int  $credits  初始积分
     * @param  int  $invitedBy  邀请人ID
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function inviteMember(int $tenantId, string $email, string $role, int $credits, int $invitedBy): array
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
                    'role' => $role,
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
                    'role' => 'end_user', // 全局角色默认为普通用户
                ]);

                // 添加到租户
                $tenantUser = TenantUser::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->user_id,
                    'role' => $role,
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
                    role: $role,
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
     * 更新成员角色
     *
     * @param  int  $tenantId  租户ID
     * @param  int  $userId  用户ID
     * @param  string  $role  新角色
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateMemberRole(int $tenantId, int $userId, string $role): array
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

        // 检查是否是最后一个管理员
        if ($tenantUser->role === 'tenant_admin' && $role !== 'tenant_admin') {
            $adminCount = TenantUser::where('tenant_id', $tenantId)
                ->where('role', 'tenant_admin')
                ->count();

            if ($adminCount <= 1) {
                return [
                    'success' => false,
                    'message' => trans('tenant.last_admin_role_protected'),
                ];
            }
        }

        $tenantUser->update(['role' => $role]);

        return [
            'success' => true,
            'message' => trans('tenant.role_updated'),
        ];
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

        // 检查是否是最后一个管理员
        if ($tenantUser->role === 'tenant_admin') {
            $adminCount = TenantUser::where('tenant_id', $tenantId)
                ->where('role', 'tenant_admin')
                ->count();

            if ($adminCount <= 1) {
                return [
                    'success' => false,
                    'message' => trans('tenant.last_admin_protected'),
                ];
            }
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
        $admins = TenantUser::where('tenant_id', $tenantId)
            ->where('role', 'tenant_admin')
            ->count();
        $users = TenantUser::where('tenant_id', $tenantId)
            ->where('role', 'end_user')
            ->count();

        return [
            'total' => $total,
            'admins' => $admins,
            'users' => $users,
        ];
    }
}
