<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Ai\Services\AiAudioService;

/**
 * 语音评测契约测试（对标补足 #11 → scrm todo 测试节第 3 项）：
 * - 未配置供应商：isAvailable()=false、evaluate() 抛 ServiceUnavailable
 * - 未知供应商（平台配置或显式指定）一律抛 ServiceUnavailable 且消息带 key
 * - 显式指定优先于平台配置（resolveProvider 解析顺序）
 * - 已接入 bailian（百炼 qwen 两段式）：Http::fake 隔离外网，覆盖
 *   转写请求契约（input_audio 公网 URL）、评分请求契约（参考文本入参）、
 *   评分 JSON 解析容错（代码块包裹/缺总分兜底）
 */
class AiAudioServiceTest extends TestCase
{
    private AiAudioService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 每个用例从「未配置」干净起点开始（同进程内 config 共享）
        config()->set('ai.audio_eval.provider', null);
        config()->set('ai.audio_eval.transcribe_model', 'qwen3-asr-flash');
        config()->set('ai.audio_eval.scoring_model', 'qwen3.5-omni-flash');

        $this->service = $this->app->make(AiAudioService::class);
    }

    // ---------- isAvailable ----------

    public function test_is_available_false_when_not_configured(): void
    {
        $this->assertFalse($this->service->isAvailable());
    }

    public function test_is_available_false_when_provider_not_registered(): void
    {
        config()->set('ai.audio_eval.provider', 'some_asr');

        $this->assertFalse($this->service->isAvailable());
    }

    public function test_is_available_true_when_bailian_configured(): void
    {
        config()->set('ai.audio_eval.provider', 'bailian');

        $this->assertTrue($this->service->isAvailable());
    }

    // ---------- evaluate：未配置抛 503 ----------

    public function test_evaluate_throws_service_unavailable_when_not_configured(): void
    {
        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('语音评测供应商未配置');

        $this->service->evaluate('https://cdn.example.com/audios/demo.m4a');
    }

    public function test_evaluate_throws_service_unavailable_when_api_key_missing(): void
    {
        config()->set('ai.audio_eval.provider', 'bailian');
        config()->set('ai.providers.bailian.api_key', '');
        config()->set('ai.providers.bailian.key', '');

        try {
            $this->service->evaluate('https://cdn.example.com/audios/demo.m4a');
            $this->fail('供应商未配置 API Key 必须抛 ServiceUnavailable');
        } catch (ServiceUnavailableException $ex) {
            $this->assertStringContainsString('未配置 API Key', $ex->getMessage());
            $this->assertStringContainsString('bailian', $ex->getMessage());
        }
    }

    // ---------- 未知供应商报错 ----------

    public function test_evaluate_rejects_unknown_configured_provider(): void
    {
        config()->set('ai.audio_eval.provider', 'unknown_asr');

        try {
            $this->service->evaluate('https://cdn.example.com/audios/demo.m4a');
            $this->fail('未知供应商必须抛 ServiceUnavailable');
        } catch (ServiceUnavailableException $ex) {
            $this->assertStringContainsString('未知语音评测供应商', $ex->getMessage());
            $this->assertStringContainsString('unknown_asr', $ex->getMessage());
        }
    }

    public function test_evaluate_rejects_unknown_explicit_provider(): void
    {
        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('未知语音评测供应商: bad_provider');

        $this->service->evaluate('https://cdn.example.com/audios/demo.m4a', '', ['provider' => 'bad_provider']);
    }

    public function test_explicit_provider_takes_priority_over_config(): void
    {
        config()->set('ai.audio_eval.provider', 'cfg_provider');

        try {
            $this->service->evaluate('https://cdn.example.com/audios/demo.m4a', '', ['provider' => 'explicit_provider']);
            $this->fail('未知供应商必须抛 ServiceUnavailable');
        } catch (ServiceUnavailableException $ex) {
            // 显式指定优先：报错引用的是显式 key，而非平台配置 key
            $this->assertStringContainsString('explicit_provider', $ex->getMessage());
            $this->assertStringNotContainsString('cfg_provider', $ex->getMessage());
        }
    }

    // ---------- bailian 两段式全链路 ----------

    public function test_evaluate_bailian_two_stage_full_chain(): void
    {
        $this->prepareBailian();

        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true);

            if (($body['model'] ?? '') === 'qwen3-asr-flash') {
                // 第一段：ASR 转写响应
                return Http::response([
                    'choices' => [['message' => ['content' => 'Hello world. This is my reading.']]],
                    'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 20, 'total_tokens' => 820],
                ]);
            }

            // 第二段：评分响应
            return Http::response([
                'choices' => [['message' => ['content' => '{"score":88,"dimensions":{"pronunciation":90,"fluency":85,"integrity":89}}']]],
            ]);
        });

        $result = $this->service->evaluate('https://cdn.example.com/audios/demo.m4a', 'Hello world. This is my reading.');

        $this->assertSame(88, $result['score']);
        $this->assertSame(['pronunciation' => 90, 'fluency' => 85, 'integrity' => 89], $result['dimensions']);
        $this->assertSame('Hello world. This is my reading.', $result['transcript']);
        $this->assertArrayHasKey('transcribe', $result['raw']);
        $this->assertArrayHasKey('score', $result['raw']);

        // 转写请求契约：input_audio 携带音频公网 URL
        Http::assertSent(fn (Request $request) => json_decode((string) $request->body(), true)['model'] === 'qwen3-asr-flash'
            && json_decode((string) $request->body(), true)['messages'][0]['content'][0]['type'] === 'input_audio'
            && json_decode((string) $request->body(), true)['messages'][0]['content'][0]['input_audio']['data'] === 'https://cdn.example.com/audios/demo.m4a');

        // 评分请求契约：参考文本进入 user 消息（跟读比对）
        Http::assertSent(function (Request $request) {
            $body = json_decode((string) $request->body(), true);
            $userContent = (string) ($body['messages'][1]['content'] ?? '');

            return ($body['model'] ?? '') === 'qwen3.5-omni-flash'
                && str_contains($userContent, '参考文本：Hello world. This is my reading.');
        });
    }

    public function test_evaluate_scoring_accepts_markdown_json_block(): void
    {
        // 评分模型返回 ```json 代码块包裹（容错解析）
        $this->prepareBailian();

        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true);

            if (($body['model'] ?? '') === 'qwen3-asr-flash') {
                return Http::response(['choices' => [['message' => ['content' => 'Good morning.']]]]);
            }

            return Http::response([
                'choices' => [['message' => ['content' => "好的，以下是评测结果：\n```json\n{\"score\":76,\"dimensions\":{\"pronunciation\":80,\"fluency\":72,\"integrity\":75}}\n```"]]],
            ]);
        });

        $result = $this->service->evaluate('https://cdn.example.com/audios/demo.m4a', 'Good morning.');

        $this->assertSame(76, $result['score']);
        $this->assertSame(['pronunciation' => 80, 'fluency' => 72, 'integrity' => 75], $result['dimensions']);
    }

    public function test_evaluate_score_falls_back_to_dimension_average(): void
    {
        // 模型漏给总分时，按三维均值兜底
        $this->prepareBailian();

        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true);

            if (($body['model'] ?? '') === 'qwen3-asr-flash') {
                return Http::response(['choices' => [['message' => ['content' => 'Speech without reference.']]]]);
            }

            return Http::response([
                'choices' => [['message' => ['content' => '{"dimensions":{"pronunciation":80,"fluency":70,"integrity":60}}']]],
            ]);
        });

        $result = $this->service->evaluate('https://cdn.example.com/audios/demo.m4a');

        $this->assertSame(70, $result['score']);
        $this->assertSame(['pronunciation' => 80, 'fluency' => 70, 'integrity' => 60], $result['dimensions']);
    }

    public function test_evaluate_throws_when_transcription_empty(): void
    {
        $this->prepareBailian();

        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true);

            if (($body['model'] ?? '') === 'qwen3-asr-flash') {
                return Http::response(['choices' => [['message' => ['content' => '']]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => '{}']]]]);
        });

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('语音转写返回空文本');

        $this->service->evaluate('https://cdn.example.com/audios/demo.m4a', 'Hello');
    }

    public function test_evaluate_throws_when_scoring_json_invalid(): void
    {
        $this->prepareBailian();

        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true);

            if (($body['model'] ?? '') === 'qwen3-asr-flash') {
                return Http::response(['choices' => [['message' => ['content' => 'Hello world.']]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => '抱歉，我无法完成评测。']]]]);
        });

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('语音评测评分响应解析失败');

        $this->service->evaluate('https://cdn.example.com/audios/demo.m4a', 'Hello world.');
    }

    // ---------- helpers ----------

    private function prepareBailian(): void
    {
        config()->set('ai.audio_eval.provider', 'bailian');
        config()->set('ai.providers.bailian.api_key', 'sk-test-bailian');
        config()->set('ai.providers.bailian.key', 'sk-test-bailian');
        config()->set('ai.providers.bailian.base_url', 'https://dashscope.aliyuncs.com/compatible-mode/v1');
    }
}