<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Billing\Services\PayService;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrder;
use MultiTenantSaas\Modules\Commerce\Models\CommerceOrderItem;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;

/**
 * 商业体订单服务（租户向平台购买）
 *
 * placeOrder：校验 SKU → 快照 payload → 建单（订单:支付单=1:1）；零元单直接履约。
 * pay：委托 PayService 平台级商户预下单。
 */
class CommerceOrderService
{
    /** 支持的支付渠道 → PayService 平台方法 */
    private const PAY_CHANNELS = ['wechat_h5', 'alipay_web', 'alipay_wap'];

    public function __construct(
        private readonly PayService $payService,
        private readonly CommerceFulfillmentService $fulfillmentService
    ) {}

    /**
     * 下单
     *
     * @param  int  $operatorId  下单运营人员（Operator）
     * @param  array<int, array{sku_id: int, qty?: int}>  $items
     */
    public function placeOrder(int $operatorId, array $items): CommerceOrder
    {
        if (empty($items)) {
            throw new DomainException('订单商品不能为空');
        }

        // 校验 SKU 并快照
        $resolved = [];
        $total = 0.00;

        foreach ($items as $item) {
            $skuId = (int) ($item['sku_id'] ?? 0);
            $qty = max(1, (int) ($item['qty'] ?? 1));

            $sku = CommerceSku::find($skuId);
            if (! $sku) {
                throw new DomainException("SKU [{$skuId}] 不存在");
            }
            if (! $sku->isActive()) {
                throw new DomainException("SKU [{$sku->name}] 已下架");
            }
            if (! $this->fulfillmentService->registry()->has($sku->fulfill_handler ?: $sku->type)) {
                throw new DomainException("SKU [{$sku->name}] 未配置履约 Handler");
            }

            $unitPrice = (float) $sku->price;
            $total += $unitPrice * $qty;

            $resolved[] = [
                'sku' => $sku,
                'qty' => $qty,
                'unit_price' => $unitPrice,
            ];
        }

        $order = DB::transaction(function () use ($operatorId, $resolved, $total) {
            $order = CommerceOrder::create([
                'order_no' => $this->generateOrderNo(),
                'amount' => round($total, 2),
                'status' => CommerceOrder::STATUS_PENDING,
                'operator_id' => $operatorId,
            ]);

            foreach ($resolved as $row) {
                CommerceOrderItem::create([
                    'order_id' => $order->order_id,
                    'sku_id' => $row['sku']->sku_id,
                    'qty' => $row['qty'],
                    'unit_price' => $row['unit_price'],
                    'fulfill_status' => CommerceOrderItem::FULFILL_PENDING,
                    'retry_count' => 0,
                    'payload_snapshot' => $row['sku']->payload ?? [],
                ]);
            }

            return $order;
        });

        // 零元单：免支付，直接标记支付成功并履约（如平台赠送/内部划拨）
        if ((float) $order->amount <= 0) {
            $order->update([
                'status' => CommerceOrder::STATUS_PAID,
                'paid_at' => now(),
            ]);
            $this->fulfillmentService->fulfillOrder($order);
        }

        return $order->fresh();
    }

    /**
     * 发起支付（平台商户预下单）
     *
     * @return array{channel: string, pay_data: array|string}
     */
    public function pay(CommerceOrder $order, string $channel): array
    {
        if (! $order->isPayable()) {
            throw new DomainException('订单当前状态不可支付');
        }
        if (! in_array($channel, self::PAY_CHANNELS, true)) {
            throw new DomainException("不支持的支付渠道 [{$channel}]");
        }

        $amount = (float) $order->amount;

        $payData = match ($channel) {
            'wechat_h5' => $this->payService->platformWechatH5($amount, $order->order_no),
            'alipay_web' => $this->payService->platformAlipayWeb($amount, $order->order_no),
            'alipay_wap' => $this->payService->platformAlipayWap($amount, $order->order_no),
        };

        return ['channel' => $channel, 'pay_data' => $payData];
    }

    /**
     * 取消订单（仅 pending 且未履约）
     */
    public function cancel(CommerceOrder $order): void
    {
        if (! $order->isPayable()) {
            throw new DomainException('订单当前状态不可取消');
        }

        $order->update(['status' => CommerceOrder::STATUS_CANCELLED]);
    }

    private function generateOrderNo(): string
    {
        return 'CM' . date('YmdHis') . random_int(100000, 999999);
    }
}
