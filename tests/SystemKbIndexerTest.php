<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Models\SystemKbChunk;
use MultiTenantSaas\Modules\Ai\Models\SystemKbDocument;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbEmbedder;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbIndexer;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\SystemKbRegistry;
use MultiTenantSaas\Tests\Schema\SystemKbModule;

/**
 * SystemKbIndexer 单元测试
 *
 * 覆盖：新增/checksum 增量跳过/更新重建分块/删除清理、标题分块、embedding fail-open 存 null
 */
class SystemKbIndexerTest extends TestCase
{
    protected array $uses = [SystemKbModule::class];

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir().'/system_kb_indexer_'.uniqid();
        mkdir($this->basePath.'/docs/kb', 0777, true);

        // embedding_model 为空 → SystemKbEmbedder 直接返回 null（fail-open，无网络请求）
        config(['ai.secretary.embedding_model' => '']);
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

    private function indexer(): SystemKbIndexer
    {
        return new SystemKbIndexer(new SystemKbRegistry($this->basePath), new SystemKbEmbedder);
    }

    private function writeDoc(string $name, string $content): void
    {
        file_put_contents($this->basePath.'/docs/kb/'.$name, $content);
    }

    // ---------- 同步生命周期 ----------

    public function test_sync_adds_new_documents(): void
    {
        $this->writeDoc('guide.md', "## 第一节\n\n内容一\n\n## 第二节\n\n内容二");

        $stats = $this->indexer()->sync();

        $this->assertEquals(1, $stats['added']);
        $this->assertEquals(0, $stats['updated']);
        $this->assertEquals(1, SystemKbDocument::count());
        $this->assertEquals(2, SystemKbChunk::count());
    }

    public function test_sync_skips_unchanged_documents(): void
    {
        $this->writeDoc('guide.md', "# 指南\n\n## 节\n\n内容");

        $this->indexer()->sync();
        $stats = $this->indexer()->sync();

        $this->assertEquals(0, $stats['added']);
        $this->assertEquals(0, $stats['updated']);
        $this->assertEquals(1, $stats['unchanged']);
    }

    public function test_sync_updates_changed_document_and_rebuilds_chunks(): void
    {
        $this->writeDoc('guide.md', "## 甲\n\n旧内容\n\n## 乙\n\n旧内容");
        $this->indexer()->sync();

        $this->writeDoc('guide.md', "## 丙\n\n新内容");
        $stats = $this->indexer()->sync();

        $this->assertEquals(1, $stats['updated']);
        $this->assertEquals(1, SystemKbDocument::count());
        $this->assertEquals(1, SystemKbChunk::count());
        $this->assertEquals('丙', SystemKbChunk::first()->heading);
    }

    public function test_sync_removes_deleted_documents(): void
    {
        $this->writeDoc('guide.md', "# 指南\n\n## 节\n\n内容");
        $this->indexer()->sync();

        unlink($this->basePath.'/docs/kb/guide.md');
        $stats = $this->indexer()->sync();

        $this->assertEquals(1, $stats['removed']);
        $this->assertEquals(0, SystemKbDocument::count());
        $this->assertEquals(0, SystemKbChunk::count());
    }

    public function test_document_attributes_persisted(): void
    {
        $this->writeDoc('manual.md', "---\ntitle: 手册\naudience: internal\nlocale: en\nversion: 3.0\n---\n\n# 手册\n\n正文");

        $this->indexer()->sync();

        $document = SystemKbDocument::first();
        $this->assertEquals('手册', $document->title);
        $this->assertEquals('internal', $document->audience);
        $this->assertEquals('en', $document->locale);
        $this->assertEquals('3.0', $document->version);
        $this->assertEquals('docs/kb/manual.md', $document->path);
    }

    // ---------- 分块 ----------

    public function test_document_without_headings_becomes_single_chunk(): void
    {
        $this->writeDoc('flat.md', "没有任何二级标题的短文档，整体成一块。");

        $this->indexer()->sync();

        $this->assertEquals(1, SystemKbChunk::count());
        $this->assertEquals('', SystemKbChunk::first()->heading);
    }

    public function test_chunks_keep_position_order(): void
    {
        $this->writeDoc('multi.md', "## 一\n\n内容\n\n## 二\n\n内容\n\n## 三\n\n内容");

        $this->indexer()->sync();

        $positions = SystemKbChunk::orderBy('position')->pluck('heading')->all();
        $this->assertEquals(['一', '二', '三'], $positions);
    }

    // ---------- embedding fail-open ----------

    public function test_embedding_stored_as_null_when_embedder_unavailable(): void
    {
        $this->writeDoc('guide.md', "# 指南\n\n## 节\n\n内容");

        $this->indexer()->sync();

        $this->assertNull(SystemKbChunk::first()->embedding);
    }

    public function test_embedding_stored_when_embedder_returns_vector(): void
    {
        $this->writeDoc('guide.md', "# 指南\n\n## 节\n\n内容");

        $embedder = new class extends SystemKbEmbedder
        {
            public function embed(string $text): ?array
            {
                return [0.1, 0.2, 0.3];
            }
        };

        (new SystemKbIndexer(new SystemKbRegistry($this->basePath), $embedder))->sync();

        $this->assertEquals([0.1, 0.2, 0.3], SystemKbChunk::first()->embedding);
    }
}
