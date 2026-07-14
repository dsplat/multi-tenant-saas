<?php

namespace MultiTenantSaas\Modules\User\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\IdGenerator;

class UserService
{
    public function __construct(
        private IdGenerator $idGenerator
    ) {}

    /**
     * 获取用户列表（带分页和筛选）
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = User::query()->with(['tenants']);

        // 搜索（name 或 email）
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // 按角色筛选
        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        // 按租户筛选
        if (! empty($filters['tenant_id'])) {
            $query->whereHas('tenants', function ($q) use ($filters) {
                $q->where('tenants.tenant_id', $filters['tenant_id']);
            });
        }

        // 按邮箱验证状态筛选
        if (isset($filters['email_verified'])) {
            if ($filters['email_verified']) {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }

        // 排序
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // 分页
        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    /**
     * 注册公共平台用户（自动附加到平台默认租户，并赠送欢迎积分）
     *
     * @param  array{name: string, email?: string, phone?: string, password?: string, avatar?: string}  $data
     */
    public function registerAsPlatformUser(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => isset($data['password']) ? Hash::make($data['password']) : null,
                'avatar' => $data['avatar'] ?? null,
                'role' => 'platform_user',
            ]);

            $platformTenantId = (int) config('id.platform_tenant_id');
            $this->attachToTenant($user->user_id, $platformTenantId, 'end_user');
            $this->giveWelcomeCredits($user->user_id, $platformTenantId);

            return $user->fresh();
        });
    }

    /**
     * 注册为企业租户用户（直接挂到目标租户，不经过公共租户）
     *
     * @param  array{name: string, email?: string, phone?: string, password?: string, avatar?: string}  $data
     * @param  int  $tenantId  目标企业租户 ID
     * @param  int  $welcomeCredits  欢迎积分数
     */
    public function registerAsTenantUser(array $data, int $tenantId, int $welcomeCredits = 0): User
    {
        return DB::transaction(function () use ($data, $tenantId, $welcomeCredits) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => isset($data['password']) ? Hash::make($data['password']) : null,
                'avatar' => $data['avatar'] ?? null,
                'role' => 'platform_user',
            ]);

            $this->attachToTenant($user->user_id, $tenantId, 'end_user');

            if ($welcomeCredits > 0) {
                $account = CreditAccount::where('tenant_id', $tenantId)
                    ->where('user_id', $user->user_id)
                    ->first();
                if ($account) {
                    $account->recharge($user->user_id, $welcomeCredits, '新用户注册赠送积分', ['source' => 'welcome_bonus']);
                }
            }

            return $user->fresh();
        });
    }

    /**
     * 通过 OAuth 登录（找或新建用户，并确保租户附件）
     *
     * @param  array{provider: string, provider_id: string, provider_name?: string, provider_email?: string, provider_avatar?: string, access_token?: string, refresh_token?: string, token_expires_at?: Carbon|null, metadata?: array}  $oauthData
     */
    public function loginViaOauth(array $oauthData, int $tenantId, string $tenantRole = 'end_user'): User
    {
        return DB::transaction(function () use ($oauthData, $tenantId, $tenantRole) {
            $oauthAccount = OauthAccount::where('provider', $oauthData['provider'])
                ->where('provider_id', $oauthData['provider_id'])
                ->first();

            $isNewUser = false;

            if ($oauthAccount) {
                $oauthAccount->update([
                    'access_token' => $oauthData['access_token'] ?? $oauthAccount->access_token,
                    'refresh_token' => $oauthData['refresh_token'] ?? $oauthAccount->refresh_token,
                    'token_expires_at' => $oauthData['token_expires_at'] ?? $oauthAccount->token_expires_at,
                    'provider_name' => $oauthData['provider_name'] ?? $oauthAccount->provider_name,
                    'provider_avatar' => $oauthData['provider_avatar'] ?? $oauthAccount->provider_avatar,
                    'metadata' => $oauthData['metadata'] ?? $oauthAccount->metadata,
                ]);

                $user = $oauthAccount->user;
            } else {
                $isNewUser = true;
                $name = $oauthData['provider_name'] ?? ('用户' . substr($oauthData['provider_id'], -4));
                $email = $oauthData['provider_email'] ?? null;

                if ($email && User::where('email', $email)->exists()) {
                    $email = null;
                }
                if (! $email) {
                    $email = $oauthData['provider'] . '_' . strtolower(substr($oauthData['provider_id'], 0, 20)) . '@oauth.local';
                }
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'avatar' => $oauthData['provider_avatar'] ?? null,
                    'role' => 'platform_user',
                    'password' => Hash::make(Str::random(32)),
                ]);

                OauthAccount::create([
                    'user_id' => $user->user_id,
                    'tenant_id' => $tenantId,
                    'provider' => $oauthData['provider'],
                    'provider_id' => $oauthData['provider_id'],
                    'provider_email' => $oauthData['provider_email'] ?? null,
                    'provider_name' => $oauthData['provider_name'] ?? null,
                    'provider_avatar' => $oauthData['provider_avatar'] ?? null,
                    'access_token' => $oauthData['access_token'] ?? null,
                    'refresh_token' => $oauthData['refresh_token'] ?? null,
                    'token_expires_at' => $oauthData['token_expires_at'] ?? null,
                    'metadata' => $oauthData['metadata'] ?? null,
                ]);
            }

            if (! $user->tenants()->where('tenants.tenant_id', $tenantId)->exists()) {
                $this->attachToTenant($user->user_id, $tenantId, $tenantRole);
                if ($isNewUser) {
                    $this->giveWelcomeCredits($user->user_id, $tenantId);
                }
            }

            return $user->fresh();
        });
    }

    /**
     * 赠送欢迎积分（新用户首次加入租户时）
     * 积分数量由 config('id.platform_welcome_credits') 控制，设为 0 则跳过。
     */
    private function giveWelcomeCredits(int $userId, int $tenantId): void
    {
        $credits = (int) config('id.platform_welcome_credits', 500);
        if ($credits <= 0) {
            return;
        }

        $account = CreditAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId, 'account_type' => 'personal'],
            ['balance' => 0, 'gift_balance' => 0, 'recharge_balance' => 0, 'total_recharged' => 0, 'total_consumed' => 0, 'status' => 'active']
        );

        $account->gift($userId, $credits, 30, '新用户注册赠送积分', ['source' => 'welcome_bonus']);
    }

    /**
     * 创建用户
     */
    public function create(array $data): User
    {
        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => isset($data['password']) ? Hash::make($data['password']) : null,
                'avatar' => $data['avatar'] ?? null,
                'role' => $data['role'] ?? 'platform_user',
                'email_verified_at' => $data['email_verified'] ?? false ? now() : null,
            ]);

            if (! empty($data['tenant_id'])) {
                $this->attachToTenant($user->user_id, $data['tenant_id'], $data['tenant_role'] ?? 'end_user');
            }

            DB::commit();

            return $user->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新用户
     */
    public function update(int $userId, array $data): User
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($userId);

            $updateData = [
                'name' => $data['name'] ?? $user->name,
                'email' => $data['email'] ?? $user->email,
                'phone' => $data['phone'] ?? $user->phone,
                'avatar' => $data['avatar'] ?? $user->avatar,
                'role' => $data['role'] ?? $user->role,
            ];

            if (! empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            if (isset($data['email_verified'])) {
                $updateData['email_verified_at'] = $data['email_verified'] ? now() : null;
            }

            $user->update($updateData);

            DB::commit();

            return $user->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 删除用户（软删除）
     */
    public function delete(int $userId): bool
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($userId);
            $result = $user->delete();

            DB::commit();

            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 查找用户
     */
    public function find(int $userId): User
    {
        return User::with(['tenants', 'creditAccounts', 'oauthAccounts'])->findOrFail($userId);
    }

    /**
     * 将用户关联到租户
     */
    public function attachToTenant(int $userId, int $tenantId, string $role = 'end_user', int $credits = 0): void
    {
        $user = User::findOrFail($userId);
        $tenant = Tenant::findOrFail($tenantId);

        if ($user->tenants()->where('tenants.tenant_id', $tenantId)->exists()) {
            $user->tenants()->updateExistingPivot($tenantId, [
                'role' => $role,
                'credits' => $credits,
                'is_active' => true,
            ]);
        } else {
            $user->tenants()->attach($tenantId, [
                'tenant_user_id' => $this->idGenerator->generate(),
                'role' => $role,
                'credits' => $credits,
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }

        CreditAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            [
                'account_type' => 'personal',
                'balance' => 0,
                'total_recharged' => 0,
                'total_consumed' => 0,
            ]
        );
    }

    /**
     * 从租户移除用户
     */
    public function detachFromTenant(int $userId, int $tenantId): void
    {
        $user = User::findOrFail($userId);
        $user->tenants()->detach($tenantId);
    }

    /**
     * 更新用户在租户中的角色
     */
    public function updateTenantRole(int $userId, int $tenantId, string $role): void
    {
        $user = User::findOrFail($userId);

        $user->tenants()->updateExistingPivot($tenantId, [
            'role' => $role,
        ]);
    }

    /**
     * 获取用户的租户列表
     */
    public function getUserTenants(int $userId): Collection
    {
        $user = User::findOrFail($userId);

        return $user->tenants()
            ->withPivot('role', 'credits', 'is_active', 'joined_at')
            ->orderBy('tenant_users.joined_at', 'desc')
            ->get();
    }

    /**
     * 重置用户密码
     */
    public function resetPassword(int $userId, string $newPassword): User
    {
        $user = User::findOrFail($userId);

        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        return $user->fresh();
    }

    /**
     * 启用/禁用用户
     */
    public function toggleStatus(int $userId, bool $isActive): User
    {
        $user = User::findOrFail($userId);

        if ($isActive && $user->trashed()) {
            $user->restore();
        } elseif (! $isActive && ! $user->trashed()) {
            $user->delete();
        }

        return $user->fresh();
    }

    /**
     * 获取用户统计信息
     */
    public function getUserStats(int $userId): array
    {
        $user = User::findOrFail($userId);

        return [
            'total_tenants' => $user->tenants()->count(),
            'total_credits' => $user->creditAccounts()->sum('balance'),
            'oauth_connections' => $user->oauthAccounts()->count(),
        ];
    }
}
