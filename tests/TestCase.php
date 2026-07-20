<?php

namespace MultiTenantSaas\Tests;

use Barryvdh\DomPDF\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Http\Middleware\CheckPermission;
use MultiTenantSaas\Modules\Auth\Http\Middleware\CheckRbacPermission;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\CheckFeatureFlag;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\EnsureTenantContext;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Operator\Http\Middleware\EnsureOperator;
use MultiTenantSaas\TenancyServiceProvider;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\SchemaModuleInterface;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static bool $schemaInitialized = false;

    /** @var array<string, SchemaModuleInterface> */
    private static array $moduleInstances = [];

    /** @var array<string, bool> */
    private static array $loadedModules = [];

    /** @var bool SQLite PRAGMA 已优化标记 */
    private static bool $pragmaOptimized = false;

    /**
     * 子类可声明需要的 Schema 模块
     *
     * @var array<class-string<SchemaModuleInterface>>
     */
    protected array $uses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();

        // MySQL: 每个测试前清空有数据的表
        if (DB::connection()->getDriverName() !== 'sqlite' && static::$schemaInitialized) {
            $this->truncateDataTables();
        }
        // SQLite: 使用 DELETE 而非 DROP/CREATE 重置数据
        elseif (DB::connection()->getDriverName() === 'sqlite' && static::$schemaInitialized) {
            $this->resetSqliteData();
        }

        // 重新填充需要 seed 数据的模块
        $this->reseedModules();

        // SQLite 无 NOW() 函数，注册自定义函数
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);
        }

        // 加载项目 lang 目录
        $langPath = realpath(__DIR__ . '/../lang');
        if ($langPath !== false) {
            app('translation.loader')->addPath($langPath);
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('tenant.identify', IdentifyTenant::class);
        $router->aliasMiddleware('tenant.ensure', EnsureTenantContext::class);
        $router->aliasMiddleware('tenant.permission', CheckPermission::class);
        $router->aliasMiddleware('rbac.permission', CheckRbacPermission::class);
        $router->aliasMiddleware('feature.flag', CheckFeatureFlag::class);
        $router->aliasMiddleware('operator.auth', EnsureOperator::class);

        $router->prefix('api')->group(function () {
            require __DIR__ . '/../routes/api.php';
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            ServiceProvider::class,
            TenancyServiceProvider::class,
        ];
    }

    protected function defineRoutes($router): void
    {
        $router->get('/api/v1/test', function () {
            return response()->json(['success' => true]);
        });
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.defaults.guard', 'sanctum');
        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => User::class,
        ]);

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);
        $app['config']->set('mail.default', 'log');
        $app['config']->set('broadcasting.default', 'log');

        // 注册项目视图路径
        $app['view']->addLocation(realpath(__DIR__ . '/../resources/views'));

        // 降低 bcrypt 轮数，加速测试中的密码操作
        $app['config']->set('hashing.bcrypt.rounds', 4);

        // SQLite 测试优化
        $app['config']->set('database.connections.sqlite.foreign_key_constraints', false);

        // 测试环境不使用默认租户
        $app['config']->set('tenancy.default_tenant_id', null);
    }

    protected function setUpDatabase(): void
    {
        $isMysql = DB::connection()->getDriverName() !== 'sqlite';
        $moduleClasses = $this->getRequiredModules();

        if ($isMysql) {
            if (! static::$schemaInitialized) {
                if (Schema::hasTable('tenants')) {
                    $this->truncateAllTables();
                } else {
                    DB::statement('SET FOREIGN_KEY_CHECKS=0');
                    $this->loadModules($moduleClasses);
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                $this->loadModules($moduleClasses);
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
            static::$schemaInitialized = true;
        } else {
            // SQLite: 首次建表，后续测试不再重建
            $tablesExist = Schema::hasTable('tenants');

            if (! static::$schemaInitialized || ! $tablesExist) {
                $this->optimizeSqlite();
                static::$loadedModules = [];
                static::$moduleInstances = [];
                $this->loadModules($moduleClasses);
                static::$schemaInitialized = true;
            } else {
                // 后续测试只加载新增模块（如果有）
                $this->loadModules($moduleClasses);
            }
        }
    }

    /**
     * SQLite PRAGMA 优化：关闭安全机制，最大化写入速度
     * 测试环境不需要恢复这些设置
     */
    private function optimizeSqlite(): void
    {
        if (static::$pragmaOptimized) {
            return;
        }

        try {
            $pdo = DB::connection()->getPdo();
            $pdo->exec('PRAGMA journal_mode=OFF');
            $pdo->exec('PRAGMA synchronous=OFF');
            $pdo->exec('PRAGMA locking_mode=EXCLUSIVE');
            $pdo->exec('PRAGMA temp_store=MEMORY');
            $pdo->exec('PRAGMA cache_size=-200000');  // 200MB cache
            $pdo->exec('PRAGMA foreign_keys=OFF');     // 测试环境永久关闭
        } catch (\Throwable $e) {
            // PRAGMAs may fail inside transactions (DatabaseTransactions trait)
            // This is safe to ignore - they're performance optimizations only
        }

        static::$pragmaOptimized = true;
    }

    /**
     * SQLite 数据重置：DELETE 而非 DROP/CREATE
     * 比 dropAllSqliteTables + loadModules 快 10-50 倍
     * foreign_keys 已在 optimizeSqlite() 中永久关闭, 无需再操作
     */
    private function resetSqliteData(): void
    {
        $pdo = DB::connection()->getPdo();

        // 确保 FK 关闭 (可能被新连接重置)
        $pdo->exec('PRAGMA foreign_keys=OFF');

        // 收集所有已加载模块的表
        $tables = [];
        foreach (array_keys(static::$loadedModules) as $class) {
            $tables = array_merge($tables, $this->getModuleInstance($class)->getTableNames());
        }
        $tables = array_unique($tables);

        // 反向排序: 子表先删
        $tables = array_reverse($tables);

        foreach ($tables as $table) {
            try {
                $pdo->exec("DELETE FROM \"{$table}\"");
            } catch (\Throwable $e) {
                // 表可能不存在，忽略
            }
        }

        // 清除自增序列
        try {
            $pdo->exec('DELETE FROM sqlite_sequence');
        } catch (\Throwable $e) {
            // sqlite_sequence 可能不存在
        }
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /**
     * @return array<class-string<SchemaModuleInterface>>
     */
    private function getRequiredModules(): array
    {
        $modules = $this->uses;
        if (! in_array(CoreModule::class, $modules, true)) {
            array_unshift($modules, CoreModule::class);
        }

        return $modules;
    }

    /**
     * @param  array<class-string<SchemaModuleInterface>>  $moduleClasses
     */
    private function loadModules(array $moduleClasses): void
    {
        foreach ($moduleClasses as $class) {
            if (isset(static::$loadedModules[$class])) {
                continue;
            }
            $module = $this->getModuleInstance($class);
            $module->createTables();
            static::$loadedModules[$class] = true;
        }
    }

    /**
     * 对支持 seedData() 的模块重新填充数据（每次 setUp 都调用）
     */
    private function reseedModules(): void
    {
        foreach (array_keys(static::$loadedModules) as $class) {
            $module = $this->getModuleInstance($class);
            if (method_exists($module, 'seedData')) {
                $module->seedData();
            }
        }
    }

    private function getModuleInstance(string $class): SchemaModuleInterface
    {
        if (! isset(static::$moduleInstances[$class])) {
            static::$moduleInstances[$class] = new $class;
        }

        return static::$moduleInstances[$class];
    }

    private function truncateAllTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $tables = DB::select('SHOW TABLES');
        foreach ($tables as $table) {
            $tableName = array_values((array) $table)[0];
            DB::statement("TRUNCATE TABLE `{$tableName}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function truncateDataTables(): void
    {
        $moduleTables = [];
        foreach (array_keys(static::$loadedModules) as $class) {
            $moduleTables = array_merge($moduleTables, $this->getModuleInstance($class)->getTableNames());
        }
        $moduleTables = array_unique($moduleTables);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        if (empty($moduleTables)) {
            $tables = DB::select('SHOW TABLES');
            foreach ($tables as $table) {
                $tableName = array_values((array) $table)[0];
                $count = (int) DB::selectOne("SELECT COUNT(*) AS c FROM `{$tableName}`")->c;
                if ($count > 0) {
                    DB::statement("TRUNCATE TABLE `{$tableName}`");
                }
            }
        } else {
            foreach ($moduleTables as $tableName) {
                if (! Schema::hasTable($tableName)) {
                    continue;
                }
                $count = (int) DB::selectOne("SELECT COUNT(*) AS c FROM `{$tableName}`")->c;
                if ($count > 0) {
                    DB::statement("TRUNCATE TABLE `{$tableName}`");
                }
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
