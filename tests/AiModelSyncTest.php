<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Ai\Services\AiModelCatalogService;

/**
 * AI 模型动态清单服务测试
 *
 * 覆盖：/models 拉取成功缓存、失败回退 config 兜底清单、
 * 缓存命中不重复请求、可用性判断、ai:models:sync 命令输出。
 */
class AiModelSyncTest extends TestCase
{
    protected AiModelCatalogService $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.providers.bailian', [
            'driver' => 'openai',
            'key' => 'sk-test',
            'url' => 'https://token-plan.example.com/compatible-mode/v1',
            'base_url' => 'https://token-plan.example.com/compatible-mode/v1',
            'api_key' => 'sk-test',
            'models' => ['qwen3.6-flash', 'deepseek-v4-flash'],
        ]);

        $this->catalog = new AiModelCatalogService;
        $this->catalog->forget('bailian');
    }

    public function test_sync_fetches_models_and_caches(): void
    {
        Http::fake([
            'token-plan.example.com/compatible-mode/v1/models' => Http::response([
                'data' => [
                    ['id' => 'qwen3.7-plus'],
                    ['id' => 'qwen3.7-max'],
                    ['id' => 'deepseek-v4-pro'],
                ],
            ]),
        ]);

        $models = $this->catalog->sync('bailian');

        $this->assertSame(['qwen3.7-plus', 'qwen3.7-max', 'deepseek-v4-pro'], $models);
        $this->assertSame($models, Cache::get('ai:model_catalog:bailian'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/compatible-mode/v1/models')
                && ($request->header('Authorization')[0] ?? '') === 'Bearer sk-test';
        });
    }

    public function test_models_returns_cached_without_http(): void
    {
        Cache::put('ai:model_catalog:bailian', ['glm-5.2'], 3600);
        Http::fake();

        $this->assertSame(['glm-5.2'], $this->catalog->models('bailian'));
        Http::assertNothingSent();
    }

    public function test_models_falls_back_to_config_on_http_failure(): void
    {
        Http::fake([
            'token-plan.example.com/*' => Http::response(null, 500),
        ]);

        $this->assertSame(['qwen3.6-flash', 'deepseek-v4-flash'], $this->catalog->models('bailian'));
    }

    public function test_sync_failure_keeps_existing_cache(): void
    {
        Cache::put('ai:model_catalog:bailian', ['qwen3.7-plus'], 3600);
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->assertSame([], $this->catalog->sync('bailian'));
        $this->assertSame(['qwen3.7-plus'], Cache::get('ai:model_catalog:bailian'));
    }

    public function test_sync_skips_provider_without_credentials(): void
    {
        config()->set('ai.providers.empty_provider', ['url' => '', 'key' => '']);
        Http::fake();

        $this->assertSame([], $this->catalog->sync('empty_provider'));
        Http::assertNothingSent();
    }

    public function test_is_available_checks_dynamic_list(): void
    {
        Cache::put('ai:model_catalog:bailian', ['qwen3.7-plus', 'glm-5.2'], 3600);

        $this->assertTrue($this->catalog->isAvailable('bailian', 'glm-5.2'));
        $this->assertFalse($this->catalog->isAvailable('bailian', 'gpt-4o'));
    }

    public function test_syncable_providers_requires_url_and_key(): void
    {
        config()->set('ai.providers', [
            'bailian' => ['url' => 'https://a.example.com', 'key' => 'k1'],
            'no_key' => ['url' => 'https://b.example.com', 'key' => ''],
            'gateway_style' => ['base_url' => 'https://c.example.com', 'api_key' => 'k2'],
        ]);

        $this->assertSame(['bailian', 'gateway_style'], $this->catalog->syncableProviders());
    }

    public function test_sync_command_reports_success_and_fallback(): void
    {
        Http::fake([
            'token-plan.example.com/compatible-mode/v1/models' => Http::response([
                'data' => [['id' => 'qwen3.7-plus'], ['id' => 'qwen3.6-flash']],
            ]),
        ]);

        $this->artisan('ai:models:sync', ['--provider' => 'bailian'])
            ->expectsOutputToContain('同步成功，缓存 2 个模型')
            ->assertExitCode(0);

        $this->assertSame(['qwen3.7-plus', 'qwen3.6-flash'], Cache::get('ai:model_catalog:bailian'));
    }
}
