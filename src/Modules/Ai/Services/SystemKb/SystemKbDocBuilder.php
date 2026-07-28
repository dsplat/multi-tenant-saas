<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

/**
 * 系统知识库文档构建器（AI 辅助，构建期工具）
 *
 * 工作流：发现模块 → ModuleFactScanner 提取事实 → facts checksum 增量判定
 * → SystemKbDrafter（LLM）起草使用手册 → 写入模块 resources/kb/usage.md。
 *
 * 关键设计：
 * - 文档是代码资产：构建发生在开发/发版时而非运行时，草稿人审后提交入库；
 * - 增量：frontmatter 记录 facts_checksum，模块代码未变则跳过（可 --force 重建）；
 * - 通吃框架与下游：src/Modules/<X>（框架仓）与 app/Modules/<X>（下游项目）
 *   目录约定一致，与 SystemKbRegistry / module-loader 同一套发现哲学；
 * - 起草失败（无 key/网络异常）跳过该模块并计入 failed，不阻断其余模块。
 */
class SystemKbDocBuilder
{
    public function __construct(
        private readonly ModuleFactScanner $scanner,
        private readonly SystemKbDrafter $drafter,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * 发现可构建的模块目录
     *
     * @return array<string, string> [模块名(kebab-case) => 模块绝对路径]
     */
    public function discoverModules(): array
    {
        $base = rtrim($this->basePath ?? base_path(), '/');
        $modules = [];

        // 下游项目模块优先（与 SystemKbRegistry 覆盖优先级一致）
        foreach (['app/Modules', 'src/Modules'] as $relative) {
            foreach (glob($base . '/' . $relative . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $pascal = basename($dir);

                // 非模块目录（如 Contracts 基类目录）：与 module-loader 同规则，
                // 必须存在 <Pascal>ServiceProvider.php
                if (! is_file($dir . '/' . $pascal . 'ServiceProvider.php')) {
                    continue;
                }

                // PascalCase → kebab-case，连续大写视为整体（SSL → ssl），与拆包命名一致
                $kebab = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $pascal));

                if (! isset($modules[$kebab])) {
                    $modules[$kebab] = $dir;
                }
            }
        }

        ksort($modules);

        return $modules;
    }

    /**
     * 构建单个模块的 kb 文档
     *
     * @return string 结果状态：built|unchanged|failed
     */
    public function build(string $moduleName, string $moduleDir, bool $force = false): string
    {
        $facts = $this->scanner->scan($moduleDir, $moduleName);
        $factsChecksum = hash('sha256', $facts);
        $target = $moduleDir . '/resources/kb/usage.md';

        if (! $force && $this->existingChecksum($target) === $factsChecksum) {
            return 'unchanged';
        }

        $draft = $this->drafter->draft($this->systemPrompt(), $facts);

        if ($draft === null) {
            return 'failed';
        }

        $dir = dirname($target);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            return 'failed';
        }

        file_put_contents($target, $this->wrapDocument($moduleName, $factsChecksum, $draft));

        return 'built';
    }

    /**
     * 读取既有文档 frontmatter 中的 facts_checksum
     */
    private function existingChecksum(string $target): ?string
    {
        if (! is_file($target)) {
            return null;
        }

        $head = (string) file_get_contents($target, false, null, 0, 600);

        return preg_match('/^facts_checksum:\s*([0-9a-f]{64})/m', $head, $m) ? $m[1] : null;
    }

    /**
     * 组装最终文档：frontmatter（含增量指纹）+ 草稿正文
     */
    private function wrapDocument(string $moduleName, string $factsChecksum, string $draft): string
    {
        // LLM 偶尔自带代码围栏/frontmatter，剥掉避免重复（先外层围栏后内层 frontmatter）
        $draft = (string) preg_replace('/\A```(?:markdown|md)?\s*\n(.*)\n```\z/s', '$1', trim($draft));
        $draft = (string) preg_replace('/\A---\s*\n.*?\n---\s*\n/s', '', trim($draft));

        return implode("\n", [
            '---',
            "module: {$moduleName}",
            'audience: operator',
            'locale: zh',
            "facts_checksum: {$factsChecksum}",
            'generated_by: secretary:kb:build',
            '---',
            '',
            trim($draft),
            '',
        ]);
    }

    /**
     * 起草系统提示词
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
你是 SaaS 系统的技术文档工程师。根据给出的「模块事实清单」（路由/服务/数据表/配置/前端页面），为运营人员撰写该模块的使用手册（markdown）。

要求：
1. 第一行为一级标题：`# <模块中文名>使用手册`；
2. 章节结构：模块简介（做什么、解决什么问题）→ 核心功能（按业务能力分节，用 ## 二级标题）→ 常见操作流程（步骤化）→ 相关配置说明（如有）；
3. 【最重要】只依据事实清单撰写，禁止编造清单中不存在的功能、接口或字段；尤其禁止编造任何具体数值（数量上限、默认有效期、配额、次数限制等）——清单没写的数字一个都不能出现；
4. 面向运营人员：讲业务能力与操作流程，不讲代码实现；接口路径可提及（帮助定位功能），但不展开请求参数细节；
5. 仅当清单明确包含前端 console 路由时才可注明控制台菜单路径；清单无前端路由信息则完全不要提及任何菜单路径或页面名称；
6. 不要输出"建议/提示/注意"类补充内容，除非其依据能在清单中找到；
7. 中文撰写，总长 800~2000 字；
8. 直接输出 markdown 正文，不要输出 frontmatter、代码围栏包裹或任何解释性开场白。
PROMPT;
    }
}
