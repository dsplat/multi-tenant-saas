<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\SystemKb\ConsoleRouteMapGenerator;

/**
 * ConsoleRouteMapGenerator 守恒测试
 *
 * 防止正则解析器静默漂移：fixture 中写入已知数量的路由，
 * 断言生成输出的表格行数与来源守恒（deficit=0），哨兵路径必在场。
 * routes.ts 书写风格变化导致解析遗漏时，本测试立即变红。
 */
class ConsoleRouteMapGeneratorTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDir = sys_get_temp_dir().'/kb-index-test-'.uniqid();
        mkdir($this->fixtureDir.'/app/Modules/Demo/resources/console', 0755, true);

        // 模拟真实 routes.ts 的多种书写风格
        file_put_contents($this->fixtureDir.'/app/Modules/Demo/resources/console/routes.ts', <<<'TS'
import { view } from '@/console/module-loader'

export default [
  { path: 'demos', name: 'DemoList', component: view('demo', 'DemoList'), meta: { title: '示例列表' } },
  { path: 'demos/new', name: 'DemoCreate', component: view('demo', 'DemoDetail'), meta: { title: '新建示例' } },
  { path: 'demos/:id', name: 'DemoDetail', component: view('demo', 'DemoDetail'), meta: { title: '示例详情' } },
]
TS);
    }

    protected function tearDown(): void
    {
        $dir = $this->fixtureDir;
        if (is_dir($dir)) {
            exec('rm -rf '.escapeshellarg($dir));
        }

        parent::tearDown();
    }

    public function test_route_count_conservation(): void
    {
        $generator = new ConsoleRouteMapGenerator($this->fixtureDir);
        $output = $generator->generate();

        // 来源守恒：fixture 中 3 条 path 定义，输出表格必须恰好 3 行数据行
        $sourceCount = substr_count(
            (string) file_get_contents($this->fixtureDir.'/app/Modules/Demo/resources/console/routes.ts'),
            'path:',
        );
        $this->assertSame(3, $sourceCount, 'fixture 自身应含 3 条路由');

        preg_match_all('/^\| [^|]+ \| \/[^|]+ \|/m', $output, $rows);
        $this->assertCount($sourceCount, $rows[0], '生成表格行数必须与 routes.ts 中 path 定义数守恒（解析器漂移检测）');
    }

    public function test_sentinel_paths_present(): void
    {
        $generator = new ConsoleRouteMapGenerator($this->fixtureDir);
        $output = $generator->generate();

        // 哨兵路径：解析器必须提取出 path 与 meta.title
        $this->assertStringContainsString('| 示例列表 | /demos |', $output);
        $this->assertStringContainsString('| 新建示例 | /demos/new |', $output);
        $this->assertStringContainsString('| 示例详情 | /demos/:id |', $output);
    }

    public function test_output_declares_machine_generated(): void
    {
        $generator = new ConsoleRouteMapGenerator($this->fixtureDir);
        $output = $generator->generate();

        $this->assertStringContainsString('generated_by: secretary:kb:index', $output);
        $this->assertStringContainsString('请勿手工编辑', $output);
    }

    public function test_empty_project_produces_valid_document(): void
    {
        $emptyDir = sys_get_temp_dir().'/kb-index-empty-'.uniqid();
        mkdir($emptyDir, 0755, true);

        try {
            $generator = new ConsoleRouteMapGenerator($emptyDir);
            $output = $generator->generate();

            // 空项目不崩溃，仍产出合法 frontmatter 文档
            $this->assertStringContainsString('# 控制台页面路由地图', $output);
        } finally {
            rmdir($emptyDir);
        }
    }

    /**
     * 回归点：knownPaths 地图必须与前端 module-loader 自动发现规则对齐
     *
     * 拥有自定义 routes.ts 的模块会跳过视图自动发现，其 knownPaths
     * 页面实际无路由可达；写入地图会误导 AI 带路（生产曾因此报
     * 「页面路径似乎已变更」）。无自定义路由的模块页面则照常收录。
     */
    public function test_known_paths_excludes_pages_of_modules_with_custom_routes(): void
    {
        $dir = sys_get_temp_dir().'/kb-index-knownpaths-'.uniqid();
        mkdir($dir.'/resources/js/console', 0755, true);
        mkdir($dir.'/app/Modules/Demo/resources/console/ui/element-plus/views', 0755, true);
        mkdir($dir.'/app/Modules/Open/resources/console/ui/element-plus/views', 0755, true);

        // Demo 有自定义 routes.ts → 自动发现关闭；Open 没有 → 自动发现生效
        file_put_contents($dir.'/app/Modules/Demo/resources/console/routes.ts', 'export default []');
        file_put_contents($dir.'/app/Modules/Demo/resources/console/ui/element-plus/views/DemoSettings.vue', '<template><div/></template>');
        file_put_contents($dir.'/app/Modules/Open/resources/console/ui/element-plus/views/OpenSettings.vue', '<template><div/></template>');

        file_put_contents($dir.'/resources/js/console/module-loader.ts', <<<'TS'
const knownPaths: Record<string, string> = {
  DemoSettings: 'demo-settings',
  OpenSettings: 'open-settings',
  GhostPage: 'ghost-path',
}
const pageTitleMap: Record<string, string> = {
  DemoSettings: '演示设置',
  OpenSettings: '开放设置',
}
TS);

        try {
            $output = (new ConsoleRouteMapGenerator($dir))->generate();

            $this->assertStringContainsString('| 开放设置 | /open-settings |', $output);
            $this->assertStringNotContainsString('/demo-settings', $output);
            // 无对应视图的幽灵条目不得入图
            $this->assertStringNotContainsString('/ghost-path', $output);
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
}
