<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\PluginService;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * PluginService 单元测试
 *
 * 覆盖：插件扫描、依赖检查、插件安装/卸载、插件启用/禁用、插件列表（租户隔离）
 */
class PluginServiceTest extends TestCase
{
    protected array $uses = [EventModule::class, PluginModule::class];

    private string $pluginsDir;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        TenantContext::setTenantId('1001');

        // 创建临时插件目录（清理可能残留的旧目录以保证干净状态）
        $this->pluginsDir = base_path('plugins');
        if (is_dir($this->pluginsDir)) {
            // 仅清理目录内容，保留目录本身以避免影响 testbench 运行时
            foreach (glob($this->pluginsDir . '/*') as $entry) {
                is_dir($entry) ? File::deleteDirectory($entry) : unlink($entry);
            }
        } else {
            mkdir($this->pluginsDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // 清理临时插件目录
        if (is_dir($this->pluginsDir)) {
            File::deleteDirectory($this->pluginsDir);
        }

        parent::tearDown();
    }

    /**
     * 创建测试插件目录与 manifest.json
     */
    private function createTestPlugin(string $name, array $manifest = []): void
    {
        $dir = $this->pluginsDir . '/' . $name;
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $manifest = array_merge([
            'name' => $name,
            'version' => '1.0.0',
            'description' => 'Test plugin ' . $name,
            'dependencies' => [],
        ], $manifest);

        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    // ---------- 插件扫描 ----------

    public function test_scan_available_returns_empty_when_no_plugins(): void
    {
        $service = app(PluginService::class);

        $plugins = $service->scanAvailable();

        $this->assertIsArray($plugins);
        $this->assertEmpty($plugins);
    }

    public function test_scan_available_returns_plugins_with_manifest(): void
    {
        $this->createTestPlugin('test-plugin-a');
        $this->createTestPlugin('test-plugin-b', ['version' => '2.0.0']);

        $service = app(PluginService::class);

        $plugins = $service->scanAvailable();

        $this->assertCount(2, $plugins);

        $pluginA = collect($plugins)->firstWhere('name', 'test-plugin-a');
        $this->assertNotNull($pluginA);
        $this->assertEquals('1.0.0', $pluginA['version']);
        $this->assertEquals('Test plugin test-plugin-a', $pluginA['description']);
    }

    public function test_scan_available_skips_dirs_without_manifest(): void
    {
        mkdir($this->pluginsDir . '/no-manifest', 0755, true);
        $this->createTestPlugin('has-manifest');

        $service = app(PluginService::class);

        $plugins = $service->scanAvailable();

        $this->assertCount(1, $plugins);
        $this->assertEquals('has-manifest', $plugins[0]['name']);
    }

    // ---------- 依赖检查 ----------

    public function test_check_dependencies_passes_for_loaded_extension(): void
    {
        $service = app(PluginService::class);

        $ok = $service->checkDependencies(['dependencies' => ['ext-openssl' => '*']]);

        $this->assertTrue($ok);
    }

    public function test_check_dependencies_fails_for_missing_extension(): void
    {
        $service = app(PluginService::class);

        $this->expectException(\RuntimeException::class);
        $service->checkDependencies(['dependencies' => ['ext-nonexistent_ext_xyz' => '*']]);
    }

    public function test_check_dependencies_passes_when_no_dependencies(): void
    {
        $service = app(PluginService::class);

        $this->assertTrue($service->checkDependencies([]));
        $this->assertTrue($service->checkDependencies(['name' => 'no-deps']));
    }

    public function test_check_dependencies_fails_for_missing_class(): void
    {
        $service = app(PluginService::class);

        $this->expectException(\RuntimeException::class);
        $service->checkDependencies(['dependencies' => ['NonExistent\Class\Name' => '^1.0']]);
    }

    // ---------- 插件安装/卸载 ----------

    public function test_install_creates_plugin_record(): void
    {
        $this->createTestPlugin('installable-plugin', [
            'dependencies' => ['ext-openssl' => '*'],
        ]);

        $service = app(PluginService::class);

        $pluginId = $service->install('installable-plugin', 1001);

        $this->assertGreaterThan(0, $pluginId);

        $plugin = DB::table('plugins')->where('id', $pluginId)->first();
        $this->assertNotNull($plugin);
        $this->assertEquals('installable-plugin', $plugin->name);
        $this->assertEquals('1.0.0', $plugin->version);
        $this->assertEquals(PluginService::STATUS_INSTALLED, $plugin->status);
        $this->assertEquals(1001, $plugin->tenant_id);
    }

    public function test_install_throws_when_plugin_not_found(): void
    {
        $service = app(PluginService::class);

        $this->expectException(\RuntimeException::class);
        $service->install('nonexistent-plugin', 1001);
    }

    public function test_install_throws_when_already_installed(): void
    {
        $this->createTestPlugin('dup-plugin');

        $service = app(PluginService::class);

        $service->install('dup-plugin', 1001);

        $this->expectException(\RuntimeException::class);
        $service->install('dup-plugin', 1001);
    }

    public function test_install_registers_dependencies(): void
    {
        $this->createTestPlugin('dep-plugin', [
            'dependencies' => ['ext-openssl' => '*'],
        ]);

        $service = app(PluginService::class);

        $pluginId = $service->install('dep-plugin', 1001);

        $deps = DB::table('plugin_dependencies')->where('plugin_id', $pluginId)->get();
        $this->assertCount(1, $deps);
        $this->assertEquals('ext-openssl', $deps->first()->dependency_name);
    }

    public function test_uninstall_removes_plugin_and_dependencies(): void
    {
        $this->createTestPlugin('removable-plugin');

        $service = app(PluginService::class);

        $pluginId = $service->install('removable-plugin', 1001);

        $result = $service->uninstall('removable-plugin', 1001);

        $this->assertTrue($result);
        $this->assertFalse(DB::table('plugins')->where('id', $pluginId)->exists());
        $this->assertEquals(0, DB::table('plugin_dependencies')->where('plugin_id', $pluginId)->count());
    }

    public function test_uninstall_throws_when_not_installed(): void
    {
        $service = app(PluginService::class);

        $this->expectException(\RuntimeException::class);
        $service->uninstall('not-installed', 1001);
    }

    // ---------- 插件启用/禁用 ----------

    public function test_enable_updates_status_to_enabled(): void
    {
        $this->createTestPlugin('enable-test');

        $service = app(PluginService::class);

        $pluginId = $service->install('enable-test', 1001);

        $service->enable('enable-test', 1001);

        $plugin = DB::table('plugins')->where('id', $pluginId)->first();
        $this->assertEquals(PluginService::STATUS_ENABLED, $plugin->status);
        $this->assertNotNull($plugin->enabled_at);
    }

    public function test_disable_updates_status_to_disabled(): void
    {
        $this->createTestPlugin('disable-test');

        $service = app(PluginService::class);

        $pluginId = $service->install('disable-test', 1001);
        $service->enable('disable-test', 1001);
        $service->disable('disable-test', 1001);

        $plugin = DB::table('plugins')->where('id', $pluginId)->first();
        $this->assertEquals(PluginService::STATUS_DISABLED, $plugin->status);
    }

    public function test_enable_throws_when_not_installed(): void
    {
        $service = app(PluginService::class);

        $this->expectException(\RuntimeException::class);
        $service->enable('not-installed', 1001);
    }

    // ---------- 插件列表（租户隔离）----------

    public function test_list_installed_isolates_by_tenant(): void
    {
        $this->createTestPlugin('tenant-a-plugin');
        $this->createTestPlugin('tenant-b-plugin');
        $this->createTestPlugin('system-plugin');

        $service = app(PluginService::class);

        $service->install('tenant-a-plugin', 1001);
        $service->install('tenant-b-plugin', 1002);
        $service->install('system-plugin', null);

        $plugins1001 = $service->listInstalled(1001);
        $this->assertEquals(2, $plugins1001->count());
        $names = $plugins1001->pluck('name')->toArray();
        $this->assertContains('tenant-a-plugin', $names);
        $this->assertContains('system-plugin', $names);
        $this->assertNotContains('tenant-b-plugin', $names);

        $plugins1002 = $service->listInstalled(1002);
        $this->assertEquals(2, $plugins1002->count());
        $this->assertContains('tenant-b-plugin', $plugins1002->pluck('name')->toArray());
    }

    public function test_list_installed_returns_only_system_when_no_tenant(): void
    {
        $this->createTestPlugin('sys-only');
        $this->createTestPlugin('tenant-only');

        $service = app(PluginService::class);

        $service->install('sys-only', null);
        $service->install('tenant-only', 1001);

        $plugins = $service->listInstalled(null);
        $this->assertEquals(1, $plugins->count());
        $this->assertEquals('sys-only', $plugins->first()->name);
    }

    // ---------- 插件配置 ----------

    public function test_get_config_returns_empty_when_not_installed(): void
    {
        $service = app(PluginService::class);

        $this->assertEquals([], $service->getConfig('not-installed', 1001));
    }

    public function test_update_config_throws_when_not_installed(): void
    {
        $service = app(PluginService::class);

        $this->expectException(\RuntimeException::class);
        $service->updateConfig('not-installed', ['key' => 'value'], 1001);
    }

    public function test_update_and_get_config(): void
    {
        $this->createTestPlugin('config-test');

        $service = app(PluginService::class);

        $service->install('config-test', 1001);
        $service->updateConfig('config-test', ['theme' => 'dark'], 1001);

        $config = $service->getConfig('config-test', 1001);
        $this->assertEquals('dark', $config['theme']);
    }
}
