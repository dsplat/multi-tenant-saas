<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\CommerceFulfillmentHandler;
use MultiTenantSaas\Contracts\SupplyProvisionerContract;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;
use MultiTenantSaas\Modules\Commerce\Services\SupplyProvisionerRegistry;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 供给类履约基类（内容分销 / 积分商城 SKU 共用获取层，已决策）
 *
 * 框架侧动作：写 supply_grants（结算参数签约锁定）→ 调项目 Provisioner 落地 → 回填产物引用。
 * provision 失败整体回滚（不留半成品 grant），交由 commerce:retry 补偿。
 */
abstract class AbstractSupplyFulfillmentHandler implements CommerceFulfillmentHandler
{
    public function __construct(protected readonly SupplyProvisionerRegistry $provisioners) {}

    public function fulfill(CommerceOrderItem $item): void
    {
        $payload = $item->payload_snapshot ?? [];
        $tenantId = (int) $item->order->tenant_id;
        $durationDays = isset($payload['duration_days']) ? (int) $payload['duration_days'] : null;

        for ($i = 0; $i < $item->qty; $i++) {
            DB::transaction(function () use ($item, $payload, $tenantId, $durationDays) {
                $grant = SupplyGrant::withoutGlobalScope(TenantScope::class)->create([
                    'tenant_id' => $tenantId,
                    'sku_id' => $item->sku_id,
                    'source_order_id' => $item->order->order_id,
                    'status' => SupplyGrant::STATUS_ACTIVE,
                    'valid_from' => now(),
                    'valid_until' => $durationDays !== null && $durationDays > 0 ? now()->addDays($durationDays) : null,
                    'settlement' => $payload['settlement'] ?? null,
                ]);

                $instance = $this->provision($this->provisioners->resolve(), $grant, $item);
                $grant->update(['instance_payload' => $instance]);
            });
        }
    }

    /**
     * 项目侧落地动作（内容实例 / 积分商城商品）
     *
     * @return array 产物引用，写入 instance_payload
     */
    abstract protected function provision(SupplyProvisionerContract $provisioner, SupplyGrant $grant, CommerceOrderItem $item): array;

    public function revoke(CommerceOrderItem $item): void
    {
        $grants = SupplyGrant::withoutGlobalScope(TenantScope::class)
            ->where('source_order_id', $item->order->order_id)
            ->where('sku_id', $item->sku_id)
            ->whereIn('status', [SupplyGrant::STATUS_ACTIVE, SupplyGrant::STATUS_SUSPENDED])
            ->get();

        foreach ($grants as $grant) {
            $grant->update(['status' => SupplyGrant::STATUS_REVOKED]);
            $this->deprovisionSafely($grant);
        }
    }

    /**
     * 授权到期处理（由 commerce:retry 定时任务调用）
     */
    public function expire(SupplyGrant $grant): void
    {
        $grant->update(['status' => SupplyGrant::STATUS_EXPIRED]);
        $this->deprovisionSafely($grant);
    }

    /**
     * 停供（结算逾期等联动）：冻结授权，项目侧冻结新兑换
     */
    public function suspend(SupplyGrant $grant): void
    {
        $grant->update(['status' => SupplyGrant::STATUS_SUSPENDED]);
        $this->deprovisionSafely($grant);
    }

    /**
     * 恢复供给
     */
    public function resume(SupplyGrant $grant): void
    {
        $grant->update(['status' => SupplyGrant::STATUS_ACTIVE]);
    }

    /**
     * 反向联动项目侧实例（失败不阻断授权状态变更，仅告警）
     */
    protected function deprovisionSafely(SupplyGrant $grant): void
    {
        if (! $this->provisioners->has()) {
            return;
        }

        try {
            $this->provisioners->resolve()->deprovision($grant);
        } catch (\Throwable $e) {
            Log::warning('[Commerce] 供给反向联动失败', [
                'grant_id' => $grant->grant_id,
                'tenant_id' => $grant->tenant_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
