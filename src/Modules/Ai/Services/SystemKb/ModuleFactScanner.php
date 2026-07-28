<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

/**
 * 模块事实扫描器
 *
 * 静态扫描单个模块目录，提取供 LLM 起草使用手册的"事实清单"：
 * composer 清单、路由定义、控制器/服务公开方法、迁移表结构、
 * 配置项、前端 console 路由。纯文本输出，不执行任何模块代码。
 *
 * 框架仓（src/Modules/<X>）与下游项目（app/Modules/<X>）目录约定
 * 一致，同一套扫描逻辑通吃。
 */
class ModuleFactScanner
{
    /**
     * 事实清单总字符上限（防止 LLM 上下文溢出）
     */
    private const MAX_FACTS_CHARS = 24000;

    /**
     * 单文件摘录字符上限
     */
    private const MAX_FILE_CHARS = 4000;

    /**
     * 扫描模块目录，产出事实清单文本
     */
    public function scan(string $moduleDir, string $moduleName): string
    {
        $sections = [];

        $sections[] = "# 模块事实清单：{$moduleName}";
        $sections[] = $this->scanManifest($moduleDir);
        $sections[] = $this->scanRoutes($moduleDir);
        $sections[] = $this->scanClasses($moduleDir . '/Http/Controllers', '控制器（API 入口）');
        $sections[] = $this->scanClasses($moduleDir . '/Services', '服务（业务能力）');
        $sections[] = $this->scanMigrations($moduleDir);
        $sections[] = $this->scanModels($moduleDir);
        $sections[] = $this->scanConfig($moduleDir);
        $sections[] = $this->scanFrontend($moduleDir);

        $facts = implode("\n\n", array_filter($sections));

        if (mb_strlen($facts) > self::MAX_FACTS_CHARS) {
            $facts = mb_substr($facts, 0, self::MAX_FACTS_CHARS) . "\n\n[事实清单超长已截断]";
        }

        return $facts;
    }

    /**
     * composer.json 清单（描述 + extra.saas 元信息）
     */
    private function scanManifest(string $moduleDir): string
    {
        $file = $moduleDir . '/composer.json';

        if (! is_file($file)) {
            return '';
        }

        $data = json_decode((string) file_get_contents($file), true) ?: [];

        $lines = ['## 模块清单'];

        if (! empty($data['description'])) {
            $lines[] = '描述：' . $data['description'];
        }

        $saas = $data['extra']['saas'] ?? [];

        if ($saas !== []) {
            $lines[] = '模块名：' . ($saas['name'] ?? '');
            $lines[] = '依赖模块：' . implode(', ', $saas['dependencies'] ?? []) ?: '无';
            $lines[] = '租户可开关：' . (! empty($saas['tenant_toggleable']) ? '是' : '否');
        }

        return implode("\n", $lines);
    }

    /**
     * 路由文件原文（通常很小，完整保留最准确）
     */
    private function scanRoutes(string $moduleDir): string
    {
        $parts = [];

        foreach (glob($moduleDir . '/Routes/*.php') ?: [] as $file) {
            $content = $this->excerpt($file);

            if ($content !== '') {
                $parts[] = '### ' . basename($file) . "\n```php\n{$content}\n```";
            }
        }

        return $parts === [] ? '' : "## 路由定义\n\n" . implode("\n\n", $parts);
    }

    /**
     * 类摘要：类名 + 类注释首行 + 公开方法（含方法注释首行）
     */
    private function scanClasses(string $dir, string $label): string
    {
        $entries = [];

        foreach ($this->phpFiles($dir) as $file) {
            $content = (string) file_get_contents($file);
            $class = basename($file, '.php');
            $summary = $this->firstDocLine($content, '/(?:abstract\s+)?class\s+' . preg_quote($class, '/') . '/');

            $methods = [];

            if (preg_match_all('/(?:\/\*\*\s*\n\s*\*\s*(.+?)\s*\n[\s\S]*?\*\/\s*\n\s*)?public function (\w+)\(/', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    if (in_array($m[2], ['__construct', '__invoke'], true) && trim($m[1]) === '') {
                        continue;
                    }

                    $methods[] = '  - ' . $m[2] . '()' . ($m[1] !== '' ? '：' . trim($m[1]) : '');
                }
            }

            $entries[] = '- **' . $class . '**' . ($summary !== '' ? '：' . $summary : '')
                . ($methods !== [] ? "\n" . implode("\n", $methods) : '');
        }

        return $entries === [] ? '' : "## {$label}\n\n" . implode("\n", $entries);
    }

