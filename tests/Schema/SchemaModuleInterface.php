<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Support\Facades\Schema;

/**
 * Schema 模块接口
 *
 * 每个模块实现此接口，提供 createTables() 和 getTableNames() 方法
 */
interface SchemaModuleInterface
{
    /**
     * 创建模块内的所有表
     */
    public function createTables(): void;

    /**
     * 获取模块内的所有表名
     *
     * @return array<string>
     */
    public function getTableNames(): array;
}
