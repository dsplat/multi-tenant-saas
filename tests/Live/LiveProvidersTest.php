<?php

namespace MultiTenantSaas\Tests\Live;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;
use MultiTenantSaas\Modules\Live\Models\LiveRoom;
use MultiTenantSaas\Modules\Live\Services\LiveCredentialService;
use MultiTenantSaas\Modules\Live\Services\LiveRoomService;
use MultiTenantSaas\Tests\Schema\CourseModule;
use MultiTenantSaas\Tests\Schema\LiveModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;

/**
 * Live 二期：Provider 双家对接 + 弹幕记录
 *
 * - 凭证双轨解析（租户 group=live → 平台 system_setting），缺失抛 ServiceUnavailableException
 * - PolyvProvider：v3 签名参数（appId/timestamp/sign=md5(appId.timestamp.secret)）与端点断言（Http::fake 隔离外网）
 * - TencentProvider：纯 URL 签名（txSecret=md5(key+streamName+txTime)）、回放文件名规则、无聊天室
 * - recordChatMessage：provider_msg_id 幂等去重
 */
class LiveProvidersTest extends TestCase
{
    protected array $uses = [ProductModule::class, CourseModule::class, LiveModule::class];

    protected const TENANT_ID = 7251;

    protected LiveRoomService $service;

    protected TenantSettingService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Provider Tenant',
            'slug' => 'provider-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);

