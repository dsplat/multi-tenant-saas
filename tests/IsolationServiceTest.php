<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IsolationStrategyContract;
use MultiTenantSaas\Isolation\DatabasePerTenantStrategy;
use MultiTenantSaas\Isolation\SchemaPerTenantStrategy;
use MultiTenantSaas\Isolation\SharedDatabaseStrategy;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\IsolationService;
use RuntimeException;

/**
 * TASK-027 IsolationService 单元测试
 *
 * 覆盖：策略注册与选择、SharedDatabaseStrategy 封装、DatabasePerTenantStrategy
 * 独立库创建/切换/清理、SchemaPerTenantStrategy 非兼容环境保护、租户创建/
 * 删除自动初始化、迁移工具（shared → database）数据搬迁与校验。
 *
 * 测试基于 SQLite（:memory:）：DatabasePerTenantStrategy 每个租户使用独立 :memory:
 * 连接（进程内天然隔离），run_migrations 设为 false 以避免依赖磁盘迁移文件。
 */
class IsolationServiceTest extends TestCase
{
    private IsolationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 隔离策略配置：禁用自动迁移，便于在 :memory: 上手工准备 schema
        config([
            'tenancy.isolation.default' => 'shared',
            'tenancy.isolation.connection_prefix' => 'tenant.',
            'tenancy.isolation.run_migrations' => false,
            'tenancy.isolation.tenant_tables' => ['tenant_settings'],
            'tenancy.isolation.database_name_template' => 'tenant_{:id}',
            'tenancy.isolation.schema_name_template' => 'tenant_{:id}',
        ]);

