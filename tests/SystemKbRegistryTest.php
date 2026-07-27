<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbRegistry;

/**
 * SystemKbRegistry 单元测试
 *
 * 覆盖：零配置发现、frontmatter 解析、覆盖优先级、模块推断、标题兜底
 */
class SystemKbRegistryTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/system_kb_registry_'.uniqid();
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
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function writeFile(string $relativePath, string $content): void
    {
        $absolute = $this->basePath.'/'.$relativePath;

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0777, true);
        }

        file_put_contents($absolute, $content);
    }

    private function registry(): SystemKbRegistry
    {
        return new SystemKbRegistry($this->basePath);
    }

    // ---------- 发现 ----------

    public function test_discovers_docs_kb_as_project_source(): void
    {
        $this->writeFile('docs/kb/guide.md', "# 使用指南\n\n正文内容");

        $documents = $this->registry()->discover();

        $this->assertCount(1, $documents);
        $this->assertEquals('project', $documents[0]['source']);
        $this->assertEquals('docs/kb/guide.md', $documents[0]['path']);
    }

    public function test_docs_kb_in_framework_repo_marked_as_framework(): void
    {
        // 存在 src/Modules 目录即视为框架仓库
        mkdir($this->basePath.'/src/Modules', 0777, true);
        $this->writeFile('docs/kb/guide.md', "# 指南\n\n正文");

        $documents = $this->registry()->discover();

        $this->assertEquals('framework', $documents[0]['source']);
    }

    public function test_discovers_project_module_kb(): void
    {
        $this->writeFile('app/Modules/Customer/resources/kb/faq.md', "# 客户 FAQ\n\n正文");

        $documents = $this->registry()->discover();

        $this->assertCount(1, $documents);
        $this->assertEquals('project_module', $documents[0]['source']);
        $this->assertEquals('customer', $documents[0]['module']);
    }

    public function test_discovers_framework_module_kb(): void
    {
        $this->writeFile('src/Modules/CouponCenter/resources/kb/usage.md', "# 用法\n\n正文");

        $documents = $this->registry()->discover();

        $this->assertCount(1, $documents);
        $this->assertEquals('framework_module', $documents[0]['source']);
        // PascalCase → kebab-case
        $this->assertEquals('coupon-center', $documents[0]['module']);
    }

    public function test_discovers_vendor_kb_and_infers_module_from_package(): void
    {
        $this->writeFile('vendor/dsplat/multi-tenant-saas-module-coupon/resources/kb/usage.md', "# 券\n\n正文");

        $documents = $this->registry()->discover();

        $this->assertCount(1, $documents);
        $this->assertEquals('vendor', $documents[0]['source']);
        $this->assertEquals('coupon', $documents[0]['module']);
    }

    public function test_skips_empty_file(): void
    {
        $this->writeFile('docs/kb/empty.md', "   \n  ");

        $this->assertCount(0, $this->registry()->discover());
    }

    // ---------- 覆盖优先级 ----------

    public function test_project_module_overrides_framework_module_with_same_identity(): void
    {
        // 同 identity（module=coupon + usage.md），project_module 优先级更高
        $this->writeFile('app/Modules/Coupon/resources/kb/usage.md', "# 项目版\n\n项目内容");
        $this->writeFile('src/Modules/Coupon/resources/kb/usage.md', "# 框架版\n\n框架内容");

        $documents = $this->registry()->discover();

        $this->assertCount(1, $documents);
        $this->assertEquals('project_module', $documents[0]['source']);
        $this->assertEquals('项目版', $documents[0]['title']);
    }

    public function test_different_filenames_do_not_override(): void
    {
        $this->writeFile('app/Modules/Coupon/resources/kb/a.md', "# A\n\n正文");
        $this->writeFile('src/Modules/Coupon/resources/kb/b.md', "# B\n\n正文");

        $this->assertCount(2, $this->registry()->discover());
    }

    // ---------- frontmatter 解析 ----------

    public function test_parses_frontmatter_fields(): void
    {
        $this->writeFile('docs/kb/manual.md', <<<'MD'
---
title: 操作手册
audience: internal
locale: en
version: 2.1
module: billing
---

# 正文标题

内容
MD);

        $documents = $this->registry()->discover();

        $this->assertEquals('操作手册', $documents[0]['title']);
        $this->assertEquals('internal', $documents[0]['audience']);
        $this->assertEquals('en', $documents[0]['locale']);
        $this->assertEquals('2.1', $documents[0]['version']);
        $this->assertEquals('billing', $documents[0]['module']);
    }

    public function test_invalid_audience_falls_back_to_operator(): void
    {
        $this->writeFile('docs/kb/doc.md', "---\ntitle: T\naudience: hacker\n---\n\n# T\n\n正文");

        $documents = $this->registry()->discover();

        $this->assertEquals('operator', $documents[0]['audience']);
    }

    public function test_title_falls_back_to_first_heading_then_filename(): void
    {
        $this->writeFile('docs/kb/with-heading.md', "# 首个标题\n\n正文");
        $this->writeFile('docs/kb/no-heading.md', "纯文本，没有标题");

        $documents = collect($this->registry()->discover())->keyBy(fn ($d) => basename($d['path']));

        $this->assertEquals('首个标题', $documents['with-heading.md']['title']);
        $this->assertEquals('no-heading', $documents['no-heading.md']['title']);
    }

    public function test_checksum_changes_with_content(): void
    {
        $this->writeFile('docs/kb/doc.md', "# V1\n\n内容一");
        $first = $this->registry()->discover()[0]['checksum'];

        $this->writeFile('docs/kb/doc.md', "# V2\n\n内容二");
        $second = $this->registry()->discover()[0]['checksum'];

        $this->assertNotEquals($first, $second);
        $this->assertEquals(64, strlen($first));
    }
}