    /**
     * 迁移文件：表名 + 字段定义行（含 comment 的最有价值）
     */
    private function scanMigrations(string $moduleDir): string
    {
        $entries = [];

        foreach (glob($moduleDir . '/Database/migrations/*.php') ?: [] as $file) {
            $content = (string) file_get_contents($file);

            if (! preg_match_all("/Schema::create\('([^']+)'/", $content, $tables)) {
                continue;
            }

            $columns = [];

            if (preg_match_all('/\$table->\w+\([^;]*\);/', $content, $cols)) {
                $columns = array_slice($cols[0], 0, 60);
            }

            $entries[] = '### 表：' . implode(', ', $tables[1]) . "\n```php\n" . implode("\n", $columns) . "\n```";
        }

        return $entries === [] ? '' : "## 数据表\n\n" . implode("\n\n", $entries);
    }

    /**
     * 模型：类名 + fillable 字段
     */
    private function scanModels(string $moduleDir): string
    {
        $entries = [];

        foreach ($this->phpFiles($moduleDir . '/Models') as $file) {
            $content = (string) file_get_contents($file);
            $class = basename($file, '.php');

            $fillable = '';

            if (preg_match('/\$fillable\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
                $fillable = trim(preg_replace('/[\s\'"]+/', ' ', $m[1]));
            }

            $entries[] = '- **' . $class . '**' . ($fillable !== '' ? '（字段：' . $fillable . '）' : '');
        }

        return $entries === [] ? '' : "## 模型\n\n" . implode("\n", $entries);
    }

    /**
     * 模块配置文件原文
     */
    private function scanConfig(string $moduleDir): string
    {
        $parts = [];

        foreach (glob($moduleDir . '/Config/*.php') ?: [] as $file) {
            $content = $this->excerpt($file, 2000);

            if ($content !== '') {
                $parts[] = '### ' . basename($file) . "\n```php\n{$content}\n```";
            }
        }

        return $parts === [] ? '' : "## 配置项\n\n" . implode("\n\n", $parts);
    }

    /**
     * 前端 console 路由/导航（菜单路径的事实来源）
     */
    private function scanFrontend(string $moduleDir): string
    {
        $parts = [];

        foreach (['resources/console/routes.ts', 'resources/console/nav.ts'] as $relative) {
            $file = $moduleDir . '/' . $relative;

            if (is_file($file)) {
                $parts[] = '### ' . $relative . "\n```ts\n" . $this->excerpt($file, 2000) . "\n```";
            }
        }

        return $parts === [] ? '' : "## 前端 console 页面\n\n" . implode("\n\n", $parts);
    }

    /**
     * 目录下 PHP 文件（含一级子目录）
     *
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = glob($dir . '/*.php') ?: [];

        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            $files = array_merge($files, glob($sub . '/*.php') ?: []);
        }

        return $files;
    }

    /**
     * 文件内容摘录（超长截断）
     */
    private function excerpt(string $file, int $limit = self::MAX_FILE_CHARS): string
    {
        $content = trim((string) @file_get_contents($file));

        return mb_strlen($content) > $limit
            ? mb_substr($content, 0, $limit) . "\n// [已截断]"
            : $content;
    }

    /**
     * 类声明前的 docblock 首行摘要
     */
    private function firstDocLine(string $content, string $classPattern): string
    {
        if (preg_match('/\/\*\*\s*\n\s*\*\s*(.+?)\s*\n[\s\S]*?\*\/\s*\n\s*' . trim($classPattern, '/') . '/', $content, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
