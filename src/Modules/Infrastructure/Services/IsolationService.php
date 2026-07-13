<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MultiTenantSaas\Contracts\IsolationStrategyContract;
use MultiTenantSaas\Isolation\DatabasePerTenantStrategy;
use MultiTenantSaas\Isolation\SchemaPerTenantStrategy;
use MultiTenantSaas\Isolation\SharedDatabaseStrategy;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use RuntimeException;

/**
 * 数据库隔离服务
 *
 * 职责：
 * - 隔离策略注册与选择（shared / database / schema）
 * - 租户创建时自动初始化数据库（setupForTenant）
 * - 租户删除时清理数据库（teardownForTenant）
 * - 连接池管理（委托给具体策略）
 * - 迁移工具：将现有租户从一种隔离策略迁移到另一种（migrate）
 *
 * 注册：通过 TenancyServiceProvider 注册为 singleton。
 * 策略选择依据 Tenant.isolation_type 字段，缺省回退到 config('tenancy.isolation.default')。
 */
class IsolationService
{
    /** 隔离策略：共享数据库（行级隔离） */
    public const TYPE_SHARED = 'shared';

    /** 隔离策略：独立数据库 */
    public const TYPE_DATABASE = 'database';

    /** 隔离策略：独立 Schema（仅 PostgreSQL） */
    public const TYPE_SCHEMA = 'schema';

    /** @var array<string, IsolationStrategyContract> 已注册策略 */
    protected array $strategies = [];

    public function __construct()
    {
        $this->registerDefaultStrategies();
    }

    /**
     * 注册默认策略
     */
    protected function registerDefaultStrategies(): void
    {
        $this->strategies[self::TYPE_SHARED] = new SharedDatabaseStrategy;
        $this->strategies[self::TYPE_DATABASE] = new DatabasePerTenantStrategy;
        $this->strategies[self::TYPE_SCHEMA] = new SchemaPerTenantStrategy;
    }

    /**
     * 注册自定义策略
     */
    public function registerStrategy(string $type, IsolationStrategyContract $strategy): void
    {
        $this->strategies[$type] = $strategy;
    }

    /**
     * 是否存在指定策略
     */
    public function hasStrategy(string $type): bool
    {
        return isset($this->strategies[$type]);
    }

    /**
     * 按类型获取策略
     *
     * @param  string  $type  策略类型（shared/database/schema）
     *
     * @throws InvalidArgumentException 不支持的类型
     */
    public function strategy(string $type): IsolationStrategyContract
    {
        if (! $this->hasStrategy($type)) {
            throw new InvalidArgumentException(
                trans('tenant.isolation_type_unsupported', ['type' => $type])
            );
        }

        return $this->strategies[$type];
    }

    /**
     * 根据租户当前隔离类型获取策略
     */
    public function strategyForTenant(Tenant $tenant): IsolationStrategyContract
    {
        $type = $tenant->isolation_type ?: $this->defaultType();

        return $this->strategy($type);
    }

    /**
     * 获取默认隔离策略类型
     */
    public function defaultType(): string
    {
        return (string) config('tenancy.isolation.default', self::TYPE_SHARED);
    }

    /**
     * 获取租户对应的数据库连接名称
     */
    public function connectionForTenant(Tenant $tenant): string
    {
        return $this->strategyForTenant($tenant)->getConnection($tenant);
    }

    /**
     * 租户创建时自动初始化数据库
     *
     * @param  Tenant  $tenant  租户实例
     * @param  string|null  $type  隔离类型，缺省使用默认策略
     */
    public function setupForTenant(Tenant $tenant, ?string $type = null): void
    {
        $type = $type ?: $this->defaultType();

        $tenant->isolation_type = $type;
        $tenant->save();

        $this->strategy($type)->setupDatabase($tenant);
        $tenant->save();
    }

    /**
     * 租户删除时清理数据库
     */
    public function teardownForTenant(Tenant $tenant): void
    {
        $this->strategyForTenant($tenant)->teardownDatabase($tenant);
    }

    /**
     * 在租户连接上执行迁移
     */
    public function migrateTenant(Tenant $tenant): void
    {
        $this->strategyForTenant($tenant)->migrate($tenant);
    }

