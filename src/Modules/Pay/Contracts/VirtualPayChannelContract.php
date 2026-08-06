<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Pay\Contracts;

/**
 * 虚拟支付渠道契约
 *
 * 框架不内置任何虚拟支付实现；项目层按需注册（如 scrm 的积分渠道）。
 * 未注册渠道时，points/mixed 支付路径优雅报错（渠道未开通）。
 */
interface VirtualPayChannelContract
{
    /** 渠道名（如 'points'） */
    public function name(): string;

    /** 查询用户虚拟资产余额 */
    public function getBalance(int $tenantId, int $userId): int;

    /**
     * 扣减虚拟资产（支付确认时调用，处于订单事务内）
     *
     * @param string $orderNo 关联订单号（幂等/流水用）
     * @throws \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException 余额不足等
     */
    public function consume(int $tenantId, int $userId, int $amount, string $orderNo): void;

    /** 返还虚拟资产（退款时调用） */
    public function refund(int $tenantId, int $userId, int $amount, string $orderNo): void;
}
