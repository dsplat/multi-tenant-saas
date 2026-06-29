<?php

namespace MultiTenantSaas\Contracts;

use MultiTenantSaas\Models\Tenant;

/**
 * 数据库隔离策略接口契约
 *
 * 统一抽象三种隔离策略：共享数据库（shared）、独立数据库（database）、独立 Schema（schema）。
 * 派生项目可实现此接口自定义隔离策略，并通过 IsolationService 注册使用。
 */
interface IsolationStrategyContract
{
    /**
     * 获取租户对应的数据库连接名称
     *
     * @param  Tenant  $tenant  租户实例
     * @return string  连接名称（对应 config('database.connections') 中的 key）
     */
    public function getConnection(Tenant $tenant): string;

    /**
     * 初始化租户数据库
     *
     * 创建独立的数据库或 Schema，并将连接配置动态注册到运行时配置。
     *
     * @param  Tenant  $tenant  租户实例
     */
    public function setupDatabase(Tenant $tenant): void;

    /**
     * 清理租户数据库
     *
     * 删除独立的数据库或 Schema，并清除动态注册的连接配置与连接池。
     *
     * @param  Tenant  $tenant  租户实例
     */
    public function teardownDatabase(Tenant $tenant): void;

    /**
     * 在租户连接上执行数据库迁移
     *
     * @param  Tenant  $tenant  租户实例
     */
    public function migrate(Tenant $tenant): void;
}
