<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\SystemKb\ModuleFactScanner;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbDocBuilder;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbDrafter;

/**
 * SystemKbDocBuilder 单元测试（kb:build 构建器）
 *
 * 覆盖：模块发现（框架/下游/优先级/非模块目录排除）、草稿落盘与
 * frontmatter 结构、facts checksum 增量跳过、--force 重建、
 * 起草失败 fail-open、LLM 输出围栏/frontmatter 剥离
 */
class SystemKbDocBuilderTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/system_kb_builder_' . uniqid();
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->basePath);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    /**
     * 构造固定输出的 mock 起草器（null = 模拟 LLM 失败）
     */
    private function drafter(?string $output): SystemKbDrafter
    {
        return new class($output) extends SystemKbDrafter
        {
            public int $calls = 0;

            public function __construct(private readonly ?string $output) {}

            public function draft(string $systemPrompt, string $userPrompt): ?string
            {
                $this->calls++;

                return $this->output;
            }
        };
    }

    private function builder(?SystemKbDrafter $drafter = null): SystemKbDocBuilder
    {
        return new SystemKbDocBuilder(
            new ModuleFactScanner,
            $drafter ?? $this->drafter("# 优惠券使用手册\n\n## 简介\n\n正文"),
            $this->basePath,
        );
    }

    /**
     * 建一个最小合法模块（含 ServiceProvider + 路由）
     */
    private function makeModule(string $relative, string $pascal): string
    {
        $dir = $this->basePath . '/' . $relative . '/' . $pascal;
        mkdir($dir . '/Routes', 0777, true);
        file_put_contents($dir . "/{$pascal}ServiceProvider.php", "<?php class {$pascal}ServiceProvider {}");
        file_put_contents($dir . '/Routes/api.php', '<?php // routes');

        return $dir;
    }

    // ---------- 模块发现 ----------

    public function test_discovers_framework_and_project_modules(): void
    {
        $this->makeModule('src/Modules', 'Coupon');
        $this->makeModule('app/Modules', 'Customer');

        $modules = $this->builder()->discoverModules();

        $this->assertArrayHasKey('coupon', $modules);
        $this->assertArrayHasKey('customer', $modules);
    }

    public function test_skips_directories_without_service_provider(): void
    {
        mkdir($this->basePath . '/src/Modules/Contracts', 0777, true);
        file_put_contents($this->basePath . '/src/Modules/Contracts/SomeInterface.php', '<?php');

        $this->assertArrayNotHasKey('contracts', $this->builder()->discoverModules());
    }

    public function test_project_module_takes_priority_over_framework(): void
    {
        $frameworkDir = $this->makeModule('src/Modules', 'Coupon');
        $projectDir = $this->makeModule('app/Modules', 'Coupon');

        $modules = $this->builder()->discoverModules();

        $this->assertEquals($projectDir, $modules['coupon']);
        $this->assertNotEquals($frameworkDir, $modules['coupon']);
    }

    public function test_pascal_to_kebab_module_name(): void
    {
        $this->makeModule('src/Modules', 'CouponCenter');
        $this->makeModule('src/Modules', 'SSL');

        $modules = $this->builder()->discoverModules();

        $this->assertArrayHasKey('coupon-center', $modules);
        // 连续大写视为整体，与拆包命名 module-ssl 一致
        $this->assertArrayHasKey('ssl', $modules);
        $this->assertArrayNotHasKey('s-s-l', $modules);
    }

    // ---------- 构建与增量 ----------

    public function test_build_writes_doc_with_frontmatter(): void
    {
        $dir = $this->makeModule('src/Modules', 'Coupon');

        $result = $this->builder()->build('coupon', $dir);

        $this->assertEquals('built', $result);

        $content = (string) file_get_contents($dir . '/resources/kb/usage.md');
        $this->assertStringContainsString('module: coupon', $content);
        $this->assertStringContainsString('audience: operator', $content);
        $this->assertMatchesRegularExpression('/facts_checksum: [0-9a-f]{64}/', $content);
        $this->assertStringContainsString('# 优惠券使用手册', $content);
    }

    public function test_second_build_skips_when_facts_unchanged(): void
    {
        $dir = $this->makeModule('src/Modules', 'Coupon');
        $drafter = $this->drafter("# 手册\n\n正文");
        $builder = $this->builder($drafter);

        $builder->build('coupon', $dir);
        $result = $builder->build('coupon', $dir);

        $this->assertEquals('unchanged', $result);
        $this->assertEquals(1, $drafter->calls);
    }

    public function test_rebuild_when_module_code_changes(): void
    {
        $dir = $this->makeModule('src/Modules', 'Coupon');
        $builder = $this->builder();

        $builder->build('coupon', $dir);
        file_put_contents($dir . '/Routes/api.php', '<?php // new route added');

        $this->assertEquals('built', $builder->build('coupon', $dir));
    }

    public function test_force_rebuilds_even_when_unchanged(): void
    {
        $dir = $this->makeModule('src/Modules', 'Coupon');
        $drafter = $this->drafter("# 手册\n\n正文");
        $builder = $this->builder($drafter);

        $builder->build('coupon', $dir);
        $result = $builder->build('coupon', $dir, force: true);

        $this->assertEquals('built', $result);
        $this->assertEquals(2, $drafter->calls);
    }

    // ---------- 失败与输出清洗 ----------

    public function test_build_fails_open_when_drafter_unavailable(): void
    {
        $dir = $this->makeModule('src/Modules', 'Coupon');

        $result = $this->builder($this->drafter(null))->build('coupon', $dir);

        $this->assertEquals('failed', $result);
        $this->assertFileDoesNotExist($dir . '/resources/kb/usage.md');
    }

    public function test_strips_llm_code_fence_and_frontmatter(): void
    {
        $dir = $this->makeModule('src/Modules', 'Coupon');
        $llmOutput = "```markdown\n---\ntitle: 多余的\n---\n# 手册\n\n正文\n```";

        $this->builder($this->drafter($llmOutput))->build('coupon', $dir);

        $content = (string) file_get_contents($dir . '/resources/kb/usage.md');
        $this->assertStringNotContainsString('```', $content);
        $this->assertStringNotContainsString('title: 多余的', $content);
        $this->assertStringContainsString('# 手册', $content);
        // 构建器自己的 frontmatter 保留
        $this->assertStringContainsString('module: coupon', $content);
    }
}
