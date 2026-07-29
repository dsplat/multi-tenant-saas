<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

/**
 * 控制台路由地图生成器（机器文档）
 *
 * 扫描项目 + 框架所有模块的 routes.ts，提取 { path, title } 映射，
 * 并补充 module-loader knownPaths 自动发现页面，输出 markdown 路由地图。
 * 是 AI 小助手 navigate 能力的事实来源。
 *
 * 由 secretary:kb:index 在部署时自动生成，禁止手工编辑。
 */
class ConsoleRouteMapGenerator
{
    /** 项目根目录 */
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? base_path();
    }

    /**
     * 生成控制台路由地图 markdown（含 frontmatter）
     */
    public function generate(): string
    {
        $sections = $this->collectRoutes();

        $lines = [
            '---',
            'title: 控制台页面路由地图',
            'module: ',
            'audience: operator',
            'locale: zh',
            'generated_by: secretary:kb:index',
            'generated_at: '.date('c'),
            '---',
            '',
            '# 控制台页面路由地图',
            '',
            '> 本文档由 `secretary:kb:index` 自动生成，请勿手工编辑。',
            '> AI 小助手在带路（navigate）或正文引用页面时，**必须**使用本文档中的路径，禁止猜测。',
            '',
            '## 使用方式',
            '',
            '- navigate 工具的 `route_path` 参数：填写"路由路径"列的值（以 `/` 开头）',
            '- Markdown 链接：`[页面名称](/路由路径)`',
            '- 带参数路径（如 `:id`）：替换为实际 ID，如 `/customers/42`',
            '',
        ];

        foreach ($sections as $label => $routes) {
            $lines[] = "## {$label}";
            $lines[] = '';
            $lines[] = '| 页面名称 | 路由路径 | 说明 |';
            $lines[] = '|---------|---------|------|';

            foreach ($routes as $route) {
                $lines[] = sprintf('| %s | %s | %s |', $route['title'], $route['path'], $route['desc']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * 收集所有路由，按模块分组
     *
     * @return array<string, list<array{path: string, title: string, desc: string}>>
     */
    private function collectRoutes(): array
    {
        $sections = [];

        // 1. 项目模块 routes.ts（app/Modules/*/resources/console/routes.ts）
        $projectRoutes = $this->parseRoutesTsFiles($this->basePath.'/app/Modules');
        foreach ($projectRoutes as $module => $routes) {
            $label = $this->moduleLabel($module);
            $sections[$label] = array_merge($sections[$label] ?? [], $routes);
        }

        // 2. 框架 split 包 routes.ts（vendor/dsplat/*/resources/console/routes.ts）
        $vendorRoutes = $this->parseRoutesTsFiles($this->basePath.'/vendor/dsplat', 'vendor');
        foreach ($vendorRoutes as $module => $routes) {
            $label = $this->moduleLabel($module);
            $sections[$label] = array_merge($sections[$label] ?? [], $routes);
        }

        // 3. 框架 standalone routes.ts（src/Modules/*/resources/console/routes.ts）
        $srcRoutes = $this->parseRoutesTsFiles($this->basePath.'/src/Modules');
        foreach ($srcRoutes as $module => $routes) {
            $label = $this->moduleLabel($module);
            $sections[$label] = array_merge($sections[$label] ?? [], $routes);
        }

        // 4. knownPaths 自动发现页面（module-loader 的 view 自动注册）
        $knownPages = $this->parseKnownPaths();
        if ($knownPages !== []) {
            $sections['系统配置'] = array_merge($sections['系统配置'] ?? [], $knownPages);
        }

        // 按 label 排序
        ksort($sections);

        return $sections;
    }

    /**
     * 扫描目录下所有模块的 routes.ts 并解析
     *
     * @return array<string, list<array{path: string, title: string, desc: string}>>
     */
    private function parseRoutesTsFiles(string $modulesDir, string $type = 'project'): array
    {
        $result = [];

        $pattern = $modulesDir.'/*/resources/console/routes.ts';
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $module = $this->extractModuleName($file, $modulesDir);
            if ($module === null) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $routes = $this->parseRoutesTs($content);
            if ($routes !== []) {
                $result[$module] = $routes;
            }
        }

        return $result;
    }

    /**
     * 正则解析 routes.ts 内容，提取 path + meta.title
     *
     * 匹配格式：{ path: 'xxx', name: 'Yyy', component: ..., meta: { title: '中文标题' } }
     *
     * @return list<array{path: string, title: string, desc: string}>
     */
    private function parseRoutesTs(string $content): array
    {
        $routes = [];

        // 匹配每个路由对象的 path 和 meta.title
        // 支持单引号和双引号
        preg_match_all(
            '/\{\s*path:\s*[\'"]([^\'"]+)[\'"].*?meta:\s*\{[^}]*title:\s*[\'"]([^\'"]+)[\'"]/s',
            $content,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $m) {
            $path = $m[1];
            $title = $m[2];

            // 跳过 redirect-only 路由（无 component）
            if (str_contains($title, 'redirect')) {
                continue;
            }

            $routes[] = [
                'path' => '/'.ltrim($path, '/'),
                'title' => $title,
                'desc' => $title,
            ];
        }

        return $routes;
    }

    /**
     * 解析 module-loader.ts 中的 knownPaths 和 pageTitleMap
     *
     * @return list<array{path: string, title: string, desc: string}>
     */
    private function parseKnownPaths(): array
    {
        // 尝试多个可能位置（项目级可能是 re-export，需继续找 vendor 原始文件）
        $candidates = [
            $this->basePath.'/resources/js/console/module-loader.ts',
            $this->basePath.'/vendor/dsplat/multi-tenant-saas/resources/js/console/module-loader.ts',
        ];

        $content = null;
        foreach ($candidates as $file) {
            if (! file_exists($file)) {
                continue;
            }
            $raw = file_get_contents($file);
            if ($raw !== false && str_contains($raw, 'knownPaths')) {
                $content = $raw;
                break;
            }
        }

        if ($content === null) {
            return [];
        }

        $pages = [];

        // 提取 knownPaths: { PageName: 'path', ... }
        if (preg_match('/const\s+knownPaths[^{]*\{([^}]+)\}/s', $content, $m)) {
            preg_match_all('/(\w+):\s*[\'"]([^\'"]+)[\'"]/', $m[1], $pairs, PREG_SET_ORDER);

            // 提取 pageTitleMap 获取中文标题
            $titleMap = [];
            if (preg_match('/const\s+pageTitleMap[^{]*\{([^}]+)\}/s', $content, $tm)) {
                preg_match_all('/(\w+):\s*[\'"]([^\'"]+)[\'"]/', $tm[1], $titlePairs, PREG_SET_ORDER);
                foreach ($titlePairs as $tp) {
                    $titleMap[$tp[1]] = $tp[2];
                }
            }

            foreach ($pairs as $pair) {
                $pageName = $pair[1];
                $path = $pair[2];
                $title = $titleMap[$pageName] ?? $this->pageNameToLabel($pageName);

                $pages[] = [
                    'path' => '/'.ltrim($path, '/'),
                    'title' => $title,
                    'desc' => $title.'（框架自动发现）',
                ];
            }
        }

        return $pages;
    }

    /**
     * 从文件路径提取模块名
     */
    private function extractModuleName(string $file, string $modulesDir): ?string
    {
        $relative = str_replace($modulesDir.'/', '', $file);
        $parts = explode('/', $relative);

        return $parts[0] ?? null;
    }

    /**
     * 模块名 → 中文分组标签
     */
    private function moduleLabel(string $module): string
    {
        // 去除 split 包前缀
        $module = preg_replace('/^multi-tenant-saas-module-/', '', $module);

        $labels = [
            'customer' => '客户管理', 'Customer' => '客户管理',
            'community' => '社群运营', 'Community' => '社群运营',
            'channel' => '渠道管理', 'Channel' => '渠道管理',
            'marketing' => '营销活动', 'Marketing' => '营销活动',
            'content' => '内容与素材', 'Content' => '内容与素材',
            'membership' => '会员体系', 'Membership' => '会员体系',
            'analytics' => '数据分析', 'Analytics' => '数据分析',
            'staff' => '员工与权限', 'Staff' => '员工与权限',
            'product' => '商品与优惠', 'Product' => '商品与优惠',
            'coupon' => '商品与优惠', 'Coupon' => '商品与优惠',
            'distribution' => '商品与优惠', 'Distribution' => '商品与优惠',
            'knowledge' => '知识库', 'Knowledge' => '知识库',
            'ai' => 'AI 数字员工', 'AI' => 'AI 数字员工', 'Ai' => 'AI 数字员工',
            'event' => '活动管理', 'Event' => '活动管理',
            'lottery' => '互动玩法', 'Lottery' => '互动玩法',
            'voting' => '互动玩法', 'Voting' => '互动玩法',
            'platform' => '平台运营', 'Platform' => '平台运营',
            'sms' => '平台运营', 'Sms' => '平台运营',
            'mcp' => '平台运营', 'Mcp' => '平台运营',
            'chat-archive' => '平台运营', 'ChatArchive' => '平台运营',
            'auth' => '系统配置', 'billing' => '系统配置',
            'user' => '系统配置', 'payment' => '系统配置',
            'api-token' => '系统配置', 'workflow' => '系统配置',
            'notification' => '系统配置',
        ];

        return $labels[$module] ?? ucfirst($module);
    }

    /**
     * PascalCase → 可读标签
     */
    private function pageNameToLabel(string $name): string
    {
        return trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name);
    }
}
