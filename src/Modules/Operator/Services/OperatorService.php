<?php

namespace MultiTenantSaas\Modules\Operator\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\IdGenerator;
use MultiTenantSaas\Modules\Operator\Mail\OperatorInvitationMail;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;

/**
 * Operator 服务（Operator 直连租户模式）
 *
 * 设计原则：
 * - Operator 即管理者：通过 operator_tenants 直接关联到 Tenant，不再创建 User
 * - User 只在租户后台开启"开放注册"后才产生，走独立的 /auth 注册路径
 * - 所有 Operator 在租户中的角色、状态、邀请流程都通过 operator_tenants 维护
 *
 * 本类历史版本曾通过 operator_tenants.user_id 创建并同步 User 记录，
 * 现已彻底移除该路径，避免双账户体系造成的认知冲突。
 */
class OperatorService
{
    private const INVITE_TOKEN_TTL_DAYS = 7;

    public function __construct(
        private IdGenerator $idGenerator
    ) {}

    /**
     * 邀请 Operator 加入团队（租户）
     *
     * 流程：
     * - 若该邮箱对应的 Operator 不存在，则创建一个未激活的 Operator（无密码，待接受邀请后设置）
     * - 若已存在，则刷新其 invite_token
     * - 在 operator_tenants 表插入待接受关联（is_active=false, invited_at=now）
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
                $operator->update([
                    'invite_token' => Str::random(64),
                    'invite_expires_at' => now()->addDays(self::INVITE_TOKEN_TTL_DAYS),
                ]);
            }

            $existing = OperatorTenant::where('operator_id', $operator->operator_id)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing) {
                DB::rollBack();
                throw new \DomainException('Operator already belongs to this tenant.');
            }

            // 直接建立 Operator-Tenant 关联，不再创建 User
            OperatorTenant::create([
                'operator_id' => $operator->operator_id,
                'tenant_id' => $tenantId,
                'role' => $role,
                'is_active' => false,
                'invited_at' => now(),
            ]);

            DB::commit();

            $this->sendInvitationEmail($operator, $tenantId, $role);

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
     * 接受邀请：激活 Operator 账户并设置密码，将 operator_tenants 关联置为 active
     */
    public function acceptInvite(string $token, string $password): bool
    {
        DB::beginTransaction();
        try {
            $operator = Operator::where('invite_token', $token)->first();
            if (! $operator) {
                return false;
            }
            if ($operator->invite_expires_at?->isPast()) {
                return false;
            }

            $operator->update([
                'password' => Hash::make($password),
                'is_active' => true,
                'invite_token' => null,
                'invite_expires_at' => null,
            ]);

            // 将该 Operator 所有待接受关联置为 active
            OperatorTenant::where('operator_id', $operator->operator_id)
                ->whereNull('accepted_at')
                ->update([
                    'is_active' => true,
                    'accepted_at' => now(),
                ]);

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 将已有 Operator 添加到新团队（租户）
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

            $operatorTenant = OperatorTenant::create([
                'operator_id' => $operatorId,
                'tenant_id' => $tenantId,
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
     * 从团队中移除 Operator：停用关联并撤销其在该租户下签发的所有 token
     *
     * 注意：Operator 主体记录不被删除，仅断开与团队的关联
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

            // 撤销该 Operator 的所有 Sanctum token
            $operator = Operator::find($operatorId);
            if ($operator) {
                $operator->tokens()->delete();
            }

            $ot->update(['is_active' => false]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新 Operator 在指定团队中的角色
     */
    public function updateRole(int $operatorId, int $tenantId, string $role): void
    {
        DB::beginTransaction();
        try {
            $ot = OperatorTenant::where('operator_id', $operatorId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $ot->update(['role' => $role]);

            // 撤销 Operator token，强制重新登录（使新角色权限生效）
            $operator = Operator::find($operatorId);
            if ($operator) {
                $operator->tokens()->delete();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 列出团队下的活跃 Operator
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

    public function getOperator(int $operatorId): Operator
    {
        return Operator::findOrFail($operatorId);
    }

    public function updateOperator(int $operatorId, array $data): Operator
    {
        $operator = Operator::findOrFail($operatorId);
        $operator->update($data);

        return $operator->fresh();
    }

    public function toggleStatus(int $operatorId): Operator
    {
        $operator = Operator::findOrFail($operatorId);
        $operator->update(['is_active' => ! $operator->is_active]);

        return $operator->fresh();
    }

    /**
     * 重新发送邀请邮件
     */
    public function resendInvite(int $operatorId): array
    {
        $operator = Operator::findOrFail($operatorId);
        if ($operator->is_active) {
            throw new \DomainException('Operator is already active.');
        }
        if (! $operator->invite_token) {
            throw new \DomainException('Operator has no pending invitation.');
        }

        $operator->update([
            'invite_token' => Str::random(64),
            'invite_expires_at' => now()->addDays(self::INVITE_TOKEN_TTL_DAYS),
        ]);

        $operatorTenant = OperatorTenant::where('operator_id', $operatorId)
            ->whereNull('accepted_at')
            ->first();

        if ($operatorTenant) {
            $this->sendInvitationEmail($operator, $operatorTenant->tenant_id, $operatorTenant->role);
        }

        return [
            'operator' => $operator->fresh(),
            'invite_token' => $operator->invite_token,
        ];
    }

    /**
     * 列出 Operator 管理的团队
     */
    public function listTenants(int $operatorId): Collection
    {
        return OperatorTenant::where('operator_id', $operatorId)
            ->where('is_active', true)
            ->with('operator')
            ->get();
    }

    /**
     * 发送邀请邮件
     */
    private function sendInvitationEmail(Operator $operator, int $tenantId, string $role): void
    {
        try {
            $tenant = Tenant::find($tenantId);
            $inviteUrl = url("/operator/accept-invite?token={$operator->invite_token}");

            Mail::to($operator->email)->send(new OperatorInvitationMail(
                operatorName: $operator->name,
                tenantName: $tenant?->name ?? 'Platform',
                inviteUrl: $inviteUrl,
                role: $role,
            ));
        } catch (\Throwable $e) {
            Log::warning('Operator invitation email failed: ' . $e->getMessage());
        }
    }
}
