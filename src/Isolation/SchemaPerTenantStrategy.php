<?php

namespace MultiTenantSaas\Isolation;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\IsolationStrategyContract;
use MultiTenantSaas\Models\Tenant;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * 独立 Schema 隔离策略
 *
 * 每个租户使用独立的 PostgreSQL Schema，通过动态切换 search_path 实现隔离。
 *
 * 注意：Schema 级隔离仅适用于 PostgreSQL，MySQL 8.0 不支持 Schema 级隔离。
 * 若当前数据库驱动非 pgsql，setupDatabase/teardownDatabase 将抛出异常；
 * 派生项目迁移至 PostgreSQL 后可启用此策略。
 */
class SchemaPerTenantStrategy implements IsolationStrategyContract
{
    /**
     * 获取租户连接名称并确保连接配置已注册
     */
    public function getConnection(Tenant $tenant): string
    {
        $this->ensureConnection($tenant);

        return $this->connectionName($tenant);
    }

    /**
     * 初始化独立 Schema：命名、创建 Schema、注册连接
     *
     * 仅 PostgreSQL 支持，其它驱动抛出异常。
     */
    public function setupDatabase(Tenant $tenant): void
    {
        if ($this->baseDriver() !== 'pgsql') {
            throw new RuntimeException(trans('tenant.isolation_schema_unsupported'));
        }

        if (empty($tenant->schema_name)) {
            $tenant->schema_name = $this->defaultSchemaName($tenant);
        }

        $schema = $tenant->schema_name;
        $this->validateIdentifier($schema, 'schema_name');
        $base = (string) config('tenancy.isolation.base_connection', config('database.default'));
        DB::connection($base)->statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');

        $this->ensureConnection($tenant);
    }

    /**
     * 清理独立 Schema：删除 Schema、清除连接池与配置
     */
    public function teardownDatabase(Tenant $tenant): void
    {
        $connection = $this->connectionName($tenant);

        if ($this->baseDriver() !== 'pgsql') {
            throw new RuntimeException(trans('tenant.isolation_schema_unsupported'));
        }

        $schema = $tenant->schema_name;
        $base = (string) config('tenancy.isolation.base_connection', config('database.default'));

        if (! empty($schema)) {
            $this->validateIdentifier($schema, 'schema_name');
            DB::connection($base)->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
        }

        try {
            DB::purge($connection);
        } catch (Throwable) {
            // 忽略连接清理异常
        }

        Config::set("database.connections.{$connection}", null);

        $tenant->schema_name = null;
        $tenant->save();
    }

    /**
     * 在租户 Schema 上执行迁移
     */
    public function migrate(Tenant $tenant): void
    {
        if (! config('tenancy.isolation.run_migrations', true)) {
            return;
        }

        $connection = $this->connectionName($tenant);
        $this->ensureConnection($tenant);

        Artisan::call('migrate', [
            '--database' => $connection,
            '--path' => (string) config('tenancy.isolation.migrations_path', database_path('migrations')),
            '--force' => true,
        ]);
    }

    /**
     * 生成租户连接名称
     */
    protected function connectionName(Tenant $tenant): string
    {
        $prefix = (string) config('tenancy.isolation.connection_prefix', 'tenant.');

        return $prefix.$tenant->getKey();
    }

    /**
     * 生成默认 Schema 名称
     */
    protected function defaultSchemaName(Tenant $tenant): string
    {
        $template = (string) config('tenancy.isolation.schema_name_template', 'tenant_{:id}');

        return str_replace('{:id}', (string) $tenant->getKey(), $template);
    }

    /**
     * 动态注册租户连接配置（继承基础连接配置，覆盖 search_path）
     */
    protected function ensureConnection(Tenant $tenant): void
    {
        $name = $this->connectionName($tenant);

        if (config("database.connections.{$name}") !== null) {
            return;
        }

        $baseConnection = (string) config('tenancy.isolation.base_connection', config('database.default'));
        $config = (array) config("database.connections.{$baseConnection}", []);
        $config['search_path'] = $tenant->schema_name;

        Config::set("database.connections.{$name}", $config);
    }

    /**
     * 获取基础连接的数据库驱动
     */
    protected function baseDriver(): string
    {
        $base = (string) config('tenancy.isolation.base_connection', config('database.default'));

        return (string) config("database.connections.{$base}.driver", 'sqlite');
    }

    /**
     * 校验标识符仅包含安全字符，防止 SQL 注入
     *
     * @throws InvalidArgumentException 标识符包含非法字符
     */
    protected function validateIdentifier(string $value, string $field): void
    {
        if (! preg_match('/\A[a-zA-Z0-9_]+\z/', $value)) {
            throw new InvalidArgumentException(
                trans('tenant.isolation_invalid_identifier', ['field' => $field, 'value' => $value])
            );
        }
    }
}