        // 显式注册为 singleton（TenancyServiceProvider 不在本任务修改范围内）
        $this->app->singleton(IsolationService::class);
        $this->service = app(IsolationService::class);
    }

    /**
     * 在指定连接上创建与 TestCase 一致的 tenant_settings 表结构
     */
    protected function createTenantSettingsTable(string $connection): void
    {
        Schema::connection($connection)->create('tenant_settings', function (Blueprint $table) {
            $table->bigInteger('setting_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('group', 50);
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'group', 'key']);
            $table->index('tenant_id');
        });
    }

    // ---------- 策略注册与选择 ----------

    public function test_default_type_is_shared(): void
    {
        $this->assertSame('shared', $this->service->defaultType());
    }

    public function test_strategy_returns_registered_instances(): void
    {
        $this->assertInstanceOf(SharedDatabaseStrategy::class, $this->service->strategy('shared'));
        $this->assertInstanceOf(DatabasePerTenantStrategy::class, $this->service->strategy('database'));
        $this->assertInstanceOf(SchemaPerTenantStrategy::class, $this->service->strategy('schema'));
    }

    public function test_has_strategy(): void
    {
        $this->assertTrue($this->service->hasStrategy('shared'));
        $this->assertFalse($this->service->hasStrategy('unknown'));
    }

    public function test_strategy_unknown_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->strategy('unknown');
    }

    public function test_register_custom_strategy(): void
    {
        $custom = new class implements IsolationStrategyContract {
            public function getConnection(Tenant $tenant): string
            {
                return 'custom';
            }
            public function setupDatabase(Tenant $tenant): void {}
            public function teardownDatabase(Tenant $tenant): void {}
            public function migrate(Tenant $tenant): void {}
        };

        $this->service->registerStrategy('custom', $custom);

        $this->assertSame($custom, $this->service->strategy('custom'));
    }

    public function test_strategy_for_tenant_matches_tenant_isolation_type(): void
    {
        $tenant = Tenant::create(['tenant_id' => 2001, 'name' => 'T1', 'slug' => 't1', 'status' => 'active']);

        // DB 列默认值为 shared
        $reloaded = Tenant::find(2001);
        $this->assertSame('shared', $reloaded->isolation_type);
        $this->assertInstanceOf(
            SharedDatabaseStrategy::class,
            $this->service->strategyForTenant($reloaded)
        );

        // 切换为 database 策略后应返回对应策略
        $reloaded->isolation_type = 'database';
        $reloaded->save();
        $this->assertInstanceOf(
            DatabasePerTenantStrategy::class,
            $this->service->strategyForTenant($reloaded)
        );
    }

    // ---------- SharedDatabaseStrategy ----------

    public function test_shared_strategy_uses_default_connection(): void
    {
        $tenant = Tenant::create(['tenant_id' => 2002, 'name' => 'T2', 'slug' => 't2', 'status' => 'active']);

        $shared = $this->service->strategy('shared');

        $this->assertSame(config('database.default'), $shared->getConnection($tenant));
    }

    public function test_shared_setup_teardown_migrate_are_safe_noops(): void
    {
        $tenant = Tenant::create(['tenant_id' => 2003, 'name' => 'T3', 'slug' => 't3', 'status' => 'active']);
        $shared = $this->service->strategy('shared');

        // 无异常即通过：共享库依赖 TenantScope 行级隔离
        $shared->setupDatabase($tenant);
        $shared->migrate($tenant);
        $shared->teardownDatabase($tenant);

        $this->assertNull($tenant->database_name);
        $this->assertNull($tenant->schema_name);
    }

    // ---------- IsolationService 初始化与清理 ----------

    public function test_setup_for_tenant_with_shared_sets_isolation_type(): void
    {
        $tenant = Tenant::create(['tenant_id' => 2004, 'name' => 'T4', 'slug' => 't4', 'status' => 'active']);

        $this->service->setupForTenant($tenant, 'shared');

        $reloaded = Tenant::find(2004);
        $this->assertSame('shared', $reloaded->isolation_type);
    }

    public function test_setup_for_tenant_defaults_to_configured_type(): void
    {
        config(['tenancy.isolation.default' => 'shared']);
        $tenant = Tenant::create(['tenant_id' => 2005, 'name' => 'T5', 'slug' => 't5', 'status' => 'active']);

        $this->service->setupForTenant($tenant);

        $reloaded = Tenant::find(2005);
        $this->assertSame('shared', $reloaded->isolation_type);
    }

    public function test_setup_for_tenant_with_database_registers_connection(): void
    {
        $tenant = Tenant::create(['tenant_id' => 2006, 'name' => 'T6', 'slug' => 't6', 'status' => 'active']);

        $this->service->setupForTenant($tenant, 'database');

        $reloaded = Tenant::find(2006);
        $this->assertSame('database', $reloaded->isolation_type);
        $this->assertSame('tenant_2006', $reloaded->database_name);

        $conn = 'tenant.2006';
        $config = config("database.connections.{$conn}");
        $this->assertIsArray($config);
        $this->assertSame('sqlite', $config['driver']);
        $this->assertSame(':memory:', $config['database']);

        $this->assertSame($conn, $this->service->connectionForTenant($reloaded));
    }

    public function test_teardown_for_tenant_with_database_clears_connection_and_metadata(): void
    {
        $tenant = Tenant::create(['tenant_id' => 2007, 'name' => 'T7', 'slug' => 't7', 'status' => 'active']);
        $this->service->setupForTenant($tenant, 'database');

        $conn = 'tenant.2007';
        $this->assertNotNull(config("database.connections.{$conn}"));

        // 重新加载，保证 teardown 使用持久化的 isolation_type
        $reloaded = Tenant::find(2007);
        $this->service->teardownForTenant($reloaded);

        $this->assertNull(config("database.connections.{$conn}"));
        $after = Tenant::find(2007);
        $this->assertNull($after->database_name);
    }

    public function test_teardown_for_tenant_with_shared_is_noop(): void
    {
        $tenant = Tenant::create(['tenant_id' => 2008, 'name' => 'T8', 'slug' => 't8', 'status' => 'active']);
        $this->service->setupForTenant($tenant, 'shared');

        $reloaded = Tenant::find(2008);
        $this->service->teardownForTenant($reloaded);

        $this->assertSame('shared', Tenant::find(2008)->isolation_type);
    }

    // ---------- SchemaPerTenantStrategy（非 PostgreSQL 保护） ----------

    public function test_schema_strategy_setup_throws_on_non_postgres(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(trans('tenant.isolation_schema_unsupported'));

        $tenant = Tenant::create(['tenant_id' => 2009, 'name' => 'T9', 'slug' => 't9', 'status' => 'active']);
        $this->service->strategy('schema')->setupDatabase($tenant);
    }

    public function test_schema_strategy_teardown_throws_on_non_postgres(): void
    {
        $this->expectException(RuntimeException::class);

        $tenant = Tenant::create(['tenant_id' => 2010, 'name' => 'T10', 'slug' => 't10', 'status' => 'active']);
        $this->service->strategy('schema')->teardownDatabase($tenant);
    }

    // ---------- 迁移工具：shared → database ----------

    public function test_migrate_tool_moves_data_from_shared_to_database(): void
    {
        $tenantId = 2011;
        $tenant = Tenant::create(['tenant_id' => $tenantId, 'name' => 'TMig', 'slug' => 'tmig', 'status' => 'active']);
        $tenant->isolation_type = 'shared';
        $tenant->save();

        $sharedConn = config('database.default');

        // 在共享库为该租户准备 2 行数据
        DB::connection($sharedConn)->table('tenant_settings')->insert([
            ['setting_id' => 90001, 'tenant_id' => $tenantId, 'group' => 'info', 'key' => 'name', 'value' => 'Acme', 'is_encrypted' => false, 'description' => null, 'created_at' => null, 'updated_at' => null],
            ['setting_id' => 90002, 'tenant_id' => $tenantId, 'group' => 'info', 'key' => 'logo', 'value' => 'logo.png', 'is_encrypted' => false, 'description' => null, 'created_at' => null, 'updated_at' => null],
        ]);

        $this->assertSame(2, DB::connection($sharedConn)->table('tenant_settings')->where('tenant_id', $tenantId)->count());

        // 预注册目标连接（:memory:）并创建目标 schema，模拟 run_migrations=false 下运维已准备结构
        $targetConn = 'tenant.'.$tenantId;
        Config::set("database.connections.{$targetConn}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        $this->createTenantSettingsTable($targetConn);

        // 执行迁移工具：shared → database
        $this->service->migrate($tenantId, 'shared', 'database');

        // 租户隔离类型已切换，database_name 已写入
        $reloaded = Tenant::find($tenantId);
        $this->assertSame('database', $reloaded->isolation_type);
        $this->assertSame('tenant_2011', $reloaded->database_name);

        // 共享库中该租户数据已清理
        $this->assertSame(
            0,
            DB::connection($sharedConn)->table('tenant_settings')->where('tenant_id', $tenantId)->count()
        );

        // 新库中数据已迁入
        $rows = DB::connection($targetConn)->table('tenant_settings')->where('tenant_id', $tenantId)->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Acme', $rows->firstWhere('key', 'name')->value);
    }

    public function test_migrate_tool_throws_on_strategy_mismatch(): void
    {
        $tenantId = 2012;
        $tenant = Tenant::create(['tenant_id' => $tenantId, 'name' => 'TMismatch', 'slug' => 'tmismatch', 'status' => 'active']);
        $tenant->isolation_type = 'database';
        $tenant->save();

        $this->expectException(RuntimeException::class);
        // 租户当前为 database，但 fromStrategy 声明为 shared → 不一致
        $this->service->migrate($tenantId, 'shared', 'database');
    }

    public function test_migrate_tool_verify_failure_when_target_missing_rows(): void
    {
        $tenantId = 2013;
        $tenant = Tenant::create(['tenant_id' => $tenantId, 'name' => 'TVerify', 'slug' => 'tverify', 'status' => 'active']);
        $tenant->isolation_type = 'shared';
        $tenant->save();

        $sharedConn = config('database.default');
        DB::connection($sharedConn)->table('tenant_settings')->insert([
            ['setting_id' => 90003, 'tenant_id' => $tenantId, 'group' => 'info', 'key' => 'name', 'value' => 'Foo', 'is_encrypted' => false, 'description' => null, 'created_at' => null, 'updated_at' => null],
        ]);

        // 预注册目标连接但 *不创建表* → 导入将失败，触发异常
        $targetConn = 'tenant.'.$tenantId;
        Config::set("database.connections.{$targetConn}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->service->migrate($tenantId, 'shared', 'database');
    }

    // ---------- TenantContext 隔离访问 ----------

    public function test_tenant_context_exposes_isolation_fields(): void
    {
        $tenant = Tenant::create(['tenant_id' => 2014, 'name' => 'TCtx', 'slug' => 'tctx', 'status' => 'active']);
        $tenant->isolation_type = 'database';
        $tenant->database_name = 'tenant_2014';
        $tenant->schema_name = null;
        $tenant->save();

        TenantContext::setTenantId('2014');

        $this->assertSame('database', TenantContext::getIsolationType());
        $this->assertSame('tenant_2014', TenantContext::getDatabaseName());
        $this->assertNull(TenantContext::getSchemaName());
    }

    public function test_tenant_context_returns_null_without_tenant(): void
    {
        TenantContext::clear();

        $this->assertNull(TenantContext::getIsolationType());
        $this->assertNull(TenantContext::getDatabaseName());
        $this->assertNull(TenantContext::getSchemaName());
    }
}
