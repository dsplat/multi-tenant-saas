<?php

namespace MultiTenantSaas\Support\Messaging;

/**
 * Markdown 按频道能力降级适配器（共享层，纯静态无依赖）
 *
 * AI 输出统一为标准 Markdown，各 IM 频道渲染能力不同：
 * - toPlain            剥离全部语法（企微 text 兜底、通用纯文本出口）
 * - toTelegramHtml     Telegram parse_mode=HTML 安全子集（b/i/code/pre/a）
 * - toWechatWorkMarkdown 企微 markdown 子集（标题/加粗/链接/行内代码/引用），
 *                      不支持的语法（斜体、代码块围栏）降级为纯文本表达
 */
class MarkdownAdapter
{
    /**
     * 剥离 Markdown 语法为纯文本
     */
    public static function toPlain(string $markdown): string
    {
        $text = $markdown;

        // 代码块围栏：去掉 ``` 行，保留内容
        $text = preg_replace('/^```[^\n]*$/m', '', $text) ?? $text;

        // 图片 ![alt](url) → alt (url)；链接 [text](url) → text (url)
        $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '$1 ($2)', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $text) ?? $text;

        // 加粗/斜体/行内代码/删除线
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/', '$1', $text) ?? $text;
        $text = preg_replace('/(?<![*\w])\*([^*\n]+)\*(?![*\w])/', '$1', $text) ?? $text;
        $text = preg_replace('/(?<![_\w])_([^_\n]+)_(?![_\w])/', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`\n]+)`/', '$1', $text) ?? $text;
        $text = preg_replace('/~~([^~]+)~~/', '$1', $text) ?? $text;

        // 标题井号、引用符、水平线
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/^>\s?/m', '', $text) ?? $text;
        $text = preg_replace('/^[ \t]*([-*_])\1{2,}[ \t]*$/m', '', $text) ?? $text;

        // 无序列表符统一为「- 」
        $text = preg_replace('/^([ \t]*)[*+]\s+/m', '$1- ', $text) ?? $text;

        // 收敛 3+ 连续空行
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * 转 Telegram HTML 安全子集（先转义再转换，代码内容原样保护）
     */
    public static function toTelegramHtml(string $markdown): string
    {
        [$text, $blocks] = self::extractCode($markdown);

        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // 图片/链接（URL 已被转义，& → &amp; 在 href 中合法）
        $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text) ?? $text;

        // 标题 → 加粗行；引用符去掉
        $text = preg_replace('/^#{1,6}\s+(.+)$/m', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/^&gt;\s?/m', '', $text) ?? $text;

        // 无序列表符 → 圆点（避免 * 与斜体正则冲突）
        $text = preg_replace('/^([ \t]*)[-*+]\s+/m', '$1• ', $text) ?? $text;

        // 加粗/斜体/删除线
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/__([^_]+)__/', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/(?<![*\w])\*([^*\n]+)\*(?![*\w])/', '<i>$1</i>', $text) ?? $text;
        $text = preg_replace('/(?<![_\w])_([^_\n]+)_(?![_\w])/', '<i>$1</i>', $text) ?? $text;
        $text = preg_replace('/~~([^~]+)~~/', '<s>$1</s>', $text) ?? $text;

        // 水平线移除
        $text = preg_replace('/^[ \t]*([-*_])\1{2,}[ \t]*$/m', '', $text) ?? $text;

        // 还原代码占位（内容转义后包 pre/code）
        foreach ($blocks as $placeholder => $block) {
            $escaped = htmlspecialchars($block['content'], ENT_QUOTES, 'UTF-8');
            $html = $block['type'] === 'fence' ? "<pre>{$escaped}</pre>" : "<code>{$escaped}</code>";
            $text = str_replace($placeholder, $html, $text);
        }

        return trim($text);
    }

    /**
     * 转企业微信 markdown 子集
     *
     * 企微支持：标题、加粗 **、链接 [t](u)、行内代码 `x`、引用 >。
     * 不支持：斜体、删除线、代码块围栏（降级为引用块）、图片（降级为链接）。
     * 注意：企微 markdown 消息仅企业微信客户端渲染。
     */
    public static function toWechatWorkMarkdown(string $markdown): string
    {
        [$text, $blocks] = self::extractCode($markdown);

        // 图片降级为链接
        $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '[$1]($2)', $text) ?? $text;

        // 斜体/删除线降级为纯文本（保留加粗）
        $text = preg_replace('/(?<![*\w])\*([^*\n]+)\*(?![*\w])/', '$1', $text) ?? $text;
        $text = preg_replace('/(?<![_\w])_([^_\n]+)_(?![_\w])/', '$1', $text) ?? $text;
        $text = preg_replace('/~~([^~]+)~~/', '$1', $text) ?? $text;

        // __bold__ 统一为 **bold**
        $text = preg_replace('/__([^_]+)__/', '**$1**', $text) ?? $text;

        // 还原代码占位：行内 code 保留反引号，围栏降级为引用块
        foreach ($blocks as $placeholder => $block) {
            if ($block['type'] === 'inline') {
                $text = str_replace($placeholder, "`{$block['content']}`", $text);
            } else {
                $quoted = implode("\n", array_map(
                    fn (string $line) => '> ' . $line,
                    explode("\n", trim($block['content']))
                ));
                $text = str_replace($placeholder, $quoted, $text);
            }
        }

        return trim($text);
    }

    /**
     * 抽取代码块/行内代码为占位符，保护内容不被后续正则处理
     *
     * @return array{0: string, 1: array<string, array{type: string, content: string}>}
     */
    private static function extractCode(string $text): array
    {
        $blocks = [];
        $index = 0;

        // 围栏代码块 ```lang\n...\n```
        $text = preg_replace_callback('/```[^\n]*\n(.*?)```/s', function (array $m) use (&$blocks, &$index) {
            $placeholder = "\x00CODE{$index}\x00";
            $blocks[$placeholder] = ['type' => 'fence', 'content' => rtrim($m[1], "\n")];
            $index++;

            return $placeholder;
        }, $text) ?? $text;

        // 行内代码 `x`
        $text = preg_replace_callback('/`([^`\n]+)`/', function (array $m) use (&$blocks, &$index) {
            $placeholder = "\x00CODE{$index}\x00";
            $blocks[$placeholder] = ['type' => 'inline', 'content' => $m[1]];
            $index++;

            return $placeholder;
        }, $text) ?? $text;

        return [$text, $blocks];
    }
}
