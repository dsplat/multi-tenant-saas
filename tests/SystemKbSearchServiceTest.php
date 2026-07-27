<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Models\SystemKbChunk;
use MultiTenantSaas\Modules\Ai\Models\SystemKbDocument;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbEmbedder;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbSearchService;
use MultiTenantSaas\Tests\Schema\SystemKbModule;

/**
 * SystemKbSearchService 单元测试
 *
 * 覆盖：关键词退化检索、混合打分、internal 受众过滤、topK 截断、空查询
 */
class SystemKbSearchServiceTest extends TestCase
{
    protected array $uses = [SystemKbModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        // embedding_model 为空 → 查询向量为 null，默认走纯关键词
        config(['ai.secretary.embedding_model' => '']);
    }

    private function service(?SystemKbEmbedder $embedder = null): SystemKbSearchService
    {
        return new SystemKbSearchService($embedder ?? new SystemKbEmbedder);
    }

    private function createDocument(array $overrides = []): SystemKbDocument
    {
        static $sequence = 0;
        $sequence++;

        return SystemKbDocument::create(array_merge([
            'source' => 'framework',
            'module' => '',
            'path' => "docs/kb/doc-{$sequence}.md",
            'title' => "文档{$sequence}",
            'audience' => 'operator',
            'locale' => 'zh',
            'version' => '',
            'checksum' => str_repeat('a', 64),
        ], $overrides));
    }

    private function createChunk(SystemKbDocument $document, string $content, array $overrides = []): SystemKbChunk
    {
        return SystemKbChunk::create(array_merge([
            'document_id' => $document->document_id,
            'position' => 0,
            'heading' => '',
            'content' => $content,
            'embedding' => null,
        ], $overrides));
    }

    // ---------- 关键词检索（embedding 缺失退化） ----------

    public function test_keyword_search_returns_matching_chunks(): void
    {
        $document = $this->createDocument(['title' => '优惠券指南']);
        $this->createChunk($document, '优惠券的发放与核销流程说明');
        $this->createChunk($document, '与查询完全无关的抽奖内容');

        $results = $this->service()->search('优惠券怎么发放');

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('优惠券', $results[0]['content']);
    }

    public function test_search_returns_empty_for_no_match(): void
    {
        $document = $this->createDocument();
        $this->createChunk($document, '完全不相干的正文');

        $this->assertSame([], $this->service()->search('xyzzy'));
    }

    public function test_search_returns_empty_for_blank_query(): void
    {
        $document = $this->createDocument();
        $this->createChunk($document, '内容');

        $this->assertSame([], $this->service()->search('   '));
    }

    public function test_search_returns_empty_when_no_chunks(): void
    {
        $this->assertSame([], $this->service()->search('任意查询'));
    }

    public function test_result_contains_document_metadata(): void
    {
        $document = $this->createDocument(['title' => '入门', 'module' => 'coupon', 'path' => 'docs/kb/intro.md']);
        $this->createChunk($document, '优惠券入门', ['heading' => '第一节']);

        $results = $this->service()->search('优惠券');

        $this->assertEquals('入门', $results[0]['title']);
        $this->assertEquals('coupon', $results[0]['module']);
        $this->assertEquals('docs/kb/intro.md', $results[0]['path']);
        $this->assertEquals('第一节', $results[0]['heading']);
        $this->assertIsFloat($results[0]['score']);
    }

    public function test_top_k_limits_results(): void
    {
        $document = $this->createDocument();
        for ($i = 0; $i < 5; $i++) {
            $this->createChunk($document, "优惠券相关内容 {$i}", ['position' => $i]);
        }

        $this->assertCount(2, $this->service()->search('优惠券', 2));
    }

    // ---------- internal 受众过滤 ----------

    public function test_internal_documents_excluded_by_default(): void
    {
        $internal = $this->createDocument(['audience' => 'internal']);
        $this->createChunk($internal, '优惠券内部实现细节');

        $this->assertSame([], $this->service()->search('优惠券'));
    }

    public function test_internal_documents_included_when_requested(): void
    {
        $internal = $this->createDocument(['audience' => 'internal']);
        $this->createChunk($internal, '优惠券内部实现细节');

        $results = $this->service()->search('优惠券', 5, true);

        $this->assertCount(1, $results);
    }

    // ---------- 混合打分 ----------

    public function test_vector_similarity_boosts_ranking(): void
    {
        $document = $this->createDocument();
        // 关键词均不命中，只靠向量：chunk A 与查询向量同向，chunk B 反向
        $this->createChunk($document, 'alpha', ['embedding' => [1.0, 0.0], 'position' => 0]);
        $this->createChunk($document, 'beta', ['embedding' => [-1.0, 0.0], 'position' => 1]);

        $embedder = new class extends SystemKbEmbedder
        {
            public function embed(string $text): ?array
            {
                return [1.0, 0.0];
            }
        };

        $results = $this->service($embedder)->search('查询词');

        $this->assertCount(1, $results);
        $this->assertEquals('alpha', $results[0]['content']);
    }

    public function test_chunks_without_embedding_still_match_by_keyword(): void
    {
        $document = $this->createDocument();
        $this->createChunk($document, '优惠券操作说明', ['embedding' => null]);

        $embedder = new class extends SystemKbEmbedder
        {
            public function embed(string $text): ?array
            {
                return [1.0, 0.0];
            }
        };

        // 查询有向量、chunk 无向量 → 该 chunk 退化为关键词侧命中
        $results = $this->service($embedder)->search('优惠券');

        $this->assertCount(1, $results);
    }
}
