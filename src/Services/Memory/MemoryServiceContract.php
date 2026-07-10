<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Memory;

use MultiTenantSaas\Contracts\MemoryContract;

/**
 * Memory 服务契约
 *
 * 扩展 MemoryContract，新增租户级记忆操作与配置访问方法。
 * MemoryService 实现本接口，由 TenancyServiceProvider 注册到服务容器。
 */
interface MemoryServiceContract extends MemoryContract
{
    /**
     * 读取租户级记忆
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $type  记忆类型
     * @param  string  $key  记忆键
     * @return mixed 记忆值；未命中时返回 null
     */
    public function readTenantMemory(int $tenantId, string $type, string $key): mixed;

    /**
     * 写入租户级记忆
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $type  记忆类型
     * @param  string  $key  记忆键
     * @param  mixed  $value  记忆值
     */
    public function writeTenantMemory(int $tenantId, string $type, string $key, mixed $value): void;

    /**
     * 获取衰减率
     */
    public function getDecayRate(): float;

    /**
     * 获取压缩阈值
     */
    public function getCompressThreshold(): int;
}
