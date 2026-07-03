<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\AiRequest;
use MultiTenantSaas\Models\FileUpload;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\Ai\Providers\DalleImageProvider;
use MultiTenantSaas\Services\AiImageService;
use RuntimeException;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\BillingModule;

/**
 * AiImageService 测试套件
 *
 * 覆盖：文生图（DALL-E / Stable Diffusion）、图生图、图片编辑（Inpainting）、
 * 风格迁移、结果存储（FileService）、请求日志（ai_requests）、
 * 参数校验、提供商不支持操作、上游错误落库。
 *
 * 通过 Http::fake 模拟提供商 HTTP 请求，通过 Storage::fake 模拟文件存储。
 */
class AiImageServiceTest extends TestCase
{
    protected array $uses = [AiModule::class, BillingModule::class, PluginModule::class];

    protected ?AiImageService $service = null;

    /**
     * 1x1 透明 PNG 的 base64，用于模拟提供商返回与输入图片构造
     */
    protected const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Image Tenant', 'slug' => 'image-tenant', 'status' => 'active']);

        Storage::fake('local');

        $this->configureAiImageDefaults();

        TenantContext::setTenantId('1001');

        $this->service = $this->app->make(AiImageService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 设置图片 AI 默认配置与提供商密钥
     */
    protected function configureAiImageDefaults(): void
    {
        config(['ai.providers.openai.api_key' => 'test-openai-key']);
        config(['ai.providers.openai.base_url' => 'https://api.openai.com/v1']);
        config(['ai.providers.stability.api_key' => 'test-stability-key']);
        config(['ai.providers.stability.base_url' => 'https://api.stability.ai/v2beta']);
        config(['ai.image.default_provider' => 'dalle']);
        config(['ai.image.default_model' => 'dall-e-3']);
        config(['ai.image.default_size' => '1024x1024']);
        config(['ai.image.default_n' => 1]);
        config(['ai.image.storage_disk' => 'local']);
        config(['ai.image.storage_is_public' => false]);
        config(['ai.log.enable' => true]);
    }

    /**
     * 将 DalleImageProvider 绑定为 Mockery mock
     */
    protected function bindDalleMock(): Mockery\MockInterface&Mockery\LegacyMockInterface
    {
        $mock = Mockery::mock(DalleImageProvider::class);
        $this->app->instance(DalleImageProvider::class, $mock);

        return $mock;
    }

    /**
     * 注册 DALL-E 与 Stable Diffusion 的 HTTP fake 响应
     */
    protected function fakeProviders(): void
    {
        Http::fake([
            'https://api.openai.com/v1/images/generations*' => Http::response([
                'created' => 1700000000,
                'data' => [
                    ['b64_json' => self::PNG_B64, 'revised_prompt' => 'revised'],
                ],
            ], 200),

            'https://api.openai.com/v1/images/edits*' => Http::response([
                'created' => 1700000000,
                'data' => [
                    ['b64_json' => self::PNG_B64],
                ],
            ], 200),

            'https://api.stability.ai/v2beta/stable-image/generate/*' => Http::response([
                'images' => [
                    ['image' => self::PNG_B64, 'finish_reason' => 'SUCCESS'],
                ],
            ], 200),

            'https://api.stability.ai/v2beta/stable-image/edit/inpaint*' => Http::response([
                'images' => [
                    ['image' => self::PNG_B64, 'finish_reason' => 'SUCCESS'],
                ],
            ], 200),
        ]);
    }

    /**
     * 构造一个输入图片的 FileUpload 记录（落盘到 fake 本地磁盘）
     */
    protected function createInputFile(string $filename = 'input.png'): FileUpload
    {
        $binary = base64_decode(self::PNG_B64, true);
        $tempPath = (string) tempnam(sys_get_temp_dir(), 'test_in_');
        file_put_contents($tempPath, (string) $binary);

        $uploaded = new UploadedFile($tempPath, $filename, 'image/png', null, true);

        $file = \MultiTenantSaas\Services\FileService::upload($uploaded, 1001, null, 'general', 'local', false);

        @unlink($tempPath);

        return $file;
    }

    // ======================================================================
    // textToImage — DALL-E
    // ======================================================================

    public function test_text_to_image_with_dalle_stores_result_and_logs_request(): void
    {
        $mock = $this->bindDalleMock();
        $mock->shouldReceive('textToImage')->once()->with('dall-e-3', 'a cute cat', Mockery::type('array'))
            ->andReturn([
                'provider' => 'dalle',
                'model' => 'dall-e-3',
                'images' => [
                    ['b64' => self::PNG_B64, 'url' => null, 'content_type' => 'image/png', 'revised_prompt' => 'revised'],
                ],
                'usage' => ['image_count' => 1, 'size' => '1024x1024'],
                'raw' => [],
            ]);

        $result = $this->service->textToImage('a cute cat', [
            'provider' => 'dalle',
            'model' => 'dall-e-3',
            'size' => '1024x1024',
            'quality' => 'hd',
            'style' => 'vivid',
        ]);

        $this->assertSame('dalle', $result['provider']);
        $this->assertSame('dall-e-3', $result['model']);
        $this->assertCount(1, $result['images']);

        $image = $result['images'][0];
        $this->assertNotEmpty($image['file_upload_id']);
        $this->assertNotEmpty($image['url']);
        $this->assertSame('image/png', $image['mime_type']);

        // 结果已落盘为 FileUpload
        $this->assertDatabaseHas('file_uploads', [
            'file_upload_id' => $image['file_upload_id'],
            'mime_type' => 'image/png',
            'category' => 'ai_generated',
        ]);

        // 请求已记录到 ai_requests 且状态为 success
        $this->assertDatabaseHas('ai_requests', [
            'request_id' => $result['request_id'],
            'provider' => 'dalle',
            'model' => 'dall-e-3',
            'status' => AiRequest::STATUS_SUCCESS,
        ]);
    }

    // ======================================================================
    // textToImage — Stable Diffusion
    // ======================================================================

    public function test_text_to_image_with_stability_stores_result(): void
    {
        $this->fakeProviders();

        $result = $this->service->textToImage('a futuristic city', [
            'provider' => 'stability',
            'model' => 'sd3',
            'negative_prompt' => 'blurry',
            'steps' => 30,
            'cfg_scale' => 7.5,
        ]);

        $this->assertSame('stability', $result['provider']);
        $this->assertSame('sd3', $result['model']);
        $this->assertCount(1, $result['images']);
        $this->assertNotEmpty($result['images'][0]['file_upload_id']);

        $this->assertDatabaseHas('ai_requests', [
            'request_id' => $result['request_id'],
            'provider' => 'stability',
            'model' => 'sd3',
            'status' => AiRequest::STATUS_SUCCESS,
        ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_starts_with($request->url(), 'https://api.stability.ai/v2beta/stable-image/generate/sd3')
                && str_contains((string) $request->body(), 'a futuristic city')
                && str_contains((string) $request->body(), 'blurry');
        });
    }

    // ======================================================================
    // imageToImage — Stable Diffusion
    // ======================================================================

    public function test_image_to_image_with_stability_uses_input_image(): void
    {
        $this->fakeProviders();

        $input = $this->createInputFile();

        $result = $this->service->imageToImage($input->file_upload_id, 'oil painting style', [
            'provider' => 'stability',
            'model' => 'sdxl',
            'image_strength' => 0.5,
        ]);

        $this->assertSame('stability', $result['provider']);
        $this->assertSame('sdxl', $result['model']);
        $this->assertCount(1, $result['images']);
        $this->assertNotEmpty($result['images'][0]['file_upload_id']);

        $this->assertDatabaseHas('ai_requests', [
            'request_id' => $result['request_id'],
            'provider' => 'stability',
            'status' => AiRequest::STATUS_SUCCESS,
        ]);

        // 应以 multipart 上传初始图
        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_starts_with($request->url(), 'https://api.stability.ai/v2beta/stable-image/generate/sdxl')
                && str_contains((string) $request->body(), 'oil painting style')
                && $request->hasFile('image');
        });
    }

    // ======================================================================
    // editImage — DALL-E 2
    // ======================================================================

    public function test_edit_image_with_dalle_uploads_image_and_mask(): void
    {
        $mock = $this->bindDalleMock();
        $mock->shouldReceive('editImage')
            ->andThrow(new RuntimeException(trans('ai.image_operation_not_supported', [
                'provider' => 'dalle',
                'operation' => 'edit_image',
            ])));

        $image = $this->createInputFile('photo.png');
        $mask = $this->createInputFile('mask.png');

        $this->expectException(RuntimeException::class);

        $this->service->editImage($image->file_upload_id, $mask->file_upload_id, 'remove background', [
            'provider' => 'dalle',
            'model' => 'dall-e-2',
        ]);
    }

    // ======================================================================
    // editImage — Stable Diffusion Inpaint
    // ======================================================================

    public function test_edit_image_with_stability_inpaint(): void
    {
        $this->fakeProviders();

        $image = $this->createInputFile();
        $mask = $this->createInputFile('mask.png');

        $result = $this->service->editImage($image->file_upload_id, $mask->file_upload_id, 'replace sky', [
            'provider' => 'stability',
            'model' => 'sdxl',
        ]);

        $this->assertSame('stability', $result['provider']);
        $this->assertCount(1, $result['images']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_starts_with($request->url(), 'https://api.stability.ai/v2beta/stable-image/edit/inpaint')
                && $request->hasFile('image')
                && $request->hasFile('mask');
        });
    }

    // ======================================================================
    // styleTransfer — Stable Diffusion
    // ======================================================================

    public function test_style_transfer_with_stability(): void
    {
        $this->fakeProviders();

        $input = $this->createInputFile();

        $result = $this->service->styleTransfer($input->file_upload_id, 'van gogh starry night', [
            'provider' => 'stability',
            'model' => 'sdxl',
        ]);

        $this->assertSame('stability', $result['provider']);
        $this->assertCount(1, $result['images']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_starts_with($request->url(), 'https://api.stability.ai/v2beta/stable-image/generate/sdxl')
                && str_contains((string) $request->body(), 'van gogh starry night')
                && $request->hasFile('image');
        });
    }

    // ======================================================================
    // 默认提供商路由
    // ======================================================================

    public function test_text_to_image_uses_default_provider_when_not_specified(): void
    {
        $mock = $this->bindDalleMock();
        $mock->shouldReceive('textToImage')->once()->andReturn([
            'provider' => 'dalle',
            'model' => 'dall-e-3',
            'images' => [
                ['b64' => self::PNG_B64, 'url' => null, 'content_type' => 'image/png', 'revised_prompt' => null],
            ],
            'usage' => ['image_count' => 1, 'size' => '1024x1024'],
            'raw' => [],
        ]);

        $result = $this->service->textToImage('default prompt');

        $this->assertSame('dalle', $result['provider']);
        $this->assertSame('dall-e-3', $result['model']);
    }

    public function test_text_to_image_routes_by_model_map_to_stability(): void
    {
        $this->fakeProviders();

        $result = $this->service->textToImage('sd prompt', ['model' => 'sdxl']);

        $this->assertSame('stability', $result['provider']);
        $this->assertSame('sdxl', $result['model']);
    }

    // ======================================================================
    // 参数校验
    // ======================================================================

    public function test_text_to_image_throws_when_prompt_empty(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->textToImage('   ');
    }

    public function test_text_to_image_throws_when_prompt_too_long(): void
    {
        config(['ai.image.max_prompt_length' => 10]);

        $this->expectException(RuntimeException::class);

        $this->service->textToImage(str_repeat('a', 11));
    }

    public function test_image_to_image_throws_when_input_not_found(): void
    {
        $this->fakeProviders();

        $this->expectException(RuntimeException::class);

        $this->service->imageToImage(999999, 'prompt', ['provider' => 'stability', 'model' => 'sdxl']);
    }

    public function test_dalle_does_not_support_image_to_image(): void
    {
        $mock = $this->bindDalleMock();
        $mock->shouldReceive('imageToImage')
            ->andThrow(new RuntimeException(trans('ai.image_operation_not_supported', [
                'provider' => 'dalle',
                'operation' => 'image_to_image',
            ])));

        $input = $this->createInputFile();

        $this->expectException(RuntimeException::class);

        $this->service->imageToImage($input->file_upload_id, 'prompt', [
            'provider' => 'dalle',
            'model' => 'dall-e-3',
        ]);
    }

    public function test_dalle_does_not_support_style_transfer(): void
    {
        $mock = $this->bindDalleMock();
        $mock->shouldReceive('styleTransfer')
            ->andThrow(new RuntimeException(trans('ai.image_operation_not_supported', [
                'provider' => 'dalle',
                'operation' => 'style_transfer',
            ])));

        $input = $this->createInputFile();

        $this->expectException(RuntimeException::class);

        $this->service->styleTransfer($input->file_upload_id, 'style', [
            'provider' => 'dalle',
            'model' => 'dall-e-3',
        ]);
    }

    public function test_text_to_image_throws_on_unsupported_size(): void
    {
        $mock = $this->bindDalleMock();
        $mock->shouldReceive('textToImage')
            ->andThrow(new RuntimeException('Invalid size'));

        $this->expectException(RuntimeException::class);

        $this->service->textToImage('prompt', [
            'provider' => 'dalle',
            'model' => 'dall-e-3',
            'size' => '512x512',
        ]);
    }

    public function test_text_to_image_throws_on_unsupported_quality(): void
    {
        $mock = $this->bindDalleMock();
        $mock->shouldReceive('textToImage')
            ->andThrow(new RuntimeException('Invalid quality'));

        $this->expectException(RuntimeException::class);

        $this->service->textToImage('prompt', [
            'provider' => 'dalle',
            'model' => 'dall-e-3',
            'quality' => 'ultra',
        ]);
    }

    // ======================================================================
    // 上游错误 → 落库失败
    // ======================================================================

    public function test_text_to_image_logs_failure_when_provider_returns_error(): void
    {
        $mock = $this->bindDalleMock();
        $mock->shouldReceive('textToImage')
            ->andThrow(new RuntimeException('Provider error: boom'));

        $caught = null;
        try {
            $this->service->textToImage('prompt', ['provider' => 'dalle', 'model' => 'dall-e-3']);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);

        $this->assertDatabaseHas('ai_requests', [
            'provider' => 'dalle',
            'model' => 'dall-e-3',
            'status' => AiRequest::STATUS_FAILED,
        ]);
    }

    public function test_text_to_image_with_unsupported_provider_throws(): void
    {
        $this->fakeProviders();

        $this->expectException(RuntimeException::class);

        $this->service->textToImage('prompt', ['provider' => 'midjourney']);
    }

    public function test_stability_throws_when_model_not_supported(): void
    {
        $this->fakeProviders();

        $this->expectException(RuntimeException::class);

        $this->service->textToImage('prompt', ['provider' => 'stability', 'model' => 'unknown-model']);
    }
}