        // 测试 schema 无 system_settings 表，补建（凭证回退查询需要）
        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function ($table) {
                $table->bigInteger('setting_id')->unsigned()->primary();
                $table->string('group', 50);
                $table->string('key', 100);
                $table->text('value')->nullable();
                $table->boolean('is_encrypted')->default(false);
                $table->string('description', 255)->nullable();
                $table->timestamps();
            });
        }

        $this->service = $this->app->make(LiveRoomService::class);
        $this->settings = $this->app->make(TenantSettingService::class);
    }

    // ---------- 凭证解析 ----------

    public function test_polyv_credentials_resolved_from_tenant_settings(): void
    {
        $this->setPolyvCredentials();

        $credentials = $this->app->make(LiveCredentialService::class)->for('polyv', self::TENANT_ID);

        $this->assertSame('test-app-id', $credentials['app_id']);
        $this->assertSame('test-secret', $credentials['secret']);
    }

    public function test_missing_polyv_credentials_throws_service_unavailable(): void
    {
        $this->expectException(ServiceUnavailableException::class);

        $this->service->create(self::TENANT_ID, [
            'title' => '无凭证保利威房',
            'provider' => LiveRoom::PROVIDER_POLYV,
        ]);
    }

    public function test_missing_tencent_credentials_throws_service_unavailable(): void
    {
        $this->expectException(ServiceUnavailableException::class);

        $this->service->create(self::TENANT_ID, [
            'title' => '无凭证腾讯房',
            'provider' => LiveRoom::PROVIDER_TENCENT,
        ]);
    }

    public function test_unknown_credential_provider_rejected(): void
    {
        $this->expectException(ServiceUnavailableException::class);
        $this->app->make(LiveCredentialService::class)->for('zoom', self::TENANT_ID);
    }

    // ---------- PolyvProvider ----------

    public function test_polyv_create_room_signs_and_persists_channel(): void
    {
        $this->setPolyvCredentials();

        Http::fake([
            'api.polyv.net/live/v3/channel/basic/create' => Http::response([
                'code' => 200,
                'channel' => ['channelId' => 300188, 'channelPass' => '888888'],
            ]),
        ]);

        $room = $this->service->create(self::TENANT_ID, [
            'title' => '保利威房',
            'provider' => LiveRoom::PROVIDER_POLYV,
        ]);

        $this->assertSame(LiveRoom::PROVIDER_POLYV, $room->provider);
        $this->assertSame('300188', $room->provider_room_id);
        $this->assertSame(300188, $room->config['channel_id']);
        $this->assertSame('888888', $room->config['channel_pass']);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['appId'] === 'test-app-id'
                && isset($data['timestamp'], $data['sign'])
                && $data['sign'] === md5('test-app-id' . $data['timestamp'] . 'test-secret')
                && $data['name'] === '保利威房';
        });
    }

    public function test_polyv_api_error_wraps_service_unavailable(): void
    {
        $this->setPolyvCredentials();

        Http::fake([
            'api.polyv.net/*' => Http::response(['code' => 400, 'message' => 'quota exceeded']),
        ]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('quota exceeded');

        $this->service->create(self::TENANT_ID, [
            'title' => '配额超限房',
            'provider' => LiveRoom::PROVIDER_POLYV,
        ]);
    }

    public function test_polyv_stream_urls_and_watch_page(): void
    {
        $this->setPolyvCredentials();

        Http::fake([
            'api.polyv.net/live/v3/channel/basic/create' => Http::response([
                'code' => 200,
                'channel' => ['channelId' => 300188],
            ]),
            'api.polyv.net/live/v3/channel/push-url/get*' => Http::response([
                'code' => 200,
                'data' => ['url' => 'rtmp://push.polyv.net/live/300188?key=x'],
            ]),
        ]);

        $room = $this->service->create(self::TENANT_ID, [
            'title' => '保利威推拉流',
            'provider' => LiveRoom::PROVIDER_POLYV,
        ]);
        $urls = $this->service->getStreamUrls(self::TENANT_ID, (int) $room->room_id);

        $this->assertSame('rtmp://push.polyv.net/live/300188?key=x', $urls['push']);
        $this->assertSame('https://live.polyv.cn/watch/300188', $urls['play']);
    }

    public function test_polyv_chat_config_returns_viewer_token(): void
    {
        $this->setPolyvCredentials();

        Http::fake([
            'api.polyv.net/live/v3/channel/basic/create' => Http::response([
                'code' => 200,
                'channel' => ['channelId' => 300188],
            ]),
            'api.polyv.net/live/v3/chat/viewer-token*' => Http::response([
                'code' => 200,
                'data' => ['token' => 'viewer-token-abc'],
            ]),
        ]);

        $room = $this->service->create(self::TENANT_ID, [
            'title' => '保利威弹幕房',
            'provider' => LiveRoom::PROVIDER_POLYV,
        ]);
        $chat = $this->service->chatConfig(self::TENANT_ID, (int) $room->room_id);

        $this->assertNotNull($chat);
        $this->assertSame('polyv', $chat['type']);
        $this->assertSame('viewer-token-abc', $chat['viewer_token']);
        $this->assertSame('https://chat.polyv.net?channelId=300188', $chat['chat_url']);
    }

    // ---------- TencentProvider ----------

    public function test_tencent_create_room_generates_stream_name(): void
    {
        $this->setTencentCredentials();

        $room = $this->service->create(self::TENANT_ID, [
            'title' => '腾讯房',
            'provider' => LiveRoom::PROVIDER_TENCENT,
        ]);

        $this->assertSame(LiveRoom::PROVIDER_TENCENT, $room->provider);
        $this->assertNotEmpty($room->provider_room_id);
        $this->assertStringStartsWith('live_', $room->provider_room_id);
        $this->assertSame($room->provider_room_id, $room->config['stream_name']);
    }

    public function test_tencent_stream_urls_signed_with_tx_secret(): void
    {
        $this->setTencentCredentials();

        $room = $this->service->create(self::TENANT_ID, [
            'title' => '腾讯签名房',
            'provider' => LiveRoom::PROVIDER_TENCENT,
            'provider_room_id' => 'live_fixed',
        ]);
        $urls = $this->service->getStreamUrls(self::TENANT_ID, (int) $room->room_id);

        // 推流：rtmp + txSecret(txKey) + txTime（十六进制）
        $this->assertStringStartsWith('rtmp://push.example.com/live/live_fixed?txSecret=', $urls['push']);
        parse_str((string) parse_url((string) $urls['push'], PHP_URL_QUERY), $pushQuery);
        $this->assertSame(
            md5('push-key' . 'live_fixed' . $pushQuery['txTime']),
            $pushQuery['txSecret'],
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $pushQuery['txTime']);

        // 播放：m3u8 + txSecret(playKey)
        $this->assertSame('play.example.com', parse_url((string) $urls['play'], PHP_URL_HOST));
        $this->assertStringEndsWith('/live/live_fixed.m3u8', parse_url((string) $urls['play'], PHP_URL_PATH));
        parse_str((string) parse_url((string) $urls['play'], PHP_URL_QUERY), $playQuery);
        $this->assertSame(
            md5('play-key' . 'live_fixed' . $playQuery['txTime']),
            $playQuery['txSecret'],
        );
    }

    public function test_tencent_replay_url_follows_recording_naming_rule(): void
    {
        $this->setTencentCredentials();

        $room = $this->service->create(self::TENANT_ID, [
            'title' => '腾讯回放房',
            'provider' => LiveRoom::PROVIDER_TENCENT,
            'provider_room_id' => 'live_replay',
        ]);
        $this->service->startLive(self::TENANT_ID, (int) $room->room_id);
        $this->service->endLive(self::TENANT_ID, (int) $room->room_id);

        $ended = $room->fresh();
        $replay = $this->service->find(self::TENANT_ID, (int) $room->room_id);
        $provider = new \MultiTenantSaas\Modules\Live\Providers\TencentProvider(
            $this->app->make(LiveCredentialService::class)->for('tencent', self::TENANT_ID),
        );

        $url = $provider->getReplayUrl($replay);

        $expected = sprintf(
            'https://play.example.com/live/live_replay_%s_%s.m3u8',
            $ended->started_at->format('Y-m-d-H-i-s'),
            $ended->ended_at->format('Y-m-d-H-i-s'),
        );
        $this->assertSame($expected, $url);
    }

    public function test_tencent_has_no_chat_config(): void
    {
        $this->setTencentCredentials();

        $room = $this->service->create(self::TENANT_ID, [
            'title' => '腾讯无弹幕房',
            'provider' => LiveRoom::PROVIDER_TENCENT,
        ]);

        $this->assertNull($this->service->chatConfig(self::TENANT_ID, (int) $room->room_id));
    }

    // ---------- 弹幕记录 ----------

    public function test_record_chat_message_idempotent_by_provider_msg_id(): void
    {
        $room = $this->service->create(self::TENANT_ID, ['title' => '弹幕房']);

        $first = $this->service->recordChatMessage(self::TENANT_ID, (int) $room->room_id, [
            'provider_msg_id' => 'msg-1',
            'nick' => '学员A',
            'content' => '老师讲得真好',
            'sent_at' => '2026-09-01 10:00:00',
            'raw' => ['event' => 'MESSAGE'],
        ]);

        $this->assertSame('学员A', $first->nick);

        // 同 provider_msg_id 重复回调 → 幂等返回既有记录
        $duplicate = $this->service->recordChatMessage(self::TENANT_ID, (int) $room->room_id, [
            'provider_msg_id' => 'msg-1',
            'nick' => '学员A',
            'content' => '老师讲得真好',
        ]);
        $this->assertSame((int) $first->message_id, (int) $duplicate->message_id);

        // 新消息正常追加
        $this->service->recordChatMessage(self::TENANT_ID, (int) $room->room_id, [
            'provider_msg_id' => 'msg-2',
            'nick' => '学员B',
            'content' => '打卡',
            'sent_at' => '2026-09-01 10:00:05',
        ]);

        $messages = $this->service->chatMessages(self::TENANT_ID, (int) $room->room_id);
        $this->assertCount(2, $messages);
        $this->assertSame('老师讲得真好', $messages[0]->content);
        $this->assertSame('打卡', $messages[1]->content);
    }

    public function test_record_chat_message_without_provider_msg_id_always_appends(): void
    {
        $room = $this->service->create(self::TENANT_ID, ['title' => '无ID弹幕房']);

        $this->service->recordChatMessage(self::TENANT_ID, (int) $room->room_id, ['content' => '一条']);
        $this->service->recordChatMessage(self::TENANT_ID, (int) $room->room_id, ['content' => '两条']);

        $this->assertCount(2, $this->service->chatMessages(self::TENANT_ID, (int) $room->room_id));
    }

    public function test_record_chat_message_requires_existing_room(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->service->recordChatMessage(self::TENANT_ID, 999999999, ['content' => '孤儿消息']);
    }

    // ---------- 工具 ----------

    protected function setPolyvCredentials(): void
    {
        $this->settings->set(self::TENANT_ID, 'live', 'polyv.app_id', 'test-app-id');
        $this->settings->set(self::TENANT_ID, 'live', 'polyv.secret', 'test-secret');
    }

    protected function setTencentCredentials(): void
    {
        $this->settings->set(self::TENANT_ID, 'live', 'tencent.push_domain', 'push.example.com');
        $this->settings->set(self::TENANT_ID, 'live', 'tencent.play_domain', 'play.example.com');
        $this->settings->set(self::TENANT_ID, 'live', 'tencent.push_key', 'push-key');
        $this->settings->set(self::TENANT_ID, 'live', 'tencent.play_key', 'play-key');
    }
}
