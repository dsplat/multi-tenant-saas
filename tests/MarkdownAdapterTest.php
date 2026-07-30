<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Support\Messaging\MarkdownAdapter;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * MarkdownAdapter 纯单元测试（无 Laravel 依赖）
 */
class MarkdownAdapterTest extends BaseTestCase
{
    // ========== toPlain ==========

    public function test_to_plain_strips_inline_syntax(): void
    {
        $this->assertSame(
            '加粗 斜体 代码 删除',
            MarkdownAdapter::toPlain('**加粗** *斜体* `代码` ~~删除~~')
        );
    }

    public function test_to_plain_converts_link_to_text_with_url(): void
    {
        $this->assertSame(
            '官网 (https://example.com)',
            MarkdownAdapter::toPlain('[官网](https://example.com)')
        );
    }

    public function test_to_plain_strips_heading_quote_and_normalizes_list(): void
    {
        $input = "# 标题\n> 引用内容\n* 第一项\n+ 第二项\n- 第三项";

        $this->assertSame(
            "标题\n引用内容\n- 第一项\n- 第二项\n- 第三项",
            MarkdownAdapter::toPlain($input)
        );
    }

    public function test_to_plain_keeps_fenced_code_content(): void
    {
        $result = MarkdownAdapter::toPlain("```php\necho 1;\n```");

        $this->assertStringContainsString('echo 1;', $result);
        $this->assertStringNotContainsString('```', $result);
    }

    public function test_to_plain_empty_string(): void
    {
        $this->assertSame('', MarkdownAdapter::toPlain(''));
    }

    // ========== toTelegramHtml ==========

    public function test_to_telegram_html_converts_bold_and_italic(): void
    {
        $this->assertSame(
            '<b>加粗</b> 和 <i>斜体</i>',
            MarkdownAdapter::toTelegramHtml('**加粗** 和 *斜体*')
        );
    }

    public function test_to_telegram_html_escapes_special_chars(): void
    {
        $this->assertSame(
            'a &lt; b &amp; c &gt; d',
            MarkdownAdapter::toTelegramHtml('a < b & c > d')
        );
    }

    public function test_to_telegram_html_converts_link_and_heading(): void
    {
        $input = "# 今日总结\n详见 [文档](https://example.com/doc)";

        $this->assertSame(
            "<b>今日总结</b>\n详见 <a href=\"https://example.com/doc\">文档</a>",
            MarkdownAdapter::toTelegramHtml($input)
        );
    }

    public function test_to_telegram_html_protects_code_content_from_conversion(): void
    {
        // 行内代码里的 ** 与 < 不应被当作 Markdown/HTML 处理
        $this->assertSame(
            '<code>$a &lt; **b**</code>',
            MarkdownAdapter::toTelegramHtml('`$a < **b**`')
        );
    }

    public function test_to_telegram_html_fenced_code_becomes_pre(): void
    {
        $result = MarkdownAdapter::toTelegramHtml("```php\nif (\$a < 1) {}\n```");

        $this->assertSame('<pre>if ($a &lt; 1) {}</pre>', $result);
    }

    public function test_to_telegram_html_converts_list_marker_to_bullet(): void
    {
        $this->assertSame(
            "• 第一项\n• 第二项",
            MarkdownAdapter::toTelegramHtml("- 第一项\n- 第二项")
        );
    }

    public function test_to_telegram_html_empty_string(): void
    {
        $this->assertSame('', MarkdownAdapter::toTelegramHtml(''));
    }

    // ========== toWechatWorkMarkdown ==========

    public function test_to_wechat_work_keeps_supported_syntax(): void
    {
        $input = "# 标题\n**加粗** 与 [链接](https://example.com) 与 `代码`";

        $this->assertSame($input, MarkdownAdapter::toWechatWorkMarkdown($input));
    }

    public function test_to_wechat_work_degrades_unsupported_syntax(): void
    {
        // 斜体/删除线降级为纯文本，__bold__ 统一为 **bold**
        $this->assertSame(
            '斜体 删除 **加粗**',
            MarkdownAdapter::toWechatWorkMarkdown('*斜体* ~~删除~~ __加粗__')
        );
    }

    public function test_to_wechat_work_fence_becomes_quote_block(): void
    {
        $this->assertSame(
            "> line1\n> line2",
            MarkdownAdapter::toWechatWorkMarkdown("```\nline1\nline2\n```")
        );
    }

    public function test_to_wechat_work_image_degrades_to_link(): void
    {
        $this->assertSame(
            '[截图](https://example.com/a.png)',
            MarkdownAdapter::toWechatWorkMarkdown('![截图](https://example.com/a.png)')
        );
    }

    public function test_to_wechat_work_empty_string(): void
    {
        $this->assertSame('', MarkdownAdapter::toWechatWorkMarkdown(''));
    }
}
