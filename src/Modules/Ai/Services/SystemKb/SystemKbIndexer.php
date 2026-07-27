<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Ai\Models\SystemKbChunk;
use MultiTenantSaas\Modules\Ai\Models\SystemKbDocument;

/**
 * 系统知识库索引器
 *
 * 将 SystemKbRegistry 发现的文档清单同步进 system_kb_documents /
 * system_kb_chunks 两表：
 * - checksum 增量：未变化的文档跳过（发版后 secretary:kb:sync 秒级完成）；
 * - 按 markdown 标题分块（## 级），每块尝试 embedding（fail-open 存 null）；
 * - 磁盘上已消失的文档从库中清除。
 */
class SystemKbIndexer
{
    /**
     * 单块最大字符数（超长块按段落二次切分）
     */
    private const MAX_CHUNK_CHARS = 1500;

    public function __construct(
        private readonly SystemKbRegistry $registry,
        private readonly SystemKbEmbedder $embedder,
    ) {}

    /**
     * 全量同步（内部 checksum 增量）
     *
     * @return array{added: int, updated: int, removed: int, unchanged: int}
     */
    public function sync(): array
    {
        $discovered = $this->registry->discover();
        $existing = SystemKbDocument::query()->get()->keyBy('path');

        $stats = ['added' => 0, 'updated' => 0, 'removed' => 0, 'unchanged' => 0];
        $seenPaths = [];

        foreach ($discovered as $entry) {
            $seenPaths[] = $entry['path'];
            $document = $existing->get($entry['path']);

            if ($document !== null && $document->checksum === $entry['checksum']) {
                $stats['unchanged']++;

                continue;
            }

            $content = @file_get_contents($entry['absolute_path']);

            if ($content === false) {
                continue;
            }

            DB::transaction(function () use ($entry, $content, $document, &$stats) {
                if ($document === null) {
                    $document = SystemKbDocument::create($this->documentAttributes($entry));
                    $stats['added']++;
                } else {
                    $document->update($this->documentAttributes($entry));
                    $document->chunks()->delete();
                    $stats['updated']++;
                }

                foreach ($this->chunk($content) as $position => $chunk) {
                    SystemKbChunk::create([
                        'document_id' => $document->document_id,
                        'position' => $position,
                        'heading' => $chunk['heading'],
                        'content' => $chunk['content'],
                        'embedding' => $this->embedder->embed($chunk['heading']."\n".$chunk['content']),
                    ]);
                }
            });
        }

        // 清除磁盘上已消失的文档
        $removed = SystemKbDocument::query()->whereNotIn('path', $seenPaths)->get();

        foreach ($removed as $document) {
            $document->chunks()->delete();
            $document->delete();
            $stats['removed']++;
        }

        return $stats;
    }

    /**
     * @param  array<string, string>  $entry
     * @return array<string, string>
     */
    private function documentAttributes(array $entry): array
    {
        return [
            'source' => $entry['source'],
            'module' => $entry['module'],
            'path' => $entry['path'],
            'title' => $entry['title'],
            'audience' => $entry['audience'],
            'locale' => $entry['locale'],
            'version' => $entry['version'],
            'checksum' => $entry['checksum'],
        ];
    }

    /**
     * 按 markdown 标题分块（##/### 级），超长块按段落二次切分
     *
     * @return list<array{heading: string, content: string}>
     */
    private function chunk(string $content): array
    {
        // 去掉 frontmatter
        $body = preg_replace('/\A---\s*\n.*?\n---\s*\n/s', '', $content);

        // 按 ## 或 ### 标题切分，保留标题行
        $parts = preg_split('/^(?=#{2,3}\s)/m', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $heading = '';

            if (preg_match('/^#{2,3}\s+(.+)$/m', $part, $m)) {
                $heading = trim($m[1]);
            }

            // 超长块按空行二次切分
            if (mb_strlen($part) <= self::MAX_CHUNK_CHARS) {
                $chunks[] = ['heading' => $heading, 'content' => $part];

                continue;
            }

            $buffer = '';

            foreach (preg_split('/\n{2,}/', $part) ?: [] as $paragraph) {
                if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($paragraph) > self::MAX_CHUNK_CHARS) {
                    $chunks[] = ['heading' => $heading, 'content' => trim($buffer)];
                    $buffer = '';
                }

                $buffer .= $paragraph."\n\n";
            }

            if (trim($buffer) !== '') {
                $chunks[] = ['heading' => $heading, 'content' => trim($buffer)];
            }
        }

        // 无二级标题的短文档整体成块
        if ($chunks === [] && trim($body) !== '') {
            $chunks[] = ['heading' => '', 'content' => trim($body)];
        }

        return $chunks;
    }
}
