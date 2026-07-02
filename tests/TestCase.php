<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Middleware\CheckFeatureFlag;
use MultiTenantSaas\Middleware\CheckPermission;
use MultiTenantSaas\Middleware\CheckRbacPermission;
use MultiTenantSaas\Middleware\EnsureTenantContext;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\TenancyServiceProvider;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\SchemaModuleInterface;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Orchestra Testbench 明确忽略 DatabaseTransactions trait（见 setUpTheTestEnvironmentTraitToBeIgnored），
    // 且 Laravel Eloquent 内部也会启动事务，手动事务会冲突。
    // 方案：每个测试前 TRUNCATE 所有有数据的表（快速，仅首次建表）。
    private static bool $schemaInitialized = false;

    /**
     * 已实例化的模块缓存（静态，跨测试复用）
     * @var array<string, SchemaModuleInterface>
     */
    private static array $moduleInstances = [];

    /**
     * 已建表的模块类名集合（静态，记录哪些模块已建表）
     * @var array<string, bool>
     */
    private static array $loadedModules = [];

    /**
     * 子类可声明需要的 Schema 模块，只加载这些模块的表。
     * 默认加载 CoreModule。
     * 示例: protected array $uses = [AiModule::class, BillingModule::class];
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

        // SQLite 无 NOW() 函数，注册自定义函数以兼容源码中 DB::raw('NOW()') 的用法
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);
        }

        // 加载项目 lang 目录，使 trans()/__() 在测试中可解析翻译 key
        $langPath = realpath(__DIR__.'/../lang');
        if ($langPath !== false) {
            app('translation.loader')->addPath($langPath);
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('tenant.ensure', EnsureTenantContext::class);
        $router->aliasMiddleware('tenant.permission', CheckPermission::class);
        $router->aliasMiddleware('rbac.permission', CheckRbacPermission::class);
        $router->aliasMiddleware('feature.flag', CheckFeatureFlag::class);

        // 加载 API 路由
        $router->prefix('api')->group(function () {
            require __DIR__.'/../routes/api.php';
        });
    }

    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
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

        // 设置 APP_KEY 用于加密
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // 设置缓存为 array 驱动，供 MFA 验证码缓存等使用
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', [
            'driver' => 'array',
            'serialize' => false,
        ]);

        // 设置邮件驱动为 log，避免测试中真实投递
        $app['config']->set('mail.default', 'log');

        // 设置广播驱动为 log，使 isAvailable() 返回 true（部分测试会覆盖为 null 测试降级）
        $app['config']->set('broadcasting.default', 'log');
    }

    protected function setUpDatabase(): void
    {
        $isMysql = DB::connection()->getDriverName() !== 'sqlite';

        // 确定本测试需要加载的模块（默认 CoreModule）
        $moduleClasses = $this->getRequiredModules();

        if ($isMysql) {
            if (!static::$schemaInitialized) {
                if (Schema::hasTable('tenants')) {
                    // 表已存在（上次运行残留），清空数据但不重建表
                    $this->truncateAllTables();
                } else {
                    // 全新数据库，按模块建表
                    DB::statement('SET FOREIGN_KEY_CHECKS=0');
                    $this->loadModules($moduleClasses);
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }
            } else {
                // 已初始化过，但本次测试可能需要额外模块
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                $this->loadModules($moduleClasses);
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
            static::$schemaInitialized = true;
        } else {
            // SQLite :memory:：每次测试都是全新连接，直接建表
            // 重置已加载模块状态，因为 SQLite 内存数据库每次都是全新的
            static::$loadedModules = [];
            static::$moduleInstances = [];
            $this->loadModules($moduleClasses);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /**
     * 获取本测试需要的模块列表（默认包含 CoreModule）
     * @return array<class-string<SchemaModuleInterface>>
     */
    private function getRequiredModules(): array
    {
        $modules = $this->uses;
        // 始终包含 CoreModule
        if (!in_array(CoreModule::class, $modules, true)) {
            array_unshift($modules, CoreModule::class);
        }
        return $modules;
    }

    /**
     * 按需加载模块（跳过已加载的）
     * @param array<class-string<SchemaModuleInterface>> $moduleClasses
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
     * 获取模块实例（缓存）
     */
    private function getModuleInstance(string $class): SchemaModuleInterface
    {
        if (!isset(static::$moduleInstances[$class])) {
            static::$moduleInstances[$class] = new $class();
        }
        return static::$moduleInstances[$class];
    }

    /**
     * TRUNCATE 所有表（保留表结构，只清数据）
     * 用于 phpunit 进程首次运行时清除上次残留数据
     */
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

    /**
     * 快速清空有数据的表（仅 TRUNCATE 非空表，跳过空表节省时间）
     * 只清空已加载模块涉及的表
     */
    private function truncateDataTables(): void
    {
        // 收集所有已加载模块的表名
        $moduleTables = [];
        foreach (array_keys(static::$loadedModules) as $class) {
            $moduleTables = array_merge($moduleTables, $this->getModuleInstance($class)->getTableNames());
        }
        $moduleTables = array_unique($moduleTables);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // 如果模块表列表为空（不应发生），fallback 到全量
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
            // 只 truncate 已加载模块的表
            foreach ($moduleTables as $tableName) {
                if (!Schema::hasTable($tableName)) {
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
