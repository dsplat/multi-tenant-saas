<?php

namespace MultiTenantSaas\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\PaymentOrder;
use MultiTenantSaas\Models\FinancialRecord;
use Yansongda\Pay\Pay;

/**
 * 退款服务
 *
 * 支持微信/支付宝退款，通过 yansongda/pay SDK
 */
class RefundService
{
    /**
     * 发起退款
     *
     * @param int $tenantId 租户ID
     * @param string $orderNo 原订单号
     * @param float $refundAmount 退款金额
     * @param string $reason 退款原因
     * @return array 退款结果
     */
    public static function refund(int $tenantId, string $orderNo, float $refundAmount, string $reason = ''): array
    {
        // 查找原订单
        $order = PaymentOrder::where('tenant_id', $tenantId)
            ->where('order_no', $orderNo)
            ->first();

        if (!$order) {
            throw new \RuntimeException('订单不存在');
        }

        if ($order->status !== 'paid' && $order->status !== 'completed') {
            throw new \RuntimeException('订单状态不支持退款');
        }

        if ($refundAmount > floatval($order->amount)) {
            throw new \RuntimeException('退款金额不能超过订单金额');
        }

        $driver = $order->driver;

        try {
            $pay = PayService::createPayInstancePublic($tenantId, $driver);

            $refundNo = 'RFD' . date('YmdHis') . rand(1000, 9999);

            if ($driver === 'wechat') {
                $result = self::wechatRefund($pay, $order, $refundNo, $refundAmount, $reason);
            } elseif ($driver === 'alipay') {
                $result = self::alipayRefund($pay, $order, $refundNo, $refundAmount, $reason);
            } else {
                throw new \RuntimeException("不支持的支付方式: {$driver}");
            }

            // 更新订单状态
            $order->status = 'refunding';
            $order->extra = array_merge($order->extra ?? [], [
                'refund_no' => $refundNo,
                'refund_amount' => $refundAmount,
                'refund_reason' => $reason,
                'refunded_at' => now()->toDateTimeString(),
            ]);
            $order->save();

            // 创建财务记录
            FinancialRecord::create([
                'tenant_id' => $tenantId,
                'type' => 'refund',
                'amount' => intval($refundAmount * 100),
                'status' => 'pending',
                'payment_order_no' => $orderNo,
                'metadata' => [
                    'refund_no' => $refundNo,
                    'reason' => $reason,
                    'driver' => $driver,
                ],
            ]);

            AuditService::log('refund', 'payment_order', $order->id, null, [
                'order_no' => $orderNo,
                'refund_no' => $refundNo,
                'amount' => $refundAmount,
                'reason' => $reason,
            ]);

            return [
                'refund_no' => $refundNo,
                'order_no' => $orderNo,
                'amount' => $refundAmount,
                'status' => 'refunding',
                'driver' => $driver,
            ];

        } catch (\Exception $e) {
            Log::error('退款发起失败', [
                'tenant_id' => $tenantId,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 微信退款
     */
    protected static function wechatRefund($pay, PaymentOrder $order, string $refundNo, float $amount, string $reason): array
    {
        $params = [
            'out_trade_no' => $order->order_no,
            'out_refund_no' => $refundNo,
            'refund_fee' => intval($amount * 100),
            'total_fee' => intval(floatval($order->amount) * 100),
            'reason' => $reason,
        ];

        $result = $pay->refund($params);

        return $result->toArray();
    }

    /**
     * 支付宝退款
     */
    protected static function alipayRefund($pay, PaymentOrder $order, string $refundNo, float $amount, string $reason): array
    {
        $params = [
            'out_trade_no' => $order->order_no,
            'refund_amount' => $amount,
            'out_request_no' => $refundNo,
            'refund_reason' => $reason,
        ];

        $result = $pay->refund($params);

        return $result->toArray();
    }

    /**
     * 处理退款回调
     */
    public static function handleRefundCallback(string $driver, Request $request): array
    {
        $tenantId = $request->query('tenant_id');

        if (!$tenantId) {
            throw new \RuntimeException('退款回调缺少 tenant_id 参数');
        }

        $pay = PayService::createPayInstancePublic((int) $tenantId, $driver);
        $result = $pay->callback($request->all());

        $orderNo = $result->out_trade_no ?? '';
        $refundNo = $result->out_refund_no ?? $result->out_request_no ?? '';
        $refundStatus = $result->refund_status ?? $result->code ?? '';

        // 更新订单状态
        $order = PaymentOrder::where('order_no', $orderNo)->first();
        if ($order) {
            $order->status = 'refunded';
            $order->save();

            // 更新财务记录
            FinancialRecord::where('payment_order_no', $orderNo)
                ->where('type', 'refund')
                ->update(['status' => 'completed']);

            AuditService::log('refund_callback', 'payment_order', $order->id, null, [
                'order_no' => $orderNo,
                'refund_no' => $refundNo,
                'status' => $refundStatus,
            ]);
        }

        return [
            'order_no' => $orderNo,
            'refund_no' => $refundNo,
            'status' => 'refunded',
        ];
    }
}
