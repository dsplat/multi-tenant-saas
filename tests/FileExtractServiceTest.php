<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\UploadedFile;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Modules\Ai\Services\Ai\AiResponse;
use MultiTenantSaas\Modules\Ai\Services\Ai\Drivers\AiDriverContract;
use MultiTenantSaas\Modules\Ai\Services\Ai\StreamChunk;
use MultiTenantSaas\Modules\Ai\Services\Assistant\DocumentTextExtractor;
use MultiTenantSaas\Modules\Ai\Services\Assistant\FileExtractService;
use MultiTenantSaas\Tests\Schema\PluginModule;

/**
 * 小助手附件内容提取服务测试（不落库链路）
 *
 * 覆盖：md 直读、旧版 doc 拒绝、不支持扩展名拒绝、
 * 长文截断、空内容拒绝、图片未配置视觉模型拒绝、图片视觉模型提取。
 */
class FileExtractServiceTest extends TestCase
{
    protected array $uses = [PluginModule::class];

    /**
     * 用给定 AI 服务构造被测服务
     */
    private function makeService(?AiTextServiceContract $ai = null): FileExtractService
    {
        return new FileExtractService(new DocumentTextExtractor, $ai ?? new class implements AiTextServiceContract
        {
            public function chat(array $messages, array $options = []): AiResponse
            {
                return AiResponse::fromArray(['content' => '']);
            }

            public function complete(string $prompt, array $options = []): AiResponse
            {
                return AiResponse::fromArray(['content' => '']);
            }

            public function streamChat(array $messages, array $options = []): \Generator
            {
                yield new StreamChunk(text: '');
            }

            public function driver(AiDriverContract|string|null $name = null): AiDriverContract
            {
                throw new \RuntimeException('not used');
            }
        });
    }

    /**
     * 构造测试模式的 UploadedFile（内容写入临时文件）
     */
    private function makeUpload(string $filename, string $content, ?string $mime = null): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fe_test_');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, $filename, $mime, null, true);
    }

    public function test_extracts_markdown_content(): void
    {
        $service = $this->makeService();
        $file = $this->makeUpload('plan.md', "# 策划\n双十一活动");

        $result = $service->extract($file);

        $this->assertSame('plan.md', $result['filename']);
        $this->assertSame('text', $result['format']);
        $this->assertSame("# 策划\n双十一活动", $result['content']);
        $this->assertFalse($result['truncated']);
    }

    public function test_legacy_doc_is_rejected_with_guidance(): void
    {
        $service = $this->makeService();
        $file = $this->makeUpload('old.doc', 'whatever');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('另存为 .docx');

        $service->extract($file);
    }

    public function test_unsupported_extension_is_rejected(): void
    {
        $service = $this->makeService();
        $file = $this->makeUpload('archive.zip', 'PK...');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不支持的文件类型');

        $service->extract($file);
    }

    public function test_long_content_is_truncated(): void
    {
        $service = $this->makeService();
        $file = $this->makeUpload('big.md', str_repeat('长', FileExtractService::MAX_CHARS + 500));

        $result = $service->extract($file);

        $this->assertTrue($result['truncated']);
        $this->assertSame(FileExtractService::MAX_CHARS, mb_strlen($result['content']));
        $this->assertSame(FileExtractService::MAX_CHARS + 500, $result['total_length']);
    }

    public function test_empty_content_is_rejected(): void
    {
        $service = $this->makeService();
        $file = $this->makeUpload('empty.md', '   ');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('未能从文件中提取到任何内容');

        $service->extract($file);
    }

    public function test_image_without_vision_config_is_rejected(): void
    {
        config()->set('ai.assistant.image_extract.provider', '');
        config()->set('ai.assistant.image_extract.model', '');

        $service = $this->makeService();
        // 1x1 png
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $file = $this->makeUpload('shot.png', $png, 'image/png');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('图片内容识别未启用');

        $service->extract($file);
    }

    public function test_image_extracts_via_vision_model(): void
    {
        config()->set('ai.assistant.image_extract.provider', 'mock');
        config()->set('ai.assistant.image_extract.model', 'mock-vl');

        $ai = new class implements AiTextServiceContract
        {
            public array $captured = [];

            public function chat(array $messages, array $options = []): AiResponse
            {
                $this->captured = ['messages' => $messages, 'options' => $options];

                return AiResponse::fromArray(['content' => '图中文字：活动排期表']);
            }

            public function complete(string $prompt, array $options = []): AiResponse
            {
                return AiResponse::fromArray(['content' => '']);
            }

            public function streamChat(array $messages, array $options = []): \Generator
            {
                yield new StreamChunk(text: '');
            }

            public function driver(AiDriverContract|string|null $name = null): AiDriverContract
            {
                throw new \RuntimeException('not used');
            }
        };

        $service = $this->makeService($ai);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $file = $this->makeUpload('shot.png', $png, 'image/png');

        $result = $service->extract($file);

        $this->assertSame('image', $result['format']);
        $this->assertSame('图中文字：活动排期表', $result['content']);
        // 视觉调用携带多模态 content 与配置的 provider/model
        $content = $ai->captured['messages'][0]['content'];
        $this->assertIsArray($content);
        $this->assertSame('image_url', $content[1]['type']);
        $this->assertSame('mock', $ai->captured['options']['provider']);
        $this->assertSame('mock-vl', $ai->captured['options']['model']);
    }
}
