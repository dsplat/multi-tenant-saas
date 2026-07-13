<?php

namespace MultiTenantSaas\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MultiTenantSaas\Models\Operator;
use MultiTenantSaas\Models\OperatorTenant;
use MultiTenantSaas\Models\User;

class OperatorService
{
    private const INVITE_TOKEN_TTL_DAYS = 7;

    public function __construct(
        private IdGenerator $idGenerator
    ) {}

    /**
     * 邀请操作员加入租户
     *
     * @return array{operator: Operator, invite_token: string}
     */
    public function invite(string $email, int $tenantId, string $role): array
    {
        DB::beginTransaction();
        try {
            $operator = Operator::where('email', $email)->first();

            if (! $operator) {
                $operator = Operator::create([
                    'operator_id' => $this->idGenerator->generate(),
                    'email' => $email,
                    'name' => explode('@', $email)[0],
                    'scope' => 'tenant',
                    'is_active' => false,
                    'invite_token' => Str::random(64),
                    'invite_expires_at' => now()->addDays(self::INVITE_TOKEN_TTL_DAYS),
                ]);
            } else {
                // 操作员已存在，刷新邀请令牌
                $operator->update([
                    'invite_token' => Str::random(64),
                    'invite_expires_at' => now()->addDays(self::INVITE_TOKEN_TTL_DAYS),
                ]);
            }

            // 检查是否已在该租户
            $existing = OperatorTenant::where('operator_id', $operator->operator_id)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing) {
                DB::rollBack();

                throw new \DomainException('Operator already belongs to this tenant.');
            }

            // 在租户中创建用户（未激活，无密码）
            $user = User::create([
                'user_id' => $this->idGenerator->generate(),
                'tenant_id' => $tenantId,
                'name' => $operator->name,
                'email' => $email,
                'password' => null,
                'role' => $role,
                'is_active' => false,
            ]);

            // 创建 operator_tenants 映射
            OperatorTenant::create([
                'operator_id' => $operator->operator_id,
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'role' => $role,
                'is_active' => false,
                'invited_at' => now(),
            ]);

            DB::commit();

            return [
                'operator' => $operator->fresh(),
                'invite_token' => $operator->invite_token,
            ];
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 接受邀请
     */
    public function acceptInvite(string $token, string $password): bool
    {
        DB::beginTransaction();
        try {
            $operator = Operator::where('invite_token', $token)->first();

            if (! $operator) {
                return false;
            }

            if ($operator->invite_expires_at->isPast()) {
                return false;
            }

            // 激活操作员并设置密码
            $operator->update([
                'password' => $password,
                'is_active' => true,
                'invite_token' => null,
                'invite_expires_at' => null,
            ]);

            // 同步密码到关联的未激活用户
            $operatorTenants = OperatorTenant::where('operator_id', $operator->operator_id)
                ->whereNull('accepted_at')
                ->get();

            foreach ($operatorTenants as $ot) {
                // 激活用户并同步密码
                User::where('user_id', $ot->user_id)->update([
                    'password' => $password,
                    'is_active' => true,
                ]);

                $ot->update(['accepted_at' => now()]);
            }

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 将已有操作员添加到新租户
     */
    public function addToTenant(int $operatorId, int $tenantId, string $role): OperatorTenant
    {
        DB::beginTransaction();
        try {
            $operator = Operator::findOrFail($operatorId);

            $existing = OperatorTenant::where('operator_id', $operatorId)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing) {
                throw new \DomainException('Operator already belongs to this tenant.');
            }

            // 在租户中创建用户
            $user = User::create([
                'user_id' => $this->idGenerator->generate(),
                'tenant_id' => $tenantId,
                'name' => $operator->name,
                'email' => $operator->email,
                'password' => $operator->password,
                'role' => $role,
                'is_active' => $operator->is_active,
            ]);

            $operatorTenant = OperatorTenant::create([
                'operator_id' => $operatorId,
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'role' => $role,
                'is_active' => true,
                'accepted_at' => $operator->is_active ? now() : null,
            ]);

            DB::commit();

            return $operatorTenant;
        } catch (\DomainException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 从租户中移除操作员
     */
    public function removeFromTenant(int $operatorId, int $tenantId): void
    {
        DB::beginTransaction();
        try {
            $ot = OperatorTenant::where('operator_id', $operatorId)
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $ot) {
                return;
            }

            // 软删除用户
            User::where('user_id', $ot->user_id)->delete();

            // 撤销用户令牌
            $user = User::withTrashed()->where('user_id', $ot->user_id)->first();
            if ($user) {
                $user->tokens()->delete();
            }

            // 停用映射
            $ot->update(['is_active' => false]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新操作员在指定租户的角色
     */
    public function updateRole(int $operatorId, int $tenantId, string $role): void
    {
        DB::beginTransaction();
        try {
            $ot = OperatorTenant::where('operator_id', $operatorId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $ot->update(['role' => $role]);

            // 撤销用户令牌，强制重新登录
            $user = User::where('user_id', $ot->user_id)->first();
            if ($user) {
                $user->tokens()->delete();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 列出租户下的活跃操作员
     */
    public function listByTenant(int $tenantId): Collection
    {
        return Operator::whereHas('tenants', function ($query) use ($tenantId) {
            $query->where('tenants.tenant_id', $tenantId)
                ->where('operator_tenants.is_active', true);
        })
            ->where('is_active', true)
            ->get();
    }

    /**
     * 列出操作员管理的租户
     */
    public function listTenants(int $operatorId): Collection
    {
        return OperatorTenant::where('operator_id', $operatorId)
            ->where('is_active', true)
            ->with('operator')
            ->get();
    }
}
