<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

use MultiTenantSaas\Modules\Ai\Models\SystemKbChunk;

/**
 * 系统知识库检索服务（混合检索）
 *
 * - 向量：查询 embedding 与 chunk embedding 余弦相似度（embedding 缺失时跳过）；
 * - 关键词：查询词元（空格分词 + 中文 bigram）在分块中的命中频次；
 * - 混合打分：score = 0.7 * vector + 0.3 * keyword（任一侧缺失自动退化为另一侧）。
 *
 * chunk 规模在数千级，内存计算足够，不引入向量库。
 * audience=internal 的文档默认不进入检索结果（仅平台内部诊断可见）。
 */
class SystemKbSearchService
{
    private const VECTOR_WEIGHT = 0.7;

    private const KEYWORD_WEIGHT = 0.3;

    public function __construct(
        private readonly SystemKbEmbedder $embedder,
    ) {}

    /**
     * 混合检索
     *
     * @param  string  $query  自然语言查询
     * @param  int  $topK  返回条数
     * @param  bool  $includeInternal  是否包含 internal 受众文档
     * @return list<array{
     *     title: string,
     *     module: string,
     *     path: string,
     *     heading: string,
     *     content: string,
     *     score: float,
     * }>
     */
    public function search(string $query, int $topK = 5, bool $includeInternal = false): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $chunks = SystemKbChunk::query()
            ->with('document')
            ->whereHas('document', function ($q) use ($includeInternal) {
                if (! $includeInternal) {
                    $q->where('audience', '!=', 'internal');
                }
            })
            ->get();

        if ($chunks->isEmpty()) {
            return [];
        }

        // 查询向量（失败 fail-open 为 null，退化为纯关键词）
        $queryEmbedding = $this->embedder->embed($query);
        $tokens = $this->tokenize($query);

        $scored = [];

        foreach ($chunks as $chunk) {
            $vectorScore = ($queryEmbedding !== null && is_array($chunk->embedding))
                ? $this->cosine($queryEmbedding, $chunk->embedding)
                : null;

            $keywordScore = $this->keywordScore($tokens, $chunk->heading."\n".$chunk->content);

            // 两侧都无信号的分块跳过
            if (($vectorScore === null || $vectorScore <= 0) && $keywordScore <= 0) {
                continue;
            }

            $score = $vectorScore === null
                ? $keywordScore
                : self::VECTOR_WEIGHT * $vectorScore + self::KEYWORD_WEIGHT * $keywordScore;

            $scored[] = ['chunk' => $chunk, 'score' => $score];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(function ($item) {
            $chunk = $item['chunk'];

            return [
                'title' => $chunk->document->title ?? '',
                'module' => $chunk->document->module ?? '',
                'path' => $chunk->document->path ?? '',
                'heading' => $chunk->heading,
                'content' => $chunk->content,
                'score' => round($item['score'], 4),
            ];
        }, array_slice($scored, 0, max(1, $topK)));
    }

    /**
     * 查询分词：空格词元 + 中文 bigram（中文查询无空格也能匹配）
     *
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $tokens = [];

        foreach (preg_split('/[\s,，。？?!！]+/u', $query) ?: [] as $word) {
            $word = trim($word);

            if ($word === '') {
                continue;
            }

            if (preg_match('/^[\x{4e00}-\x{9fff}]+$/u', $word) && mb_strlen($word) > 2) {
                // 中文长词切 bigram
                for ($i = 0; $i < mb_strlen($word) - 1; $i++) {
                    $tokens[] = mb_substr($word, $i, 2);
                }
            } else {
                $tokens[] = $word;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * 关键词得分：命中词元占比（0~1）
     *
     * @param  list<string>  $tokens
     */
    private function keywordScore(array $tokens, string $content): float
    {
        if ($tokens === []) {
            return 0.0;
        }

        $hits = 0;

        foreach ($tokens as $token) {
            if (mb_stripos($content, $token) !== false) {
                $hits++;
            }
        }

        return $hits / count($tokens);
    }

    /**
     * 余弦相似度（维度不一致返回 0）
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $normA += $value * $value;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
