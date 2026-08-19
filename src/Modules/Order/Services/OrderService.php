<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Contracts\OrderableEntity;
use MultiTenantSaas\Modules\Order\Events\OrderPaid;
use MultiTenantSaas\Modules\Order\Events\OrderRefunded;
use MultiTenantSaas\Modules\Order\Models\ConsumptionRecord;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Models\OrderItem;
use MultiTenantSaas\Modules\Order\Support\EntityTypes;
use MultiTenantSaas\Modules\Pay\Services\TradePayService;
use MultiTenantSaas\Modules\Product\Models\ProductSku;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 统一订单服务（一切交易皆订单）
 *
 * 三条支付路径（底座委托 Pay 模块 TradePayService）：
 * - cash：现金支付（框架支付网关，生产禁 mock）
 * - points：虚拟支付（渠道由项目层注册，事务内扣减即时置 paid）
 * - mixed：虚拟折现抵扣 + 现金补差（SalesConfig 租户级配置）
 *
 * 履约委托 FulfillmentRegistry（按订单行 entity_type 分发）。
 * 计佣基数 = 实付现金（total_amount），虚拟抵扣部分不计佣。
 *
 * 实体绑定：orders.entity_type/entity_id（主实体）+ secondary_entity_*（次要关联），
 * 归因上下文走 orders.source JSON；行级实体见 order_items.entity_type/entity_id。
 */
class OrderService
{
    public function __construct(
        protected IdGeneratorContract $idGenerator,
        protected TradePayService $tradePayService,
        protected FulfillmentRegistry $fulfillmentRegistry,
    ) {}

    // ========== 下单 ==========

