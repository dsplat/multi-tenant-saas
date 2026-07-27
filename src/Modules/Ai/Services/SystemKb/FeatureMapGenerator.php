<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * 功能分布图生成器（机器文档）
 *
 * 枚举已注册的 API 路由，按模块分组输出"功能 → 模块 → 接口"映射，
 * 是小秘书指路能力（navigate_to）的事实来源之一。
 * 前端 console 菜单路径由各模块 kb 手册补充（人写部分）。
 */
class FeatureMapGenerator
{
    /**
     * 生成功能分布 markdown（含 frontmatter）
     */
    public function generate(): string
    {
        $lines = [
            '---',
            'title: 功能分布图',
            'module: ',
            'audience: internal',
            'locale: zh',
            '---',
            '',
            '# 功能分布图',
            '',
            '> 本文档由 `secretary:kb:generate` 自动生成，请勿手工编辑。',
            '',
        ];

        foreach ($this->groupedRoutes() as $module => $routes) {
            $lines[] = "## {$module}";
            $lines[] = '';
            $lines[] = '| 方法 | 路径 | 路由名 |';
            $lines[] = '|---|---|---|';

            foreach ($routes as $route) {
                $lines[] = sprintf('| %s | %s | %s |', $route['methods'], $route['uri'], $route['name']);
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * 按模块（api 路径第三段）分组的路由清单
     *
     * @return array<string, list<array{methods: string, uri: string, name: string}>>
     */
    private function groupedRoutes(): array
    {
        $grouped = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $methods = implode(',', array_diff($route->methods(), ['HEAD', 'OPTIONS']));

            if ($methods === '') {
                continue;
            }

            // api/v1/<module>/... → module；不足三段归入 core
            $segments = explode('/', $uri);
            $module = $segments[2] ?? 'core';
            // 路径参数段不作为模块名
            $module = str_starts_with($module, '{') ? 'core' : $module;

            $grouped[$module][] = [
                'methods' => $methods,
                'uri' => '/'.$uri,
                'name' => $route->getName() ?? '-',
            ];
        }

        ksort($grouped);

        return $grouped;
    }
}
