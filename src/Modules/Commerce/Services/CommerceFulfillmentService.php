<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Billing\Services\PayService;
use MultiTenantSaas\Modules\Commerce\Handlers\AbstractSupplyFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Handlers\ContentPackFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Handlers\MallSupplyFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Handlers\ModuleFulfillmentHandler;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrder;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Models\ModuleEntitlement;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;
use MultiTenantSaas\Modules\Infrastructure\Services\EventBusService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 商业体履约服务
 *
 * 回调链：平台商户验签 → 幂等 → 金额校验 → 置 paid → 设置租户上下文 → 逐项履约。
 * 单项失败不阻塞其他项（标记 failed，由 commerce:retry 补偿）。
 */
class CommerceFulfillmentService
{
    /** 单项履约最大重试次数 */
    private const MAX_RETRY = 3;

    public function __construct(
        private readonly CommerceHandlerRegistry $registry,
        private readonly PayService $payService,
        private readonly EventBusService $eventBus
    ) {}

    public function registry(): CommerceHandlerRegistry
    {
        return $this->registry;
    }

    /**
     * 处理平台级支付回调（无租户上下文入口）
     */
    public function handlePlatformCallback(string $driver, Request $request): void
    {
        // 平台商户配置验签（失败抛异常）
        $result = $this->payService->handlePlatformCallback($driver, $request);

        // 支付宝非成功状态回调（如交易关闭）不触发履约
        if ($driver === 'alipay' && $result['status'] !== '' && ! in_array($result['status'], ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return;
        }

        $orderNo = (string) ($result['out_trade_no'] ?? '');
        $order = CommerceOrder::withoutGlobalScope(TenantScope::class)
            ->where('order_no', $orderNo)
            ->first();

        if (! $order) {
            Log::warning('[Commerce] 回调订单不存在', ['order_no' => $orderNo]);

            throw new DomainException("订单 [{$orderNo}] 不存在");
        }

        // 幂等：非 pending 状态（已支付/已履约/已取消）直接跳过
        if ($order->status !== CommerceOrder::STATUS_PENDING) {
            return;
        }

        // 金额校验（微信回调单位为分，支付宝为元）
        $paidAmount = $driver === 'wechat'
            ? round(((float) ($result['total_fee'] ?? 0)) / 100, 2)
            : round((float) ($result['total_amount'] ?? 0), 2);

        if (abs($paidAmount - (float) $order->amount) > 0.01) {
            Log::error('[Commerce] 回调金额与订单金额不一致', [
                'order_no' => $orderNo,
                'expected' => $order->amount,
                'actual' => $paidAmount,
            ]);

            throw new DomainException('回调金额与订单金额不一致');
        }

        $order->update([
            'status' => CommerceOrder::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->fulfillOrder($order);
    }

    /**
     * 履约订单全部条目（单项失败不阻塞）
     */
    public function fulfillOrder(CommerceOrder $order): void
    {
        // 回调/队列场景无租户上下文：显式设置，供 Handler 内部租户级服务使用
        TenantContext::setTenantId((string) $order->tenant_id);

        $hasFailure = false;

        foreach ($order->items()->get() as $item) {
            if ($item->fulfill_status === CommerceOrderItem::FULFILL_FULFILLED) {
                continue; // 幂等：已履约项跳过
            }

            if (! $this->fulfillItem($item)) {
                $hasFailure = true;
            }
        }

        $order->update([
            'status' => $hasFailure ? CommerceOrder::STATUS_PARTIAL_FAILED : CommerceOrder::STATUS_FULFILLED,
        ]);

        $this->eventBus->publish('commerce.order.fulfilled', [
            'order_id' => $order->order_id,
            'order_no' => $order->order_no,
            'tenant_id' => $order->tenant_id,
            'status' => $order->status,
        ]);
    }

    /**
     * 履约单项（失败记录原因并递增重试计数，不抛异常）
     */
    public function fulfillItem(CommerceOrderItem $item): bool
    {
        $sku = $item->sku;
        $handlerName = $sku ? ($sku->fulfill_handler ?: $sku->type) : '';

        try {
            $handler = $this->registry->resolve((string) $handlerName);
            $handler->fulfill($item);

            $item->update([
                'fulfill_status' => CommerceOrderItem::FULFILL_FULFILLED,
                'fulfill_at' => now(),
                'fail_reason' => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[Commerce] 履约失败', [
                'item_id' => $item->item_id,
                'order_no' => $item->order?->order_no,
                'handler' => $handlerName,
                'error' => $e->getMessage(),
            ]);

            $item->update([
                'fulfill_status' => CommerceOrderItem::FULFILL_FAILED,
                'retry_count' => $item->retry_count + 1,
                'fail_reason' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return false;
        }
    }

    /**
     * 补偿：重试履约失败项（retry_count < MAX_RETRY）
     *
     * @return int 本次成功履约的条目数
     */
    public function retryFailed(): int
    {
        $items = CommerceOrderItem::with('order')
            ->where('fulfill_status', CommerceOrderItem::FULFILL_FAILED)
            ->where('retry_count', '<', self::MAX_RETRY)
            ->get();

        $fulfilled = 0;
        $touchedOrders = [];

        foreach ($items as $item) {
            if (! $item->order) {
                continue;
            }

            TenantContext::setTenantId((string) $item->order->tenant_id);

            if ($this->fulfillItem($item)) {
                $fulfilled++;
            }

            $touchedOrders[$item->order->order_id] = $item->order;
        }

        // 重算涉及订单的最终状态
        foreach ($touchedOrders as $order) {
            $stillFailed = $order->items()
                ->where('fulfill_status', CommerceOrderItem::FULFILL_FAILED)
                ->exists();

            $order->update([
                'status' => $stillFailed ? CommerceOrder::STATUS_PARTIAL_FAILED : CommerceOrder::STATUS_FULFILLED,
            ]);
        }

        return $fulfilled;
    }

    /**
     * 处理过期模块权益（由 commerce:retry 定时任务调用）
     *
     * @return int 处理的过期权益数
     */
    public function processExpiredEntitlements(): int
    {
        $expired = ModuleEntitlement::withoutGlobalScope(TenantScope::class)
            ->where('status', ModuleEntitlement::STATUS_ACTIVE)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now())
            ->get();

        $handler = app(ModuleFulfillmentHandler::class);

        foreach ($expired as $entitlement) {
            $handler->expire($entitlement);
        }

        return $expired->count();
    }

    /**
     * 处理过期供给授权（由 commerce:retry 定时任务调用）
     *
     * 置 expired 并联动项目侧实例下架（停供不停兑由项目侧处置）。
     *
     * @return int 处理的过期授权数
     */
    public function processExpiredGrants(): int
    {
        $expired = SupplyGrant::withoutGlobalScope(TenantScope::class)
            ->where('status', SupplyGrant::STATUS_ACTIVE)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', now())
            ->get();

        foreach ($expired as $grant) {
            $handler = $this->supplyHandlerFor($grant);

            if ($handler) {
                $handler->expire($grant);
            } else {
                $grant->update(['status' => SupplyGrant::STATUS_EXPIRED]);
            }
        }

        return $expired->count();
    }

    /**
     * 停供（结算逾期等联动，停供不停兑由项目侧处置）
     */
    public function suspendGrant(SupplyGrant $grant): void
    {
        if ($grant->status !== SupplyGrant::STATUS_ACTIVE) {
            throw new DomainException('仅生效中的授权可停供');
        }

        $handler = $this->supplyHandlerFor($grant);

        if ($handler) {
            $handler->suspend($grant);
        } else {
            $grant->update(['status' => SupplyGrant::STATUS_SUSPENDED]);
        }
    }

    /**
     * 恢复供给
     */
    public function resumeGrant(SupplyGrant $grant): void
    {
        if ($grant->status !== SupplyGrant::STATUS_SUSPENDED) {
            throw new DomainException('仅停供中的授权可恢复');
        }

        $handler = $this->supplyHandlerFor($grant);

        if ($handler) {
            $handler->resume($grant);
        } else {
            $grant->update(['status' => SupplyGrant::STATUS_ACTIVE]);
        }
    }

    /**
     * 按 SKU type 解析供给类 Handler（用于到期回收/停供路由）
     */
    private function supplyHandlerFor(SupplyGrant $grant): ?AbstractSupplyFulfillmentHandler
    {
        $type = $grant->sku?->type;

        return match ($type) {
            CommerceSku::TYPE_CONTENT_PACK => app(ContentPackFulfillmentHandler::class),
            CommerceSku::TYPE_MALL_SUPPLY => app(MallSupplyFulfillmentHandler::class),
            default => null,
        };
    }
}
