<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantHierarchy;
use MultiTenantSaas\Scopes\TenantScope;
use RuntimeException;

/**
 * 跨租户服务（TASK-029）
 *
 * 职责：
 * - 父-子租户关系管理（企业集团场景）
 * - 资源共享池（在父-子关系内共享 AI 提示词、品牌资产等）
 * - 层级计费（父租户统一付费，与现有 SubscriptionService 集成）
 * - 跨租户资源访问授权
 *
 * 注意：本服务按显式 tenant_id 操作，绕过 TenantScope，
 * 安全由调用方（Controller/Service）保证用户有权访问目标租户。
 */
class CrossTenantService
{
    /** 跨租户访问 scope：父租户可查看子租户计费 */
    public const ACCESS_BILLING_VIEW = 'billing_view';

    /** 跨租户访问 scope：父租户可管理子租户用户 */
    public const ACCESS_USER_MANAGEMENT = 'user_management';

    /** 跨租户访问 scope：父租户可代表子租户发起 AI 请求 */
    public const ACCESS_AI_PROXY = 'ai_proxy';

    /**
     * 创建父-子租户关系
     *
     * @param  int  $parentTenantId  父租户 ID
     * @param  int  $childTenantId  子租户 ID
     * @param  string  $relationType  关系类型（subsidiary/branch/division）
     * @param  array<string, mixed>|null  $permissionScope  权限范围初始数据
     *
     * @throws RuntimeException 父/子租户不存在、自引用、关系已存在时抛出
     */
    public function createRelationship(
        int $parentTenantId,
        int $childTenantId,
        string $relationType = TenantHierarchy::TYPE_SUBSIDIARY,
        ?array $permissionScope = null
    ): TenantHierarchy {
        if ($parentTenantId === $childTenantId) {
            throw new RuntimeException(trans('tenant.hierarchy_self_reference'));
        }

        if (! Tenant::where('tenant_id', $parentTenantId)->exists()) {
            throw new RuntimeException(trans('tenant.hierarchy_parent_not_found'));
        }
        if (! Tenant::where('tenant_id', $childTenantId)->exists()) {
            throw new RuntimeException(trans('tenant.hierarchy_child_not_found'));
        }

        if (! in_array($relationType, TenantHierarchy::TYPES, true)) {
            throw new RuntimeException(
                trans('tenant.hierarchy_relation_type_invalid', ['type' => $relationType])
            );
        }

        $existing = TenantHierarchy::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $parentTenantId)
            ->where('child_tenant_id', $childTenantId)
            ->exists();

        if ($existing) {
            throw new RuntimeException(trans('tenant.hierarchy_already_exists'));
        }

