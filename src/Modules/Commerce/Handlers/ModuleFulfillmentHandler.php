<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Handlers;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\CommerceFulfillmentHandler;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\ModuleEntitlement;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 模块加购履约 Handler
 *
 * payload: {module_name: string, duration_days?: int|null}
 * 履约 = 写 module_entitlements 权益 + ModuleManager::enableForTenant 开开关。
 * 权益/开关分离：租户自关开关不丢权益，回收只打权益层。
 */
class ModuleFulfillmentHandler implements CommerceFulfillmentHandler
{
    public function __construct(private readonly ModuleManager $moduleManager) {}

    public function fulfill(CommerceOrderItem $item): void
    {
        $payload = $item->payload_snapshot ?? [];
        $moduleName = (string) ($payload['module_name'] ?? '');

        if ($moduleName === '') {
            throw new DomainException('模块 SKU payload 缺少 module_name');
        }

        $tenantId = (int) $item->order->tenant_id;
        $durationDays = isset($payload['duration_days']) ? (int) $payload['duration_days'] : null;

        ModuleEntitlement::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenantId,
            'module_name' => $moduleName,
            'source' => ModuleEntitlement::SOURCE_PURCHASE,
            'source_order_id' => $item->order->order_id,
            'valid_from' => now(),
            'valid_until' => $durationDays !== null && $durationDays > 0 ? now()->addDays($durationDays) : null,
            'status' => ModuleEntitlement::STATUS_ACTIVE,
        ]);

        $this->moduleManager->enableForTenant($moduleName, $tenantId);
    }

    public function revoke(CommerceOrderItem $item): void
    {
        $tenantId = (int) $item->order->tenant_id;

        $affected = ModuleEntitlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('source_order_id', $item->order->order_id)
            ->where('status', ModuleEntitlement::STATUS_ACTIVE)
            ->update(['status' => ModuleEntitlement::STATUS_REVOKED]);

        if ($affected > 0) {
            $this->disableIfNoActiveEntitlement($tenantId, (string) ($item->payload_snapshot['module_name'] ?? ''));
        }
    }

    /**
     * 权益到期处理（由 commerce:retry 定时任务调用）
     */
    public function expire(ModuleEntitlement $entitlement): void
    {
        $entitlement->update(['status' => ModuleEntitlement::STATUS_EXPIRED]);

        $this->disableIfNoActiveEntitlement((int) $entitlement->tenant_id, $entitlement->module_name);
    }

    private function disableIfNoActiveEntitlement(int $tenantId, string $moduleName): void
    {
        if ($moduleName === '') {
            return;
        }

        $hasActive = ModuleEntitlement::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('module_name', $moduleName)
            ->where('status', ModuleEntitlement::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
            })
            ->exists();

        if ($hasActive) {
            return;
        }

        try {
            $this->moduleManager->disableForTenant($moduleName, $tenantId);
        } catch (\Throwable $e) {
            Log::warning('[Commerce] 模块开关回收失败', [
                'module' => $moduleName,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
