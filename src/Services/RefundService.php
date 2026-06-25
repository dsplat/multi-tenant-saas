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
            throw new \RuntimeException(trans("payment.order_not_found"));
        }

        if ($order->status !== 'paid' && $order->status !== 'completed') {
            throw new \RuntimeException(trans("payment.order_status_invalid"));
        }

        if ($refundAmount > floatval($order->amount)) {
            throw new \RuntimeException(trans("payment.refund_amount_exceeds"));
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
                throw new \RuntimeException(trans("payment.unsupported_driver") . ": {$driver}");
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
            Log::error(trans('payment.refund_init_failed'), [
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
     * 查询退款状态
     *
     * @param int $tenantId
     * @param string $orderNo 原订单号
     * @return array 退款状态信息
     */
    public static function queryRefundStatus(int $tenantId, string $orderNo): array
    {
        $order = PaymentOrder::where('tenant_id', $tenantId)
            ->where('order_no', $orderNo)
            ->first();

        if (!$order) {
            throw new \RuntimeException(trans('payment.order_not_found'));
        }

        $extra = $order->extra ?? [];
        $refundNo = $extra['refund_no'] ?? null;

        if (!$refundNo) {
            return [
                'order_no' => $orderNo,
                'status' => $order->status,
                'has_refund' => false,
                'message' => trans("payment.no_refund_record"),
            ];
        }

        // 尝试从支付网关查询最新状态
        $driver = $order->driver;
        $gatewayStatus = null;

        try {
            $pay = PayService::createPayInstancePublic($tenantId, $driver);

            if ($driver === 'wechat') {
                $result = $pay->query([
                    'out_trade_no' => $orderNo,
                    'out_refund_no' => $refundNo,
                    'type' => 'refund',
                ]);
                $gatewayStatus = $result->toArray();
            } elseif ($driver === 'alipay') {
                $result = $pay->query([
                    'out_trade_no' => $orderNo,
                    'out_request_no' => $refundNo,
                ]);
                $gatewayStatus = $result->toArray();
            }
        } catch (\Exception $e) {
            Log::warning(trans('payment.refund_query_failed'), [
                'tenant_id' => $tenantId,
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
            ]);
        }

        // 查询财务记录
        $financialRecord = FinancialRecord::where('tenant_id', $tenantId)
            ->where('payment_order_no', $orderNo)
            ->where('type', 'refund')
            ->first();

        return [
            'order_no' => $orderNo,
            'refund_no' => $refundNo,
            'status' => $order->status,
            'refund_amount' => $extra['refund_amount'] ?? null,
            'refund_reason' => $extra['refund_reason'] ?? null,
            'refunded_at' => $extra['refunded_at'] ?? null,
            'financial_status' => $financialRecord?->status,
            'gateway_response' => $gatewayStatus,
        ];
    }

    /**
     * 处理退款回调
     */
    public static function handleRefundCallback(string $driver, Request $request): array
    {
        $tenantId = $request->query('tenant_id');

        if (!$tenantId) {
            throw new \RuntimeException(trans("payment.missing_tenant_callback"));
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
