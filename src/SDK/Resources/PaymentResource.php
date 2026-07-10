<?php

namespace MultiTenantSaas\SDK\Resources;

use MultiTenantSaas\SDK\Client;

/**
 * 支付资源
 *
 * 封装支付订单相关的 API 调用，支持链式调用。
 */
class PaymentResource
{
    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * 创建支付订单
     *
     * @param  int  $tenantId  租户 ID
     * @param  array<string, mixed>  $data  订单数据
     * @return array<string, mixed>
     */
    public function createOrder(int $tenantId, array $data): array
    {
        return $this->client->request('POST', '/tenants/' . $tenantId . '/payment-orders', [], $data);
    }

    /**
     * 查询支付订单列表
     *
     * @param  int  $tenantId  租户 ID
     * @param  array<string, mixed>  $query  查询参数
     * @return array<string, mixed>
     */
    public function listOrders(int $tenantId, array $query = []): array
    {
        return $this->client->request('GET', '/tenants/' . $tenantId . '/payment-orders', $query);
    }

    /**
     * 发起退款
     *
     * @param  int  $tenantId  租户 ID
     * @param  array<string, mixed>  $data  退款数据
     * @return array<string, mixed>
     */
    public function refund(int $tenantId, array $data): array
    {
        return $this->client->request('POST', '/tenants/' . $tenantId . '/payment-orders/refund', [], $data);
    }

    /**
     * 查询退款状态
     *
     * @param  int  $tenantId  租户 ID
     * @param  array<string, mixed>  $query  查询参数
     * @return array<string, mixed>
     */
    public function refundStatus(int $tenantId, array $query = []): array
    {
        return $this->client->request('GET', '/tenants/' . $tenantId . '/payment-orders/refund-status', $query);
    }
}