    /**
     * 迁移工具：将租户从一种隔离策略迁移到另一种
     *
     * 主要场景：shared → database（共享库租户升级为独立库）
     *
     * 流程：导出现有数据 → 切换策略并初始化新库 → 新库建表迁移 → 导入数据 →
     *       清理旧库数据 → 校验一致性
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $fromStrategy  源策略类型（必须与租户当前策略一致）
     * @param  string  $toStrategy  目标策略类型
     *
     * @throws RuntimeException 策略不一致或校验失败
     */
    public function migrate(int $tenantId, string $fromStrategy, string $toStrategy): void
    {
        $tenant = Tenant::findOrFail($tenantId);

        $current = $tenant->isolation_type ?: $this->defaultType();
        if ($current !== $fromStrategy) {
            throw new RuntimeException(
                trans('tenant.isolation_migrate_type_mismatch', ['current' => $current, 'from' => $fromStrategy])
            );
        }

        $from = $this->strategy($fromStrategy);
        $to = $this->strategy($toStrategy);

        // 1. 从源连接导出现有数据
        $export = $this->exportTenantData($tenant, $from->getConnection($tenant));

        // 2. 初始化目标库（不切换 isolation_type）
        $to->setupDatabase($tenant);

        // 3. 在新库上执行迁移建表
        $to->migrate($tenant);

        // 4. 导入数据到新库
        $this->importTenantData($tenant, $to->getConnection($tenant), $export);

        // 5. 清理源库中的租户数据（仅 shared → 其它 时删除旧行）
        if ($fromStrategy === self::TYPE_SHARED && $toStrategy !== self::TYPE_SHARED) {
            $this->deleteTenantData($tenant, $from->getConnection($tenant));
        }

        // 6. 校验迁移一致性
        $this->verifyMigration($tenant, $to->getConnection($tenant), $export);

        // 7. 校验通过后才切换并持久化 isolation_type
        $tenant->isolation_type = $toStrategy;
        $tenant->save();
    }

    /**
     * 需要迁移的租户数据表清单
     *
     * @return string[]
     */
    protected function tenantTables(): array
    {
        return (array) config('tenancy.isolation.tenant_tables', []);
    }

    /**
     * 从指定连接导出租户数据
     *
     * @return array<string, array<int, object>>
     */
    protected function exportTenantData(Tenant $tenant, string $connection): array
    {
        $data = [];
        $tenantId = $tenant->getKey();

        foreach ($this->tenantTables() as $table) {
            $rows = DB::connection($connection)
                ->table($table)
                ->where('tenant_id', $tenantId)
                ->get()
                ->all();
            $data[$table] = $rows;
        }

        return $data;
    }

    /**
     * 向指定连接导入租户数据
     *
     * @param  array<string, array<int, object>>  $data
     */
    protected function importTenantData(Tenant $tenant, string $connection, array $data): void
    {
        foreach ($data as $table => $rows) {
            if (empty($rows)) {
                continue;
            }
            $records = array_map(fn ($row) => (array) $row, $rows);
            DB::connection($connection)->table($table)->insert($records);
        }
    }

    /**
     * 从指定连接删除租户数据
     */
    protected function deleteTenantData(Tenant $tenant, string $connection): void
    {
        $tenantId = $tenant->getKey();

        foreach ($this->tenantTables() as $table) {
            DB::connection($connection)
                ->table($table)
                ->where('tenant_id', $tenantId)
                ->delete();
        }
    }

    /**
     * 校验目标连接中租户数据行数与导出一致
     *
     * @param  array<string, array<int, object>>  $expected
     *
     * @throws RuntimeException 校验失败
     */
    protected function verifyMigration(Tenant $tenant, string $connection, array $expected): void
    {
        $tenantId = $tenant->getKey();

        foreach ($expected as $table => $rows) {
            $count = DB::connection($connection)
                ->table($table)
                ->where('tenant_id', $tenantId)
                ->count();
            $expectedCount = count($rows);
            if ($count !== $expectedCount) {
                throw new RuntimeException(
                    trans('tenant.isolation_migrate_verify_failed', [
                        'table' => $table,
                        'expected' => $expectedCount,
                        'actual' => $count,
                    ])
                );
            }
        }
    }
}
