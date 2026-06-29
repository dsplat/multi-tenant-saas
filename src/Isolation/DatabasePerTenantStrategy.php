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
 * 独立数据库隔离策略
 *
 * 每个租户使用独立的数据库，运行时通过 Config::set 动态注册连接配置。
 * - MySQL/PostgreSQL：通过管理员连接执行 CREATE/DROP DATABASE（需 DBA 权限）
 * - SQLite：连接时自动创建（:memory: 或文件），无需 CREATE DATABASE 语句
 *
 * 注意：CREATE/DROP DATABASE 需要数据库管理员权限，建议通过
 * config('tenancy.isolation.admin_connection') 指定具备该权限的连接。
 */
class DatabasePerTenantStrategy implements IsolationStrategyContract
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
     * 初始化独立数据库：命名、创建库、注册连接
     */
    public function setupDatabase(Tenant $tenant): void
    {
        if (empty($tenant->database_name)) {
            $tenant->database_name = $this->defaultDatabaseName($tenant);
        }

        $this->createDatabase($tenant);
        $this->ensureConnection($tenant);
    }

    /**
     * 清理独立数据库：删除库、清除连接池与配置
     */
    public function teardownDatabase(Tenant $tenant): void
    {
        $connection = $this->connectionName($tenant);

        $this->dropDatabase($tenant);

        try {
            DB::purge($connection);
        } catch (Throwable) {
            // 连接可能尚未建立，忽略清理异常
        }

        // 置空配置，使后续访问视为未注册
        Config::set("database.connections.{$connection}", null);

        $tenant->database_name = null;
        $tenant->save();
    }

    /**
     * 在租户独立库上执行迁移
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
     * 生成默认数据库名称
     */
    protected function defaultDatabaseName(Tenant $tenant): string
    {
        $template = (string) config('tenancy.isolation.database_name_template', 'tenant_{:id}');

        return str_replace('{:id}', (string) $tenant->getKey(), $template);
    }

    /**
     * 动态注册租户连接配置（继承基础连接配置，仅覆盖 database）
     */
    protected function ensureConnection(Tenant $tenant): void
    {
        $name = $this->connectionName($tenant);

        if (config("database.connections.{$name}") !== null) {
            return;
        }

        $baseConnection = (string) config('tenancy.isolation.base_connection', config('database.default'));
        $baseConfig = (array) config("database.connections.{$baseConnection}", []);
        $config = $baseConfig;
        $driver = $config['driver'] ?? 'sqlite';

        // SQLite 每个租户使用独立 :memory: 数据库（进程内天然隔离）
        $config['database'] = match ($driver) {
            'sqlite' => ':memory:',
            default => $tenant->database_name ?: $this->defaultDatabaseName($tenant),
        };

        Config::set("database.connections.{$name}", $config);
    }

    /**
     * 创建物理数据库（仅 MySQL/PostgreSQL 需要）
     */
    protected function createDatabase(Tenant $tenant): void
    {
        $driver = $this->baseDriver();

        if ($driver === 'sqlite') {
            return; // SQLite 连接时自动创建
        }

        $name = $tenant->database_name;
        if (empty($name)) {
            return;
        }

        $this->validateIdentifier($name, 'database_name');

        try {
            $admin = $this->adminConnection();
            if ($driver === 'mysql') {
                DB::connection($admin)->statement("CREATE DATABASE IF NOT EXISTS `{$name}`");
            } elseif ($driver === 'pgsql') {
                DB::connection($admin)->statement('CREATE DATABASE "'.$name.'"');
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                trans('tenant.isolation_database_create_failed', ['error' => $e->getMessage()]),
                0,
                $e
            );
        }
    }

    /**
     * 删除物理数据库（仅 MySQL/PostgreSQL 需要）
     */
    protected function dropDatabase(Tenant $tenant): void
    {
        $driver = $this->baseDriver();

        if ($driver === 'sqlite') {
            return; // SQLite 内存库随连接释放，无需显式删除
        }

        $name = $tenant->database_name;
        if (empty($name)) {
            return;
        }

        $this->validateIdentifier($name, 'database_name');

        try {
            $admin = $this->adminConnection();
            if ($driver === 'mysql') {
                DB::connection($admin)->statement("DROP DATABASE IF EXISTS `{$name}`");
            } elseif ($driver === 'pgsql') {
                DB::connection($admin)->statement('DROP DATABASE IF EXISTS "'.$name.'"');
            }
        } catch (Throwable $e) {
            throw new RuntimeException(
                trans('tenant.isolation_database_drop_failed', ['error' => $e->getMessage()]),
                0,
                $e
            );
        }
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
     * 获取用于 DDL 的管理员连接名称
     */
    protected function adminConnection(): string
    {
        $configured = config('tenancy.isolation.admin_connection');

        return is_string($configured) && $configured !== '' ? $configured : (string) config('database.default');
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
