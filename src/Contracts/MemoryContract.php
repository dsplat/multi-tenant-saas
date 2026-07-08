<?php

declare(strict_types=1);

namespace MultiTenantSaas\Contracts;

/**
 * Memory 接口契约
 *
 * 定义实体记忆读写、压缩与衰减的核心操作规范。实现类负责管理实体级别的
 * 记忆存储，包括按权重排序的压缩策略和基于衰减率的记忆清理。
 *
 * 可通过服务容器按标识绑定不同实现（如 EntityMemory、TenantMemory）。
 */
interface MemoryContract
{
    /**
     * 读取实体记忆
     *
     * @param  string  $entityType  实体类型
     * @param  int     $entityId    实体 ID
     * @param  string  $key         记忆键
     * @return mixed  记忆值；未命中时返回 null
     */
    public function read(string $entityType, int $entityId, string $key): mixed;

    /**
     * 写入实体记忆
     *
     * 若键已存在则更新值并增加权重，否则创建新记录。
     *
     * @param  string  $entityType  实体类型
     * @param  int     $entityId    实体 ID
     * @param  string  $key         记忆键
     * @param  mixed   $value       记忆值
     */
    public function write(string $entityType, int $entityId, string $key, mixed $value): void;

    /**
     * 压缩实体记忆
     *
     * 当记忆数量超过阈值时，按权重降序保留前 N 条，其余合并为压缩条目。
     *
     * @param  string  $entityType  实体类型
     * @param  int     $entityId    实体 ID
     */
    public function compress(string $entityType, int $entityId): void;

    /**
     * 衰减实体记忆权重
     *
     * 删除权重低于阈值的记忆，对其余记忆执行权重衰减。
     *
     * @param  string  $entityType  实体类型
     * @param  int     $entityId    实体 ID
     * @param  float   $threshold   删除阈值，默认 0.1
     */
    public function decay(string $entityType, int $entityId, float $threshold = 0.1): void;
}
