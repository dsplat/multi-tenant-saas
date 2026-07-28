<?php

namespace MultiTenantSaas\Modules\Ai\Services\SystemKb;

/**
 * 系统知识库检索服务（纯文件型，零 DB 零 embedding）
 *
 * 工作流：Registry 发现文档 → 读取文件 → 按 ## 标题内存分块 → 关键词打分。
 * 设计哲学：知识库是随版本发布的文件资产，检索不依赖任何外部服务，
 * 项目部署初期只需配一个 chat 小模型即可使用系统小助手。
 *
 * 分块规模在数百级（数十篇文档 × 数块），内存计算毫秒级完成。
 * audience=internal 的文档默认不进入检索结果（仅平台内部诊断可见）。
 */
class SystemKbSearchService
{
    /**
     * 单块最大字符数（超长块按段落二次切分）
     */
    private const MAX_CHUNK_CHARS = 1500;

    public function __construct(
        private readonly SystemKbRegistry $registry,
    ) {}

    /**
     * 关键词检索
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

        $tokens = $this->tokenize($query);

        if ($tokens === []) {
            return [];
        }

        $scored = [];

        foreach ($this->registry->discover() as $entry) {
            if (! $includeInternal && $entry['audience'] === 'internal') {
                continue;
            }

            $content = @file_get_contents($entry['absolute_path']);

            if ($content === false) {
                continue;
            }

            foreach ($this->chunk($content) as $chunk) {
                $score = $this->keywordScore($tokens, $chunk['heading'] . "\n" . $chunk['content']);

                if ($score <= 0) {
                    continue;
                }

                $scored[] = [
                    'title' => $entry['title'],
                    'module' => $entry['module'],
                    'path' => $entry['path'],
                    'heading' => $chunk['heading'],
                    'content' => $chunk['content'],
                    'score' => round($score, 4),
                ];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $topK));
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

                $buffer .= $paragraph . "\n\n";
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
        $hits = 0;

        foreach ($tokens as $token) {
            if (mb_stripos($content, $token) !== false) {
                $hits++;
            }
        }

        return $hits / count($tokens);
    }
}
