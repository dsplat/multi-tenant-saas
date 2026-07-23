<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Models\AiRequest;
use MultiTenantSaas\Modules\Ai\Services\AiVideoService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Storage\Models\FileUpload;
use MultiTenantSaas\Modules\Storage\Services\FileService;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\PluginModule;
use RuntimeException;

/**
 * AiVideoService 测试套件
 *
 * 覆盖：文生视频（Runway / Kling）、图生视频、视频编辑、帧提取、
 * 异步任务轮询（成功 / 运行中重试 / 失败 / 超时）、任务状态查询、
 * 结果存储（FileService）、请求日志（ai_requests）、回调通知事件、
 * 参数校验、提供商不支持操作、上游错误落库。
 *
 * 通过 Http::fake 模拟提供商 HTTP 请求与结果下载，通过 Queue::fake 捕获延迟轮询闭包，
 * 通过 Event::listen 捕获任务状态回调通知，通过 Storage::fake 模拟文件存储。
 */
class AiVideoServiceTest extends TestCase
{
    protected FileService $fileService;

    protected array $uses = [AiModule::class, BillingModule::class, PluginModule::class];

    protected ?AiVideoService $service = null;

    /**
     * 1x1 透明 PNG 的 base64，用于构造输入图片
     */
    protected const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /**
     * 最小合法 MP4 ftyp box 二进制，用于构造输入视频与模拟提供商结果下载
     */
    protected string $mp4Bin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileService = $this->app->make(FileService::class);


        // size 0x18=24, "ftyp", major "mp42", minor 0, compat "mp42","mp41","isom"
        $this->mp4Bin = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42mp41isom";

        Tenant::create(['tenant_id' => 2001, 'name' => 'Video Tenant', 'slug' => 'video-tenant', 'status' => 'active']);

        Storage::fake('local');

        $this->configureAiVideoDefaults();

        TenantContext::setTenantId('2001');