    /**
     * 创建订单
     *
     * $data: order_type, pay_method, items[], points_to_use?, source?, metadata?,
     *        entity_type?, entity_id?, secondary_entity_type?, secondary_entity_id?
     * items 两种形态：
     * - ['sku_id' => x, 'quantity' => n]：从 SKU 解析价格/名称/规格
     * - ['entity_type', 'entity_id', 'item_name', 'unit_price', 'points_unit_price', 'spec', 'quantity']：外部直接给价（课程/兑换等）
     */
    public function createOrder(int $tenantId, ?int $userId, array $data): Order
    {
        TenantContext::setTenantId((string) $tenantId);

        $orderType = $data['order_type'] ?? Order::TYPE_PRODUCT;
        $payMethod = $data['pay_method'] ?? Order::PAY_CASH;

        if (! in_array($payMethod, [Order::PAY_CASH, Order::PAY_POINTS, Order::PAY_MIXED], true)) {
            throw new UnprocessableEntityHttpException("Invalid pay_method: {$payMethod}");
        }

        $items = $this->resolveItems($tenantId, $data['items'] ?? [], $payMethod);
        if (empty($items)) {
            throw new UnprocessableEntityHttpException('Order items cannot be empty');
        }

        $cashTotal = 0.0;
        $pointsTotal = 0;
        foreach ($items as $item) {
            $cashTotal += (float) $item['unit_price'] * $item['quantity'];
            $pointsTotal += (int) $item['points_unit_price'] * $item['quantity'];
        }

        // 按支付方式拆分现金/虚拟
        [$totalAmount, $pointsAmount] = $this->tradePayService->splitPayment(
            $tenantId, $userId, $payMethod, $cashTotal, $pointsTotal, (int) ($data['points_to_use'] ?? 0)
        );

        return DB::transaction(function () use ($tenantId, $userId, $orderType, $payMethod, $totalAmount, $pointsAmount, $items, $data) {
            $order = Order::create([
                'order_id'      => $this->idGenerator->generate(),
                'tenant_id'     => $tenantId,
                'user_id'       => $userId,
                'order_no'      => Order::generateOrderNo(),
                'order_type'    => $orderType,
                'total_amount'  => $totalAmount,
                'points_amount' => $pointsAmount,
                'pay_method'    => $payMethod,
                'entity_type'   => $data['entity_type'] ?? null,
                'entity_id'     => isset($data['entity_id']) ? (string) $data['entity_id'] : null,
                'secondary_entity_type' => $data['secondary_entity_type'] ?? null,
                'secondary_entity_id'   => isset($data['secondary_entity_id']) ? (string) $data['secondary_entity_id'] : null,
                'status'        => Order::STATUS_PENDING,
                'source'        => $data['source'] ?? null,
                'metadata'      => $data['metadata'] ?? null,
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'item_id'           => $this->idGenerator->generate(),
                    'tenant_id'         => $tenantId,
                    'order_id'          => $order->order_id,
                    'sku_id'            => $item['sku_id'] ?? null,
                    'product_id'        => $item['product_id'] ?? null,
                    'entity_type'       => $item['entity_type'] ?? EntityTypes::SKU,
                    'entity_id'         => isset($item['entity_id']) ? (string) $item['entity_id'] : null,
                    'item_name'         => $item['item_name'],
                    'spec'              => $item['spec'] ?? null,
                    'quantity'          => $item['quantity'],
                    'unit_price'        => $item['unit_price'],
                    'points_unit_price' => $item['points_unit_price'],
                    'amount'            => round((float) $item['unit_price'] * $item['quantity'], 2),
                ]);
            }

            return $order;
        });
    }

    /**
     * 为可下单实体创建订单（跨层契约：任何实现 OrderableEntity 的实体均可下单）
     *
     * $options: pay_method?, quantity?, points_to_use?, source?, metadata?,
     *           secondary_entity_type?, secondary_entity_id?
     */
    public function createForEntity(int $tenantId, ?int $userId, OrderableEntity $entity, array $options = []): Order
    {
        if (! $entity->isPurchasable()) {
            throw new UnprocessableEntityHttpException("Entity [{$entity->getEntityType()}:{$entity->getEntityId()}] is not purchasable");
        }

        if (! EntityTypes::isValid($entity->getEntityType())) {
            throw new UnprocessableEntityHttpException("Invalid entity_type: {$entity->getEntityType()}");
        }

        return $this->createOrder($tenantId, $userId, array_merge($options, [
            'order_type'  => $options['order_type'] ?? Order::TYPE_PRODUCT,
            'entity_type' => $entity->getEntityType(),
            'entity_id'   => $entity->getEntityId(),
            'items'       => $options['items'] ?? [[
                'entity_type'       => $entity->getEntityType(),
                'entity_id'         => $entity->getEntityId(),
                'item_name'         => $options['item_name'] ?? $entity->getEntityType(),
                'unit_price'        => $entity->getPayableAmount(),
                'points_unit_price' => (int) ($options['points_unit_price'] ?? 0),
                'quantity'          => (int) ($options['quantity'] ?? 1),
            ]],
        ]));
    }

    /**
     * 解析订单行：sku_id 形态从 SKU 取价，其余形态以传入为准
     */
    protected function resolveItems(int $tenantId, array $rawItems, string $payMethod): array
    {
        $items = [];

        foreach ($rawItems as $raw) {
            $quantity = max(1, (int) ($raw['quantity'] ?? 1));

            if (! empty($raw['sku_id'])) {
                $sku = ProductSku::where('sku_id', $raw['sku_id'])
                    ->where('tenant_id', $tenantId)
                    ->first();

                if (! $sku || ! $sku->isActive()) {
                    throw new NotFoundHttpException("SKU [{$raw['sku_id']}] not found or inactive");
                }

                // 自建 SKU 校验库存（镜像 SKU 库存以源表为准，由源模块履约时校验）
                if (! $sku->isMirror() && $sku->stock > 0 && $sku->stock < $quantity) {
                    throw new UnprocessableEntityHttpException("SKU [{$sku->name}] insufficient stock");
                }

                $items[] = [
                    'sku_id'            => $sku->sku_id,
                    'product_id'        => $sku->product_id,
                    'entity_type'       => $sku->isMirror() ? ($sku->ref_type ?? EntityTypes::SKU) : EntityTypes::SKU,
                    'entity_id'         => $sku->ref_id !== null ? (string) $sku->ref_id : null,
                    'item_name'         => $sku->name,
                    'spec'              => $sku->spec_attrs,
                    'quantity'          => $quantity,
                    'unit_price'        => (float) $sku->price,
                    'points_unit_price' => (int) $sku->points_price,
                ];

                continue;
            }

            if (empty($raw['item_name'])) {
                throw new UnprocessableEntityHttpException('Order item requires sku_id or item_name');
            }

            $items[] = [
                'entity_type'       => $raw['entity_type'] ?? EntityTypes::SKU,
                'entity_id'         => isset($raw['entity_id']) ? (string) $raw['entity_id'] : null,
                'item_name'         => $raw['item_name'],
                'spec'              => $raw['spec'] ?? null,
                'quantity'          => $quantity,
                'unit_price'        => (float) ($raw['unit_price'] ?? 0),
                'points_unit_price' => (int) ($raw['points_unit_price'] ?? 0),
            ];
        }

        // 纯虚拟支付要求全部行有积分价
        if ($payMethod === Order::PAY_POINTS) {
            foreach ($items as $item) {
                if ($item['points_unit_price'] <= 0) {
                    throw new UnprocessableEntityHttpException("Item [{$item['item_name']}] has no points price");
                }
            }
        }

        return $items;
    }

    // ========== 支付 ==========

    /**
     * 发起支付
     *
     * - points：虚拟支付，即时确认
     * - 现金金额为 0（免费）：直接置 paid
     * - cash/mixed：走框架支付网关，返回支付参数
     */
    public function initiatePayment(int $tenantId, string $orderNo, ?string $openid = null): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $order = $this->getOrder($tenantId, $orderNo);

        if ($order->status !== Order::STATUS_PENDING) {
            throw new UnprocessableEntityHttpException("Order status is '{$order->status}', cannot pay");
        }

        // 虚拟支付：即时确认
        if ($order->pay_method === Order::PAY_POINTS || (float) $order->total_amount <= 0) {
            $this->confirmPayment($orderNo, null);

            return [
                'order_no' => $orderNo,
                'mode'     => 'virtual',
                'paid'     => true,
            ];
        }

        $amountFen = (int) bcmul((string) $order->total_amount, '100');
        $result = $this->tradePayService->createCashPayment(
            userId: $order->user_id ?? 0,
            amountFen: $amountFen,
            openid: $openid,
            orderNo: $order->order_no,
        );

        if ($result['payment_order_id']) {
            $order->update(['payment_order_id' => $result['payment_order_id']]);
        }

        return [
            'order_no'     => $orderNo,
            'total_amount' => $order->total_amount,
            'pay_data'     => $result['pay_data'],
        ];
    }

    /**
     * 确认支付（支付回调 / 虚拟支付共用）
     *
     * 幂等 + lockForUpdate 并发安全；事务内：扣虚拟资产（points/mixed）、扣库存（自建 SKU）、履约分发、置 paid
     * 事务外：写消费流水、派发 OrderPaid（amount=实付现金）
     */
    public function confirmPayment(string $orderNo, ?string $transactionId = null): bool
    {
        $order = Order::where('order_no', $orderNo)->first();

        if (! $order) {
            Log::warning("OrderService: order not found for notify: {$orderNo}");

            return false;
        }

        // 回调可能在无租户上下文环境到达，后续虚拟资产操作依赖租户上下文
        TenantContext::setTenantId((string) $order->tenant_id);

        // 快速幂等短路
        if ($order->status !== Order::STATUS_PENDING) {
            return true;
        }

        $paid = DB::transaction(function () use ($order, $transactionId) {
            $locked = Order::where('order_id', $order->order_id)->lockForUpdate()->first();

            if ($locked->status !== Order::STATUS_PENDING) {
                return false;
            }

            // 虚拟资产扣减（事务内，渠道由项目层注册；未注册时优雅报错回滚）
            if ($locked->points_amount > 0 && $locked->user_id) {
                $this->tradePayService->consumeVirtual(
                    (int) $locked->tenant_id,
                    (int) $locked->user_id,
                    (int) $locked->points_amount,
                    $locked->order_no,
                );
            }

            // 库存扣减：仅自建 SKU（镜像 SKU 由源模块履约）
            foreach ($locked->items as $item) {
                $this->deductSkuStock((int) $locked->tenant_id, $item);
            }

            // 履约分发（按订单行 entity_type 委托 Registry）
            $this->fulfillOrder($locked);

            $locked->update([
                'status'   => Order::STATUS_PAID,
                'paid_at'  => now(),
                'metadata' => array_merge($locked->metadata ?? [], array_filter([
                    'transaction_id' => $transactionId,
                ])),
            ]);

            return true;
        });

        if ($paid) {
            $fresh = $order->fresh();
            $this->writeConsumptionRecord($fresh);

            event(new OrderPaid(
                tenantId: (int) $fresh->tenant_id,
                orderType: $fresh->order_type,
                orderId: (int) $fresh->order_id,
                orderNo: $fresh->order_no,
                buyerUserId: $fresh->user_id ? (int) $fresh->user_id : null,
                amount: (float) $fresh->total_amount,
                context: [
                    'goods_id' => $fresh->items->first()?->entity_id
                        ?? $fresh->items->first()?->product_id
                        ?? $fresh->items->first()?->sku_id,
                ],
            ));

            Log::info("OrderService: payment confirmed for order {$orderNo}");
        }

        return $paid;
    }

    /**
     * 退款：现金走网关、虚拟资产原路返还、库存回退
     *
     * 佣金冲销等下游动作由项目层订阅 OrderRefunded 事件完成。
     */
    public function refundOrder(int $tenantId, string $orderNo, ?string $reason = null): Order
    {
        TenantContext::setTenantId((string) $tenantId);

        $order = $this->getOrder($tenantId, $orderNo);

        if (! $order->canRefund()) {
            throw new UnprocessableEntityHttpException("Order status '{$order->status}' does not allow refund");
        }

        $needExternalRefund = $order->status === Order::STATUS_PAID
            && $order->payment_order_id
            && (float) $order->total_amount > 0;

        // 1. 事务内完成本地状态变更（库存回退 + 虚拟资产返还）
        $order = DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status'      => Order::STATUS_REFUNDED,
                'refunded_at' => now(),
                'metadata'    => array_merge($order->metadata ?? [], [
                    'refund_reason' => $reason,
                ]),
            ]);

            foreach ($order->items as $item) {
                $this->restoreSkuStock((int) $order->tenant_id, $item);
            }

            // 虚拟资产原路返还（本地事务完成）
            if ($order->points_amount > 0 && $order->user_id) {
                $this->tradePayService->refundVirtual(
                    (int) $order->tenant_id,
                    (int) $order->user_id,
                    (int) $order->points_amount,
                    $order->order_no,
                );
            }

            return $order->fresh();
        });

        // 1.5 退款事件（项目层订阅做佣金冲销等）
        event(new OrderRefunded(
            tenantId: (int) $order->tenant_id,
            orderType: $order->order_type,
            orderId: (int) $order->order_id,
            orderNo: $order->order_no,
            buyerUserId: $order->user_id ? (int) $order->user_id : null,
            amount: (float) $order->total_amount,
            pointsAmount: (int) $order->points_amount,
            reason: $reason,
        ));

        // 2. 事务外调用外部退款网关
        if ($needExternalRefund) {
            try {
                $this->tradePayService->refundCash(
                    (int) $order->tenant_id,
                    $order->order_no,
                    (float) $order->total_amount,
                    $reason,
                );
            } catch (\Throwable $e) {
                $order->update([
                    'status'   => Order::STATUS_REFUND_FAILED,
                    'metadata' => array_merge($order->metadata ?? [], [
                        'refund_error'     => $e->getMessage(),
                        'refund_failed_at' => now()->toIso8601String(),
                    ]),
                ]);

                Log::error("OrderService: external refund failed, order={$order->order_no} marked refund_failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $order->fresh();
    }

    // ========== 查询 ==========

    public function getOrder(int $tenantId, string $orderNo): Order
    {
        TenantContext::setTenantId((string) $tenantId);

        return Order::where('order_no', $orderNo)
            ->where('tenant_id', $tenantId)
            ->with('items')
            ->firstOrFail();
    }

    public function getList(int $tenantId, array $filters = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Order::where('tenant_id', $tenantId);

        if (! empty($filters['order_type'])) {
            $query->where('order_type', $filters['order_type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        $paginator = $query->orderByDesc('created_at')
            ->paginate(
                $filters['per_page'] ?? 20,
                ['*'],
                'page',
                max(1, (int) ($filters['page'] ?? 1))
            );

        return [
            'data'      => $paginator->items(),
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    // ========== 消费流水 ==========

    /**
     * 写消费流水（幂等：同一订单只记一笔）
     */
    public function writeConsumptionRecord(Order $order): void
    {
        if (! $order->user_id) {
            return;
        }

        $exists = ConsumptionRecord::where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->order_id)
            ->exists();

        if ($exists) {
            return;
        }

        ConsumptionRecord::create([
            'record_id'     => $this->idGenerator->generate(),
            'tenant_id'     => $order->tenant_id,
            'user_id'       => $order->user_id,
            'order_id'      => $order->order_id,
            'order_type'    => $order->order_type,
            'cash_amount'   => $order->total_amount,
            'points_amount' => $order->points_amount,
            'consumed_at'   => $order->paid_at ?? now(),
        ]);
    }

    // ========== 履约（按订单行 entity_type 分发） ==========

    /**
     * 支付确认后的履约分发（事务内执行，与扣款原子一致）
     *
     * handler 未注册的 entity_type 静默跳过（项目层按需注册）。
     */
    protected function fulfillOrder(Order $order): void
    {
        if (! $order->user_id) {
            return;
        }

        foreach ($order->items as $item) {
            $handler = $this->fulfillmentRegistry->get((string) $item->entity_type);

            if ($handler) {
                $handler->fulfill($order, $item);
            }
        }
    }

    // ========== 库存（仅自建 SKU；镜像 SKU 由源模块履约） ==========

    protected function deductSkuStock(int $tenantId, OrderItem $item): void
    {
        $sku = $this->findOwnedSku($tenantId, $item);
        if (! $sku) {
            return;
        }

        if ($sku->stock > 0) {
            $affected = ProductSku::where('sku_id', $sku->sku_id)
                ->where('stock', '>=', $item->quantity)
                ->decrement('stock', $item->quantity);

            if ($affected === 0) {
                Log::error("OrderService: sku stock decrement failed, order item={$item->item_id}, data inconsistency");
            }
        }

        ProductSku::where('sku_id', $sku->sku_id)->increment('sold_count', $item->quantity);
    }

    protected function restoreSkuStock(int $tenantId, OrderItem $item): void
    {
        $sku = $this->findOwnedSku($tenantId, $item);
        if (! $sku) {
            return;
        }

        if ($sku->stock > 0) {
            ProductSku::where('sku_id', $sku->sku_id)->increment('stock', $item->quantity);
        }

        ProductSku::where('sku_id', $sku->sku_id)
            ->where('sold_count', '>=', $item->quantity)
            ->decrement('sold_count', $item->quantity);
    }

    protected function findOwnedSku(int $tenantId, OrderItem $item): ?ProductSku
    {
        if (! $item->sku_id) {
            return null;
        }

        $sku = ProductSku::withTrashed()
            ->where('sku_id', $item->sku_id)
            ->where('tenant_id', $tenantId)
            ->first();

        // 镜像 SKU 的库存/履约以源表为准
        return ($sku && ! $sku->isMirror()) ? $sku : null;
    }
}
