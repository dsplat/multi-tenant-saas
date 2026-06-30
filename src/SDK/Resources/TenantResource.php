<?php

namespace MultiTenantSaas\SDK\Resources;

use MultiTenantSaas\SDK\Client;

/**
 * 租户管理资源
 *
 * 封装租户相关的 API 调用，支持链式调用。
 */
class TenantResource
{
    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * 获取租户列表
     *
     * @param  array<string, mixed>  $query  查询参数（分页、过滤等）
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->client->request('GET', '/tenants', $query);
    }

    /**
     * 获取单个租户详情
     *
     * @param  int  $tenantId  租户 ID
     * @return array<string, mixed>
     */
    public function find(int $tenantId): array
    {
        return $this->client->request('GET', '/tenants/'.$tenantId);
    }

    /**
     * 创建租户
     *
     * @param  array<string, mixed>  $data  租户属性
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return $this->client->request('POST', '/tenants', [], $data);
    }

    /**
     * 更新租户
     *
     * @param  int  $tenantId  租户 ID
     * @param  array<string, mixed>  $data  租户属性
     * @return array<string, mixed>
     */
    public function update(int $tenantId, array $data): array
    {
        return $this->client->request('PUT', '/tenants/'.$tenantId, [], $data);
    }

    /**
     * 删除租户
     *
     * @param  int  $tenantId  租户 ID
     * @return array<string, mixed>
     */
    public function delete(int $tenantId): array
    {
        return $this->client->request('DELETE', '/tenants/'.$tenantId);
    }

    /**
     * 挂起租户
     *
     * @param  int  $tenantId  租户 ID
     * @return array<string, mixed>
     */
    public function suspend(int $tenantId): array
    {
        return $this->client->request('POST', '/tenants/'.$tenantId.'/suspend');
    }

    /**
     * 激活租户
     *
     * @param  int  $tenantId  租户 ID
     * @return array<string, mixed>
     */
    public function activate(int $tenantId): array
    {
        return $this->client->request('POST', '/tenants/'.$tenantId.'/activate');
    }
}