        $this->service = $this->app->make(AiVideoService::class);
    }

    /**
     * 设置视频 AI 默认配置与提供商密钥
     */
    protected function configureAiVideoDefaults(): void
    {
        config(['ai.providers.runway.api_key' => 'test-runway-key']);
        config(['ai.providers.runway.base_url' => 'https://api.dev.runwayml.com/v1']);
        config(['ai.providers.kling.api_key' => 'test-kling-key']);
        config(['ai.providers.kling.base_url' => 'https://api.klingai.com/v1']);
        config(['ai.video.default_provider' => 'runway']);
        config(['ai.video.default_model' => 'gen-3']);
        config(['ai.video.default_resolution' => '1280x768']);
        config(['ai.video.default_duration' => 5]);
        config(['ai.video.poll_interval_seconds' => 10]);
        config(['ai.video.max_poll_attempts' => 120]);
        config(['ai.video.storage_disk' => 'local']);
        config(['ai.video.storage_is_public' => false]);
        config(['ai.log.enable' => true]);
    }

    /**
     * 注册 Runway 提交与 Kling 提交的 HTTP fake 响应（均返回 PENDING 初始状态）
     */
    protected function fakeSubmits(): void
    {
        Http::fake([
            'https://api.dev.runwayml.com/v1/text_to_video*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'PENDING',
            ], 200),

            'https://api.dev.runwayml.com/v1/image_to_video*' => Http::response([
                'id' => 'task-runway-img',
                'status' => 'PENDING',
            ], 200),

            'https://api.dev.runwayml.com/v1/video_edit*' => Http::response([
                'id' => 'task-runway-edit',
                'status' => 'PENDING',
            ], 200),

            'https://api.klingai.com/v1/videos/text2video*' => Http::response([
                'code' => 0,
                'data' => ['task_id' => 'task-kling-1', 'task_status' => 'submitted'],
            ], 200),

            'https://api.klingai.com/v1/videos/image2video*' => Http::response([
                'code' => 0,
                'data' => ['task_id' => 'task-kling-img', 'task_status' => 'submitted'],
            ], 200),
        ]);
    }

    /**
     * 构造一个输入图片的 FileUpload 记录
     */
    protected function createInputImage(string $filename = 'input.png'): FileUpload
    {
        $binary = (string) base64_decode(self::PNG_B64, true);
        $tempPath = (string) tempnam(sys_get_temp_dir(), 'test_img_');
        file_put_contents($tempPath, $binary);

        $uploaded = new UploadedFile($tempPath, $filename, 'image/png', null, true);

        $file = $this->fileService->upload($uploaded, 2001, null, 'general', 'local', false);

        @unlink($tempPath);

        return $file;
    }

    /**
     * 构造一个输入视频的 FileUpload 记录
     */
    protected function createInputVideo(string $filename = 'input.mp4'): FileUpload
    {
        $tempPath = (string) tempnam(sys_get_temp_dir(), 'test_vid_');
        file_put_contents($tempPath, $this->mp4Bin);

        $uploaded = new UploadedFile($tempPath, $filename, 'video/mp4', null, true);

        $file = $this->fileService->upload($uploaded, 2001, null, 'general', 'local', false);

        @unlink($tempPath);

        return $file;
    }

    /**
     * 注册任务状态回调事件监听器，返回捕获引用
     *
     * @param  array<int, object>  $captured
     */
    protected function captureCallback(array &$captured): void
    {
        Event::listen('ai.video.task.updated', function ($payload) use (&$captured): void {
            $captured[] = $payload;
        });
    }

    // ======================================================================
    // textToVideo — Runway
    // ======================================================================

    public function test_text_to_video_with_runway_logs_request_and_dispatches_poll(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $result = $this->service->textToVideo('a cat playing piano', [
            'provider' => 'runway',
            'model' => 'gen-3',
            'duration' => 5,
            'resolution' => '1280x768',
        ]);

        $this->assertSame('runway', $result['provider']);
        $this->assertSame('gen-3', $result['model']);
        $this->assertSame('task-runway-1', $result['task_id']);
        $this->assertSame('PENDING', $result['status']);
        $this->assertNotEmpty($result['request_id']);

        $this->assertDatabaseHas('ai_requests', [
            'request_id' => $result['request_id'],
            'provider' => 'runway',
            'model' => 'gen-3',
            'status' => AiRequest::STATUS_PENDING,
        ]);

        // 已派发延迟轮询闭包
        Queue::assertPushed(CallQueuedClosure::class, 1);

        // 提交请求应携带 model 与 prompt
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.dev.runwayml.com/v1/text_to_video'
                && str_contains((string) $request->body(), 'gen-3')
                && str_contains((string) $request->body(), 'a cat playing piano');
        });
    }

    // ======================================================================
    // textToVideo — Kling
    // ======================================================================

    public function test_text_to_video_with_kling_returns_task_id(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $result = $this->service->textToVideo('a futuristic city at night', [
            'provider' => 'kling',
            'model' => 'kling-v2',
        ]);

        $this->assertSame('kling', $result['provider']);
        $this->assertSame('kling-v2', $result['model']);
        $this->assertSame('task-kling-1', $result['task_id']);
        $this->assertSame('PENDING', $result['status']);

        $this->assertDatabaseHas('ai_requests', [
            'request_id' => $result['request_id'],
            'provider' => 'kling',
            'model' => 'kling-v2',
            'status' => AiRequest::STATUS_PENDING,
        ]);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), 'https://api.klingai.com/v1/videos/text2video')
                && str_contains((string) $request->body(), 'kling-v2');
        });
    }

    // ======================================================================
    // imageToVideo — Runway
    // ======================================================================

    public function test_image_to_video_with_runway_sends_input_image_url(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $input = $this->createInputImage();
        $expectedUrl = $this->fileService->getUrl($input);

        $result = $this->service->imageToVideo($input->file_upload_id, 'make the cat dance', [
            'provider' => 'runway',
            'model' => 'gen-3',
        ]);

        $this->assertSame('runway', $result['provider']);
        $this->assertSame('task-runway-img', $result['task_id']);

        // 请求体应包含输入图片的可访问 URL（JSON 会转义斜杠，需还原后再匹配）
        Http::assertSent(function (Request $request) use ($expectedUrl): bool {
            $body = str_replace('\/', '/', (string) $request->body());

            return $request->url() === 'https://api.dev.runwayml.com/v1/image_to_video'
                && str_contains($body, $expectedUrl)
                && str_contains($body, 'make the cat dance');
        });
    }

    // ======================================================================
    // editVideo — Runway
    // ======================================================================

    public function test_edit_video_with_runway_sends_input_video_url(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $input = $this->createInputVideo();
        $expectedUrl = $this->fileService->getUrl($input);

        $result = $this->service->editVideo($input->file_upload_id, 'apply cyberpunk style', [
            'provider' => 'runway',
            'model' => 'gen-4',
        ]);

        $this->assertSame('runway', $result['provider']);
        $this->assertSame('task-runway-edit', $result['task_id']);

        Http::assertSent(function (Request $request) use ($expectedUrl): bool {
            $body = str_replace('\/', '/', (string) $request->body());

            return $request->url() === 'https://api.dev.runwayml.com/v1/video_edit'
                && str_contains($body, $expectedUrl);
        });
    }

    // ======================================================================
    // Kling 不支持视频编辑
    // ======================================================================

    public function test_kling_does_not_support_video_edit(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $input = $this->createInputVideo();

        $this->expectException(RuntimeException::class);

        $this->service->editVideo($input->file_upload_id, 'style', [
            'provider' => 'kling',
            'model' => 'kling-v2',
        ]);
    }

    // ======================================================================
    // pollTask — 成功
    // ======================================================================

    public function test_poll_task_success_stores_video_and_notifies(): void
    {
        Queue::fake();
        $captured = [];
        $this->captureCallback($captured);

        Http::fake([
            'https://api.dev.runwayml.com/v1/text_to_video*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'PENDING',
            ], 200),
            'https://api.dev.runwayml.com/v1/tasks/task-runway-1*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'SUCCEEDED',
                'output' => ['https://cdn.runway.com/result.mp4'],
            ], 200),
            'https://cdn.runway.com/*' => Http::response($this->mp4Bin, 200, ['Content-Type' => 'video/mp4']),
        ]);

        $submit = $this->service->textToVideo('a cat', ['provider' => 'runway', 'model' => 'gen-3']);

        $this->service->pollTask($submit['request_id']);

        // ai_requests 状态为 success 且记录了视频文件 ID
        $log = AiRequest::find($submit['request_id']);
        $this->assertSame(AiRequest::STATUS_SUCCESS, $log->status);
        $this->assertNotEmpty($log->metadata['video']['file_upload_id']);

        // 结果已落盘为 FileUpload
        $this->assertDatabaseHas('file_uploads', [
            'file_upload_id' => $log->metadata['video']['file_upload_id'],
            'mime_type' => 'video/mp4',
            'category' => 'ai_generated',
        ]);

        // 回调通知事件状态为 SUCCEEDED
        $this->assertNotEmpty($captured);
        $this->assertSame('SUCCEEDED', $captured[count($captured) - 1]->status);
        $this->assertNotEmpty($captured[count($captured) - 1]->video['file_upload_id']);
    }

    // ======================================================================
    // pollTask — 运行中（重新入队）
    // ======================================================================

    public function test_poll_task_running_redispatches_and_increments_attempts(): void
    {
        Queue::fake();
        $captured = [];
        $this->captureCallback($captured);

        Http::fake([
            'https://api.dev.runwayml.com/v1/text_to_video*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'PENDING',
            ], 200),
            'https://api.dev.runwayml.com/v1/tasks/task-runway-1*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'RUNNING',
                'progress' => 42,
            ], 200),
        ]);

        $submit = $this->service->textToVideo('a cat', ['provider' => 'runway', 'model' => 'gen-3']);

        // 提交时已派发 1 次轮询
        Queue::assertPushed(CallQueuedClosure::class, 1);

        $this->service->pollTask($submit['request_id']);

        $log = AiRequest::find($submit['request_id']);
        $this->assertSame(AiRequest::STATUS_PENDING, $log->status);
        $this->assertSame(1, $log->metadata['poll_attempts']);
        $this->assertSame('RUNNING', $log->metadata['video_status']);

        // 运行中重新入队 → 共派发 2 次闭包
        Queue::assertPushed(CallQueuedClosure::class, 2);

        // 回调通知事件状态为 RUNNING
        $last = $captured[count($captured) - 1];
        $this->assertSame('RUNNING', $last->status);
    }

    // ======================================================================
    // pollTask — 失败
    // ======================================================================

    public function test_poll_task_failed_marks_request_failed_and_notifies(): void
    {
        Queue::fake();
        $captured = [];
        $this->captureCallback($captured);

        Http::fake([
            'https://api.dev.runwayml.com/v1/text_to_video*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'PENDING',
            ], 200),
            'https://api.dev.runwayml.com/v1/tasks/task-runway-1*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'FAILED',
                'failure' => 'render error',
            ], 200),
        ]);

        $submit = $this->service->textToVideo('a cat', ['provider' => 'runway', 'model' => 'gen-3']);

        $this->service->pollTask($submit['request_id']);

        $log = AiRequest::find($submit['request_id']);
        $this->assertSame(AiRequest::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('render error', (string) $log->error_message);

        // 失败不再重新入队 → 仍是提交时的 1 次
        Queue::assertPushed(CallQueuedClosure::class, 1);

        $last = $captured[count($captured) - 1];
        $this->assertSame('FAILED', $last->status);
        $this->assertStringContainsString('render error', (string) $last->error);
    }

    // ======================================================================
    // pollTask — 超时
    // ======================================================================

    public function test_poll_task_timeout_marks_request_failed(): void
    {
        Queue::fake();
        config(['ai.video.max_poll_attempts' => 1]);

        $captured = [];
        $this->captureCallback($captured);

        Http::fake([
            'https://api.dev.runwayml.com/v1/text_to_video*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'PENDING',
            ], 200),
            'https://api.dev.runwayml.com/v1/tasks/task-runway-1*' => Http::response([
                'id' => 'task-runway-1',
                'status' => 'RUNNING',
            ], 200),
        ]);

        $submit = $this->service->textToVideo('a cat', ['provider' => 'runway', 'model' => 'gen-3']);

        $this->service->pollTask($submit['request_id']);

        $log = AiRequest::find($submit['request_id']);
        $this->assertSame(AiRequest::STATUS_FAILED, $log->status);
        $this->assertStringContainsString(trans('ai.video_task_timeout'), (string) $log->error_message);

        // 超时不再重新入队 → 仅提交时的 1 次
        Queue::assertPushed(CallQueuedClosure::class, 1);

        $last = $captured[count($captured) - 1];
        $this->assertSame('FAILED', $last->status);
    }

    // ======================================================================
    // getTask — 状态查询
    // ======================================================================

    public function test_get_task_returns_current_status(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $submit = $this->service->textToVideo('a cat', ['provider' => 'runway', 'model' => 'gen-3']);

        $task = $this->service->getTask($submit['request_id']);

        $this->assertSame($submit['request_id'], $task['request_id']);
        $this->assertSame('runway', $task['provider']);
        $this->assertSame('task-runway-1', $task['task_id']);
        $this->assertSame('PENDING', $task['status']);
        $this->assertSame(0, $task['poll_attempts']);
        $this->assertNull($task['video']);
    }

    public function test_get_task_throws_when_not_found(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->getTask(999999);
    }

    // ======================================================================
    // extractFrames — 帧提取
    // ======================================================================

    public function test_extract_frames_returns_evenly_spaced_timestamps(): void
    {
        $input = $this->createInputVideo();

        $result = $this->service->extractFrames($input->file_upload_id, ['count' => 4, 'duration' => 6]);

        $this->assertSame($input->file_upload_id, $result['file_upload_id']);
        $this->assertCount(4, $result['frames']);
        $this->assertSame(0, $result['frames'][0]['index']);
        $this->assertSame(0.0, $result['frames'][0]['timestamp']);
        $this->assertSame(2.0, $result['frames'][1]['timestamp']);
        $this->assertSame(6.0, $result['frames'][3]['timestamp']);
        $this->assertNotEmpty($result['source_url']);
    }

    public function test_extract_frames_throws_when_count_invalid(): void
    {
        $input = $this->createInputVideo();

        $this->expectException(RuntimeException::class);

        $this->service->extractFrames($input->file_upload_id, ['count' => 0]);
    }

    public function test_extract_frames_throws_when_input_not_found(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->extractFrames(999999, ['count' => 4]);
    }

    // ======================================================================
    // 参数校验
    // ======================================================================

    public function test_text_to_video_throws_when_prompt_empty(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->textToVideo('   ');
    }

    public function test_text_to_video_throws_when_prompt_too_long(): void
    {
        config(['ai.video.max_prompt_length' => 10]);

        $this->expectException(RuntimeException::class);

        $this->service->textToVideo(str_repeat('a', 11));
    }

    public function test_text_to_video_throws_on_unsupported_provider(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $this->expectException(RuntimeException::class);

        $this->service->textToVideo('prompt', ['provider' => 'pika']);
    }

    public function test_runway_throws_when_model_not_supported(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $this->expectException(RuntimeException::class);

        $this->service->textToVideo('prompt', ['provider' => 'runway', 'model' => 'unknown-model']);
    }

    public function test_image_to_video_throws_when_input_not_found(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $this->expectException(RuntimeException::class);

        $this->service->imageToVideo(999999, 'prompt', ['provider' => 'runway', 'model' => 'gen-3']);
    }

    // ======================================================================
    // 上游错误 → 落库失败
    // ======================================================================

    public function test_text_to_video_logs_failure_when_provider_returns_error(): void
    {
        Queue::fake();

        Http::fake([
            'https://api.dev.runwayml.com/v1/text_to_video*' => Http::response(['error' => 'boom'], 500),
        ]);

        $caught = null;
        try {
            $this->service->textToVideo('prompt', ['provider' => 'runway', 'model' => 'gen-3']);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);

        $this->assertDatabaseHas('ai_requests', [
            'provider' => 'runway',
            'model' => 'gen-3',
            'status' => AiRequest::STATUS_FAILED,
        ]);
    }

    // ======================================================================
    // 默认提供商路由
    // ======================================================================

    public function test_text_to_video_uses_default_provider_when_not_specified(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $result = $this->service->textToVideo('default prompt');

        $this->assertSame('runway', $result['provider']);
        $this->assertSame('gen-3', $result['model']);
    }

    public function test_text_to_video_routes_by_model_map_to_kling(): void
    {
        Queue::fake();
        $this->fakeSubmits();

        $result = $this->service->textToVideo('kling prompt', ['model' => 'kling-v2']);

        $this->assertSame('kling', $result['provider']);
        $this->assertSame('kling-v2', $result['model']);
    }
}
