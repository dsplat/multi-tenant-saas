<?php

namespace MultiTenantSaas\Isolation;

use MultiTenantSaas\Contracts\IsolationStrategyContract;
use MultiTenantSaas\Models\Tenant;

/**
 * 共享数据库隔离策略
 *
 * 所有租户共享同一数据库，通过 TenantScope（BelongsToTenant trait）实现行级隔离。
 * 该策略无需创建/删除数据库，setupDatabase/teardownDatabase/migrate 均为空操作。
 */
class SharedDatabaseStrategy implements IsolationStrategyContract
{
    /**
     * 获取连接名称：始终使用应用默认连接
     */
    public function getConnection(Tenant $tenant): string
    {
        return (string) config('database.default');
    }

    /**
     * 共享数据库无需创建，空操作
     */
    public function setupDatabase(Tenant $tenant): void
    {
        // 依赖 TenantScope 行级隔离，无需额外初始化
    }

    /**
     * 共享数据库不可删除，空操作
     *
     * 租户数据清理由上层软删除/数据保留流程处理，此处不触碰共享库。
     */
    public function teardownDatabase(Tenant $tenant): void
    {
        // 不删除共享数据库
    }

    /**
     * 共享数据库迁移在主库统一执行，无需按租户迁移
     */
    public function migrate(Tenant $tenant): void
    {
        // 主库迁移由应用统一管理
    }
}
