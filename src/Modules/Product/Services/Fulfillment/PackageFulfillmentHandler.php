<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Services\Fulfillment;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Services\FulfillmentRegistry;
use MultiTenantSaas\Modules\Order\Support\AbstractOrderFulfillmentHandler;
use MultiTenantSaas\Modules\Order\Support\EntityTypes;
use MultiTenantSaas\Modules\Product\Models\PackageItem;
use MultiTenantSaas\Modules\Product\Services\PackageService;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Package 履约处理器：递归拆解组成项分发到对应叶子 handler
 *
 * 订单 entity=package:ID → 加载 package_items → 逐项按 item_type 从
 * FulfillmentRegistry 取 handler 分发（组成项作为 $item 传入，叶子身份
 * 由 AbstractOrderFulfillmentHandler::resolveEntityId 解析）。
 *
 * 防环 + 深度上限：子 package 场景（组成项 item_type=package）递归展开，
 * visited 集合防环，深度上限 3 层。叶子无 handler 记日志跳过（如 product
 * 实物走物流，无自动履约）。单项失败抛出 → 整体回滚支付事务。
 */
class PackageFulfillmentHandler extends AbstractOrderFulfillmentHandler
{
    /** 递归拆解最大深度（防嵌套环失控） */
    private const MAX_DEPTH = 3;

    public function __construct(
        protected FulfillmentRegistry $registry,
        protected PackageService $packageService,
    ) {}

    public function entityType(): string
    {
        return EntityTypes::PACKAGE;
    }

    public function fulfill(Order $order, mixed $item): void
    {
        $packageId = $this->resolveEntityId($order, $item);

        if (! $packageId || ! $order->user_id) {
            return;
        }

        $this->expand(
            (int) $order->tenant_id,
            (int) $packageId,
            $order,
            1,
            [EntityTypes::PACKAGE . ':' . $packageId => true],
        );
    }

    /**
     * 递归展开 package 组成并分发叶子履约
     *
     * @param  array<string, bool>  $visited  已展开实体键（防环）
     */
    protected function expand(int $tenantId, int $packageId, Order $order, int $depth, array $visited): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new UnprocessableEntityHttpException(
                "Package nesting exceeds max depth " . self::MAX_DEPTH . " at package [{$packageId}]"
            );
        }

        foreach ($this->packageService->listItems($tenantId, $packageId) as $component) {
            $key = $component->item_type . ':' . $component->item_id;

            if (isset($visited[$key])) {
                Log::warning('[Package] 组成环引用，跳过', ['package_id' => $packageId, 'item' => $key]);

                continue;
            }
            $visited[$key] = true;

            // 子 package：递归展开（当前 addItem 禁止直接引用 package，此为放开后的预留路径）
            if ($component->item_type === EntityTypes::PACKAGE) {
                $this->expand($tenantId, (int) $component->item_id, $order, $depth + 1, $visited);

                continue;
            }

            $handler = $this->registry->get($component->item_type);

            if (! $handler) {
                Log::info('[Package] 叶子无履约 handler，跳过', [
                    'order_no' => $order->order_no,
                    'item'     => $key,
                ]);

                continue;
            }

            /** @var PackageItem $component 组成项作为履约上下文（携带 item_type/item_id/quantity） */
            $handler->fulfill($order, $component);
        }
    }
}
