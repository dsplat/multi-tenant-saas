<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Ai\Services\Ai\Providers\BailianImageProvider;
use MultiTenantSaas\Modules\Ai\Services\AiImageService;
use MultiTenantSaas\Modules\Ai\Services\Tool\GeneratePosterTool;
use RuntimeException;

/**
 * generate_poster 工具 + BailianImageProvider 测试
 *
 * 覆盖：参数校验、成功出图结果映射、出图失败降级为文案产出；
 * bailian provider 的 HTTP 调用、配置缺失与上游错误处理。
 */
class GeneratePosterToolTest extends TestCase
{
    private GeneratePosterTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new GeneratePosterTool;
    }

    public function test_empty_prompt_returns_error(): void
    {
        $result = ($this->tool)(['prompt' => '  '], 1001);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('prompt', $result['message']);
    }

    public function test_success_maps_images_and_defaults_to_qwen_image(): void
    {
        $this->mock(AiImageService::class, function ($mock) {
            $mock->shouldReceive('textToImage')
                ->once()
                ->withArgs(fn (string $prompt, array $options) => $prompt === '周年庆海报'
                    && $options['model'] === 'qwen-image-2.0')
                ->andReturn([
                    'request_id' => 1,
                    'provider' => 'bailian',
                    'model' => 'qwen-image-2.0',
                    'images' => [
                        ['file_upload_id' => 9001, 'url' => 'https://cdn.test/p.png', 'size' => 1024, 'mime_type' => 'image/png', 'width' => null, 'height' => null],
                    ],
                    'raw' => [],
                ]);
        });

        $result = ($this->tool)(['prompt' => '周年庆海报'], 1001);

        $this->assertTrue($result['success']);
        $this->assertEquals('qwen-image-2.0', $result['model']);
        $this->assertCount(1, $result['images']);
        $this->assertEquals('https://cdn.test/p.png', $result['images'][0]['url']);
    }

    public function test_failure_degrades_to_poster_brief(): void
    {
        $this->mock(AiImageService::class, function ($mock) {
            $mock->shouldReceive('textToImage')
                ->once()
                ->andThrow(new RuntimeException('上游超时'));
        });

        $result = ($this->tool)(['prompt' => '新品发布海报'], 1001);

        $this->assertTrue($result['error']);
        $this->assertTrue($result['degraded']);
        $this->assertStringContainsString('上游超时', $result['message']);
        $this->assertEquals('新品发布海报', $result['poster_brief']);
    }

    // ========== BailianImageProvider ==========

    public function test_provider_calls_images_generations_endpoint(): void
    {
        config([
            'ai.providers.bailian.base_url' => 'https://token-plan.test/v1',
            'ai.providers.bailian.api_key' => 'sk-test',
        ]);

        Http::fake([
            'token-plan.test/v1/images/generations' => Http::response([
                'data' => [
                    ['url' => 'https://oss.test/img.png'],
                ],
            ]),
        ]);

        $response = (new BailianImageProvider)->textToImage('qwen-image-2.0', '海报', ['size' => '1024x1792']);

        $this->assertEquals('bailian', $response['provider']);
        $this->assertEquals('https://oss.test/img.png', $response['images'][0]['url']);
        $this->assertEquals(1, $response['usage']['image_count']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://token-plan.test/v1/images/generations'
                && $request['model'] === 'qwen-image-2.0'
                && $request['size'] === '1024x1792'
                && $request->hasHeader('Authorization', 'Bearer sk-test');
        });
    }

    public function test_provider_throws_when_not_configured(): void
    {
        config([
            'ai.providers.bailian.base_url' => '',
            'ai.providers.bailian.url' => '',
            'ai.providers.bailian.api_key' => '',
            'ai.providers.bailian.key' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/AI_BAILIAN/');

        (new BailianImageProvider)->textToImage('qwen-image-2.0', '海报');
    }

    public function test_provider_throws_on_upstream_error(): void
    {
        config([
            'ai.providers.bailian.base_url' => 'https://token-plan.test/v1',
            'ai.providers.bailian.api_key' => 'sk-test',
        ]);

        Http::fake([
            'token-plan.test/v1/images/generations' => Http::response([
                'error' => ['message' => 'model not in plan'],
            ], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/model not in plan/');

        (new BailianImageProvider)->textToImage('qwen-image-9.9', '海报');
    }
}
