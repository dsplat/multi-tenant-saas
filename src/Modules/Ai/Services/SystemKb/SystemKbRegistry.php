<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

/**
 * 系统知识库文档发现器
 *
 * 仿 ModuleRegistry / 前端 module-loader 的零配置扫描：按 docs-as-knowledge
 * 目录约定发现所有 kb 文档，产出文档清单（source/module/path/title/checksum）。
 *
 * 扫描来源与覆盖优先级（高 → 低，同一 identity 只保留最高优先级）：
 * 1. project_module: app/Modules/<X>/resources/kb 下的 md（下游项目模块，零配置自动发现）
 * 2. project:        docs/kb 下的 md（下游项目全局；框架仓库内开发时归为 framework）
 * 3. vendor:         vendor/dsplat/<pkg>/resources/kb 与 vendor/dsplat/<pkg>/docs/kb 下的 md
 * 4. framework_module: src/Modules/<X>/resources/kb 下的 md（框架仓库内开发）
 *
 * identity = module + '/' + 文件名，与前端视图覆盖规则一致：项目 > vendor 包 > 框架核心。
 */
class SystemKbRegistry
{
    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    /**
     * 发现全部 kb 文档（去重后清单）
     *
     * @return list<array{
     *     source: string,
     *     module: string,
     *     path: string,
     *     absolute_path: string,
     *     title: string,
     *     audience: string,
     *     locale: string,
     *     version: string,
     *     checksum: string,
     * }>
     */
    public function discover(): array
    {
        $base = rtrim($this->basePath ?? base_path(), '/');
        $isFrameworkRepo = is_dir($base . '/src/Modules');

        // [source, glob 模式] 按优先级从高到低
        $scanGroups = [
            ['project_module', $base . '/app/Modules/*/resources/kb/*.md'],
            [$isFrameworkRepo ? 'framework' : 'project', $base . '/docs/kb/*.md'],
            ['vendor', $base . '/vendor/dsplat/*/resources/kb/*.md'],
            ['vendor', $base . '/vendor/dsplat/*/docs/kb/*.md'],
            ['vendor', $base . '/vendor/dsplat/*/src/Modules/*/resources/kb/*.md'],
            ['framework_module', $base . '/src/Modules/*/resources/kb/*.md'],
        ];

        $documents = [];

        foreach ($scanGroups as [$source, $pattern]) {
            foreach (glob($pattern) ?: [] as $file) {
                $entry = $this->parseFile($file, $source, $base);

                if ($entry === null) {
                    continue;
                }

                // 覆盖规则：先扫到的（高优先级）胜出
                $identity = $entry['module'] . '/' . basename($entry['path']);

                if (! isset($documents[$identity])) {
                    $documents[$identity] = $entry;
                }
            }
        }

        return array_values($documents);
    }

    /**
     * 解析单个 md 文件（frontmatter + 标题 + checksum）
     *
     * @return array<string, string>|null 不可读文件返回 null
     */
    private function parseFile(string $file, string $source, string $base): ?array
    {
        $content = @file_get_contents($file);

        if ($content === false || trim($content) === '') {
            return null;
        }

        $frontmatter = $this->parseFrontmatter($content);
        $relativePath = ltrim(str_replace($base, '', $file), '/');

        return [
            'source' => $source,
            'module' => $frontmatter['module'] ?? $this->inferModule($relativePath),
            'path' => $relativePath,
            'absolute_path' => $file,
            'title' => $frontmatter['title'] ?? $this->extractFirstHeading($content) ?? basename($file, '.md'),
            'audience' => in_array($frontmatter['audience'] ?? '', ['operator', 'internal'], true)
                ? $frontmatter['audience']
                : 'operator',
            'locale' => $frontmatter['locale'] ?? 'zh',
            'version' => $frontmatter['version'] ?? '',
            'checksum' => hash('sha256', $content),
        ];
    }

    /**
     * 解析 YAML frontmatter（仅支持一层 key: value，够用且零依赖）
     *
     * @return array<string, string>
     */
    private function parseFrontmatter(string $content): array
    {
        if (! preg_match('/\A---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            return [];
        }

        $result = [];

        foreach (preg_split('/\r?\n/', $matches[1]) as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(.+)$/', trim($line), $kv)) {
                $result[$kv[1]] = trim($kv[2], " \t\"'");
            }
        }

        return $result;
    }

    /**
     * 从路径推断所属模块（kebab-case），全局文档为空串
     */
    private function inferModule(string $relativePath): string
    {
        if (preg_match('#Modules/([^/]+)/resources/kb/#', $relativePath, $m)) {
            // PascalCase → kebab-case，连续大写视为整体（SSL → ssl），与拆包命名一致
            return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $m[1]));
        }

        // vendor 拆分包：vendor/dsplat/multi-tenant-saas-module-<X>/...
        if (preg_match('#vendor/dsplat/multi-tenant-saas-module-([^/]+)/#', $relativePath, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * 提取正文首个 markdown 标题作为 title 兜底
     */
    private function extractFirstHeading(string $content): ?string
    {
        // 跳过 frontmatter 后找首个 # 标题
        $body = preg_replace('/\A---\s*\n.*?\n---\s*\n/s', '', $content);

        if (preg_match('/^#{1,3}\s+(.+)$/m', $body, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
