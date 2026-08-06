<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Pay\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 统一支付编排服务
 *
 * 三条支付路径的公共底座（从项目层 OrderService 上提）：
 * - cash：现金支付（框架 PaymentService 优先，生产禁 mock）
 * - points：虚拟支付（渠道由 VirtualPayChannelRegistry 提供，默认 'points'）
 * - mixed：虚拟折现抵扣 + 现金补差（折现开关与比例由 SalesConfigService 配置）
 *
 * 计佣基数 = 实付现金；虚拟抵扣部分不计佣（防刷分套佣）。
 */
class TradePayService
{
    public const PAY_CASH = 'cash';
    public const PAY_POINTS = 'points';
    public const PAY_MIXED = 'mixed';

    /** 默认虚拟渠道名（积分） */
    public const DEFAULT_VIRTUAL_CHANNEL = 'points';

    public function __construct(
        protected SalesConfigService $salesConfigService,
        protected VirtualPayChannelRegistry $channels,
    ) {}

    /**
     * 现金/虚拟拆分计算
     *
     * @return array{0: float, 1: int} [现金金额（元）, 虚拟资产数]
     */
    public function splitPayment(
        int $tenantId,
        ?int $userId,
        string $payMethod,
        float $cashTotal,
        int $pointsTotal,
        int $pointsToUse = 0,
        string $channel = self::DEFAULT_VIRTUAL_CHANNEL,
    ): array {
        if ($payMethod === self::PAY_POINTS) {
            return [0.0, $pointsTotal];
        }

        if ($payMethod === self::PAY_CASH) {
            return [round($cashTotal, 2), 0];
        }

        if ($payMethod !== self::PAY_MIXED) {
            throw new UnprocessableEntityHttpException("Invalid pay_method: {$payMethod}");
        }

        // mixed：折现抵扣 + 现金补差
        $config = $this->salesConfigService->getConfig($tenantId);
        if (! $config['mixed_pay_enabled']) {
            throw new UnprocessableEntityHttpException('Mixed payment is not enabled');
        }
        if ($pointsToUse <= 0 || ! $userId) {
            throw new UnprocessableEntityHttpException('Mixed payment requires points_to_use');
        }

        $balance = $this->channels->get($channel)->getBalance($tenantId, $userId);
        if ($balance < $pointsToUse) {
            throw new UnprocessableEntityHttpException('Insufficient virtual balance');
        }

        // 抵扣上限：订单金额的 max_points_deduct_ratio%
        $ratio = max(1, $config['points_to_cash_ratio']);
        $maxDeductAmount = $cashTotal * $config['max_points_deduct_ratio'] / 100;
        $deductAmount = min($pointsToUse / $ratio, $maxDeductAmount, $cashTotal);
        $deductAmount = floor($deductAmount * 100) / 100;

        // 反推实际消耗（按抵扣金额折算，避免多扣）
        $pointsUsed = (int) ceil($deductAmount * $ratio);

        return [round($cashTotal - $deductAmount, 2), $pointsUsed];
    }

    /** 扣减虚拟资产（订单事务内调用；渠道未注册时优雅报错） */
    public function consumeVirtual(int $tenantId, int $userId, int $amount, string $orderNo, string $channel = self::DEFAULT_VIRTUAL_CHANNEL): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->channels->get($channel)->consume($tenantId, $userId, $amount, $orderNo);
    }

    /** 返还虚拟资产（退款时调用；渠道未注册仅告警不阻断退款） */
    public function refundVirtual(int $tenantId, int $userId, int $amount, string $orderNo, string $channel = self::DEFAULT_VIRTUAL_CHANNEL): void
    {
        if ($amount <= 0) {
            return;
        }

        if (! $this->channels->has($channel)) {
            Log::warning("TradePayService: virtual channel [{$channel}] not registered, skip refund for order {$orderNo}");

            return;
        }

        $this->channels->get($channel)->refund($tenantId, $userId, $amount, $orderNo);
    }

    /**
     * 发起现金支付（框架 PaymentService 优先，生产禁 mock）
     *
     * @return array{pay_data: array, payment_order_id: int|null}
     */
    public function createCashPayment(int $userId, int $amountFen, ?string $openid, string $orderNo): array
    {
        try {
            if (class_exists(\MultiTenantSaas\Modules\Payment\Services\PaymentService::class)) {
                $paymentService = app(\MultiTenantSaas\Modules\Payment\Services\PaymentService::class);

                $paymentOrder = $paymentService->createOrder(
                    userId: $userId,
                    credits: 0,
                    priceFen: $amountFen,
                    openid: $openid,
                    tradeType: $openid ? 'JSAPI' : 'MWEB',
                );

                return [
                    'pay_data'         => json_decode($paymentOrder->pay_data ?? '{}', true) ?: [],
                    'payment_order_id' => (int) $paymentOrder->id,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning("TradePayService: framework payment failed, order={$orderNo}", [
                'error' => $e->getMessage(),
            ]);

            if (app()->isProduction()) {
                throw new ServiceUnavailableException('Payment service unavailable, please try again later', 0, $e);
            }
        }

        if (app()->isProduction()) {
            throw new ServiceUnavailableException('Payment service not configured');
        }

        return [
            'pay_data'         => [
                'mode'     => 'mock',
                'order_no' => $orderNo,
                'amount'   => $amountFen,
                'message'  => 'Payment gateway not configured, mock mode (non-production only)',
            ],
            'payment_order_id' => null,
        ];
    }

    /** 调用框架退款服务（异常向上传播，由调用方标记 refund_failed） */
    public function refundCash(int $tenantId, string $orderNo, float $amount, ?string $reason): void
    {
        if (! class_exists(\MultiTenantSaas\Modules\Billing\Services\RefundService::class)) {
            Log::info("TradePayService: RefundService not available, skip external refund for order={$orderNo}");

            return;
        }

        $refundService = app(\MultiTenantSaas\Modules\Billing\Services\RefundService::class);
        $refundService->refund(
            tenantId: $tenantId,
            orderNo: $orderNo,
            amount: $amount,
            reason: $reason ?? 'User requested refund',
        );
    }
}
