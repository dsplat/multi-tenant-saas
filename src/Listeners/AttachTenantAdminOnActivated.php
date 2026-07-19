<?php

namespace MultiTenantSaas\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Events\TenantActivated;
use MultiTenantSaas\Modules\Auth\Models\Role;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 监听 TenantActivated：将发起该租户 onboarding 的 Operator
 * 通过 operator_tenants 中间表关联为该租户管理员
 *
 * 触发时机：平台后台审核通过租户申请（调用 TenantOnboardingService::approveTenant）
 *
 * 行为：
 * - 从 tenant.onboarding_operator_id 取发起者 Operator ID
 * - 在 operator_tenants 中插入关联：role=tenant_admin, is_active=true, accepted_at=now()
 * - 如已存在关联记录（重复触发），跳过插入并记录 warning
 * - 不创建 users/tenant_users 记录（Operator 直连租户模式）
 *
 * 幂等：同 (operator_id, tenant_id) 已存在则跳过
 */
class AttachTenantAdminOnActivated
{
    public bool $afterCommit = true;

    public function handle(TenantActivated $event): void
    {
        $tenant = $event->tenant;

        if (! isset($tenant->onboarding_operator_id) || ! $tenant->onboarding_operator_id) {
            Log::warning('TenantActivated: tenant has no onboarding_operator_id, cannot attach admin', [
                'tenant_id' => $tenant->tenant_id,
            ]);

            return;
        }

        $operatorId = (int) $tenant->onboarding_operator_id;
        $tenantId = (int) $tenant->tenant_id;

        // 注意：OperatorTenant 模型用 BelongsToTenant trait，默认带 TenantScope。
        // 监听器在 platform 域或 tenant context 不匹配的情况下查询，会被作用域过滤导致误判为不存在。
        // 必须显式 withoutGlobalScope 才能拿到真实关联记录。
        $existing = OperatorTenant::withoutGlobalScope(TenantScope::class)
            ->where('operator_id', $operatorId)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($existing) {
            // 已存在关联，可能是重复触发；保证 is_active=true 即可
            if (! $existing->is_active || ! $existing->accepted_at) {
                $existing->update([
                    'is_active' => true,
                    'accepted_at' => $existing->accepted_at ?? now(),
                ]);
            }

            Log::info('TenantActivated: operator_tenants link already exists, ensuring active', [
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
            ]);

            return;
        }

        // 取该租户的 tenant_admin 角色 ID（onboarding complete 时已预置）
        $adminRole = Role::where('tenant_id', $tenantId)
            ->where('name', 'tenant_admin')
            ->first();

        OperatorTenant::withoutGlobalScope(TenantScope::class)->create([
            'operator_id' => $operatorId,
            'tenant_id' => $tenantId,
            'role' => 'tenant_admin',
            'role_id' => $adminRole?->role_id,
            'is_active' => true,
            'invited_at' => null,
            'accepted_at' => now(),
        ]);

        Log::info('TenantActivated: operator attached as tenant_admin', [
            'tenant_id' => $tenantId,
            'operator_id' => $operatorId,
            'role_id' => $adminRole?->role_id,
        ]);
    }
}
