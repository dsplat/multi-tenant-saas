<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbRegistry;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbSearchService;

/**
 * SystemKbSearchService 单元测试（纯文件型检索）
 *
 * 覆盖：关键词命中、中文 bigram、internal 受众过滤、topK 截断、
 * 空查询、无文档、frontmatter 剥离、分块标题匹配
 */
class SystemKbSearchServiceTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/system_kb_search_' . uniqid();
        mkdir($this->basePath . '/docs/kb', 0777, true);
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

    private function service(): SystemKbSearchService
    {
        return new SystemKbSearchService(new SystemKbRegistry($this->basePath));
    }

    private function writeDoc(string $name, string $content): void
    {
        file_put_contents($this->basePath . '/docs/kb/' . $name, $content);
    }

    // ---------- 基础检索 ----------

    public function test_keyword_search_returns_matching_chunks(): void
    {
        $this->writeDoc('guide.md', "## 优惠券发放\n\n优惠券的发放与核销流程说明\n\n## 抽奖\n\n与查询完全无关的抽奖内容");

        $results = $this->service()->search('优惠券怎么发放');

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('优惠券', $results[0]['content']);
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $this->writeDoc('guide.md', "## 节\n\n完全不相干的正文");

        $this->assertSame([], $this->service()->search('xyzzy'));
    }

    public function test_search_returns_empty_for_blank_query(): void
    {
        $this->writeDoc('guide.md', "## 节\n\n内容");

        $this->assertSame([], $this->service()->search('   '));
    }

    public function test_search_returns_empty_when_no_docs(): void
    {
        $this->assertSame([], $this->service()->search('任意查询'));
    }

    // ---------- 结果结构 ----------

    public function test_result_contains_document_metadata(): void
    {
        $this->writeDoc('intro.md', "---\nmodule: coupon\ntitle: 入门\n---\n\n## 第一节\n\n优惠券入门");

        $results = $this->service()->search('优惠券');

        $this->assertEquals('入门', $results[0]['title']);
        $this->assertEquals('coupon', $results[0]['module']);
        $this->assertEquals('docs/kb/intro.md', $results[0]['path']);
        $this->assertEquals('第一节', $results[0]['heading']);
        $this->assertIsFloat($results[0]['score']);
    }

    public function test_top_k_limits_results(): void
    {
        $sections = '';
        for ($i = 0; $i < 5; $i++) {
            $sections .= "## 节{$i}\n\n优惠券相关内容 {$i}\n\n";
        }
        $this->writeDoc('multi.md', $sections);

        $this->assertCount(2, $this->service()->search('优惠券', 2));
    }

    // ---------- internal 受众过滤 ----------

    public function test_internal_documents_excluded_by_default(): void
    {
        $this->writeDoc('internal.md', "---\naudience: internal\n---\n\n## 节\n\n优惠券内部实现细节");

        $this->assertSame([], $this->service()->search('优惠券'));
    }

    public function test_internal_documents_included_when_requested(): void
    {
        $this->writeDoc('internal.md', "---\naudience: internal\n---\n\n## 节\n\n优惠券内部实现细节");

        $results = $this->service()->search('优惠券', 5, true);

        $this->assertCount(1, $results);
    }

    // ---------- 中文 bigram ----------

    public function test_chinese_bigram_matches_without_spaces(): void
    {
        $this->writeDoc('guide.md', "## 数字员工\n\n如何创建数字员工并配置角色");

        // 查询无空格，靠 bigram 切分匹配
        $results = $this->service()->search('如何创建数字员工');

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('数字员工', $results[0]['content']);
    }

    // ---------- 分块 ----------

    public function test_document_without_headings_becomes_single_chunk(): void
    {
        $this->writeDoc('flat.md', '没有任何二级标题的短文档，整体成一块。优惠券相关。');

        $results = $this->service()->search('优惠券');

        $this->assertCount(1, $results);
        $this->assertEquals('', $results[0]['heading']);
    }

    public function test_frontmatter_stripped_from_chunks(): void
    {
        $this->writeDoc('doc.md', "---\nmodule: test\ntitle: 测试\n---\n\n## 功能\n\n优惠券功能说明");

        $results = $this->service()->search('优惠券');

        $this->assertNotEmpty($results);
        $this->assertStringNotContainsString('module: test', $results[0]['content']);
    }
}