        return TenantHierarchy::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $parentTenantId,
            'child_tenant_id' => $childTenantId,
            'relation_type' => $relationType,
            'permission_scope' => $permissionScope ?? ['shared_resources' => [], 'cross_tenant_access' => []],
            'is_active' => true,
        ]);
    }

    /**
     * 删除（停用）父-子关系
     */
    public function removeRelationship(int $parentTenantId, int $childTenantId): bool
    {
        $hierarchy = TenantHierarchy::findByChild($parentTenantId, $childTenantId);

        if ($hierarchy === null) {
            return false;
        }

        return (bool) $hierarchy->delete();
    }

    /**
     * 获取父租户的所有有效子关系
     *
     * @return Collection<int, TenantHierarchy>
     */
    public function getChildren(int $parentTenantId): Collection
    {
        return TenantHierarchy::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $parentTenantId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * 获取子租户的父租户关系
     */
    public function getParent(int $childTenantId): ?TenantHierarchy
    {
        return TenantHierarchy::withoutGlobalScope(TenantScope::class)
            ->where('child_tenant_id', $childTenantId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * 资源共享：在父-子关系内共享某资源
     *
     * @param  int  $parentTenantId  父租户 ID
     * @param  int  $childTenantId  子租户 ID
     * @param  string  $resourceType  资源类型（如 ai_prompt）
     * @param  int  $resourceId  资源 ID
     * @param  string[]  $permissions  权限列表（默认 read）
     * @return TenantHierarchy 更新后的关系实例
     *
     * @throws RuntimeException 关系不存在时抛出
     */
    public function shareResource(
        int $parentTenantId,
        int $childTenantId,
        string $resourceType,
        int $resourceId,
        array $permissions = ['read']
    ): TenantHierarchy {
        $hierarchy = $this->getOrFailRelationship($parentTenantId, $childTenantId);

        $hierarchy->addSharedResource($resourceType, $resourceId, $permissions);

        Log::info(trans('tenant.hierarchy_resource_shared', [
            'parent' => $parentTenantId,
            'child' => $childTenantId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]));

        return $hierarchy->fresh();
    }

    /**
     * 撤销共享资源
     */
    public function revokeSharedResource(
        int $parentTenantId,
        int $childTenantId,
        string $resourceType,
        int $resourceId
    ): TenantHierarchy {
        $hierarchy = $this->getOrFailRelationship($parentTenantId, $childTenantId);
        $hierarchy->removeSharedResource($resourceType, $resourceId);

        return $hierarchy->fresh();
    }

    /**
     * 查询子租户可访问的共享资源列表
     *
     * @return array<int, array{resource_type:string, resource_id:int, permissions:string[]}>
     */
    public function listSharedResources(int $parentTenantId, int $childTenantId): array
    {
        $hierarchy = $this->getOrFailRelationship($parentTenantId, $childTenantId);

        return $hierarchy->sharedResources();
    }

    /**
     * 跨租户资源访问授权：授予指定 scope
     *
     * @param  string  $accessScope  ACCESS_BILLING_VIEW / ACCESS_USER_MANAGEMENT / ACCESS_AI_PROXY
     */
    public function grantCrossTenantAccess(
        int $parentTenantId,
        int $childTenantId,
        string $accessScope,
        bool $allowed = true
    ): TenantHierarchy {
        $hierarchy = $this->getOrFailRelationship($parentTenantId, $childTenantId);
        $hierarchy->grantAccess($accessScope, $allowed);

        return $hierarchy->fresh();
    }

    /**
     * 撤销跨租户访问授权
     */
    public function revokeCrossTenantAccess(
        int $parentTenantId,
        int $childTenantId,
        string $accessScope
    ): TenantHierarchy {
        return $this->grantCrossTenantAccess($parentTenantId, $childTenantId, $accessScope, false);
    }

    /**
     * 校验父租户对子租户是否拥有指定访问 scope
     */
    public function hasCrossTenantAccess(
        int $parentTenantId,
        int $childTenantId,
        string $accessScope
    ): bool {
        $hierarchy = TenantHierarchy::findByChild($parentTenantId, $childTenantId);

        if ($hierarchy === null || ! $hierarchy->is_active) {
            return false;
        }

        return $hierarchy->canAccess($accessScope);
    }

    /**
     * 层级计费：聚合父租户下所有子租户的财务记录
     *
     * 集成 SubscriptionService：父租户统一付费时，将子租户的订阅支出
     * 汇总到父租户名下，便于统一对账。
     *
     * @param  int  $parentTenantId  父租户 ID
     * @param  string  $period  计费周期（YYYY-MM）
     * @return array{
     *   parent_tenant_id:int,
     *   period:string,
     *   child_count:int,
     *   total_amount:float,
     *   breakdown:array<int, array{tenant_id:int,amount:float}>
     * }
     */
    public function aggregateBilling(int $parentTenantId, string $period): array
    {
        $children = $this->getChildren($parentTenantId);

        $breakdown = [];
        $total = 0.0;

        foreach ($children as $child) {
            $childTenantId = (int) $child->child_tenant_id;

            // 仅聚合授权 billing_view 的子租户
            if (! $child->canAccess(self::ACCESS_BILLING_VIEW)) {
                continue;
            }

            $amount = $this->sumTenantBilling($childTenantId, $period);
            $breakdown[] = [
                'tenant_id' => $childTenantId,
                'amount' => $amount,
            ];
            $total += $amount;
        }

        return [
            'parent_tenant_id' => $parentTenantId,
            'period' => $period,
            'child_count' => count($breakdown),
            'total_amount' => round($total, 2),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * 层级计费：将聚合后的费用从父租户账户扣款
     *
     * 与 SubscriptionService 集成：在父租户订阅续费周期内，
     * 将子租户实际消耗汇总后从父租户账户扣款。
     *
     * @param  int  $parentTenantId  父租户 ID
     * @param  string  $period  计费周期（YYYY-MM）
     * @return array 聚合计费结果（参见 aggregateBilling）
     *
     * @throws RuntimeException 父租户不存在时抛出
     */
    public function billAtParent(int $parentTenantId, string $period): array
    {
        $parent = Tenant::find($parentTenantId);
        if ($parent === null) {
            throw new RuntimeException(trans('tenant.hierarchy_parent_not_found'));
        }

        $aggregate = $this->aggregateBilling($parentTenantId, $period);

        if ($aggregate['total_amount'] <= 0) {
            return $aggregate;
        }

        // 在父租户名下记录聚合计费财务条目
        DB::table('financial_records')->insert([
            'financial_record_id' => app(IdGeneratorContract::class)->generate(),
            'tenant_id' => $parentTenantId,
            'type' => 'hierarchy_billing',
            'amount' => $aggregate['total_amount'],
            'status' => 'pending',
            'metadata' => json_encode([
                'period' => $period,
                'child_count' => $aggregate['child_count'],
                'breakdown' => $aggregate['breakdown'],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info(trans('tenant.hierarchy_billing_aggregated', [
            'parent' => $parentTenantId,
            'period' => $period,
            'total' => $aggregate['total_amount'],
        ]));

        return $aggregate;
    }

    // ========== 内部工具 ==========

    /**
     * 获取关系，不存在则抛出异常
     *
     * @throws RuntimeException
     */
    protected function getOrFailRelationship(int $parentTenantId, int $childTenantId): TenantHierarchy
    {
        $hierarchy = TenantHierarchy::findByChild($parentTenantId, $childTenantId);

        if ($hierarchy === null) {
            throw new RuntimeException(
                trans('tenant.hierarchy_not_found', [
                    'parent' => $parentTenantId,
                    'child' => $childTenantId,
                ])
            );
        }

        return $hierarchy;
    }

    /**
     * 统计指定租户在指定周期的财务金额合计
     */
    protected function sumTenantBilling(int $tenantId, string $period): float
    {
        $sum = DB::table('financial_records')
            ->where('tenant_id', $tenantId)
            ->where('metadata->period', $period)
            ->whereIn('status', ['paid', 'completed'])
            ->sum('amount');

        return (float) $sum;
    }
}
