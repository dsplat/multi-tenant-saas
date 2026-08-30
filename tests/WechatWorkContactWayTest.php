<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Events\WechatWorkExternalEvent;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;

class WechatWorkContactWayTest extends TestCase
{
    private function client(): WechatWorkApiClient
    {
        return new WechatWorkApiClient('wwcorp123', 'secret-abc', '1000002');
    }

    private function fakeApi(): void
    {
        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'ww-access-token',
                'expires_in' => 7200,
            ]),
        ]);
    }

    // ---------- addContactWay ----------

    public function test_add_contact_way_success(): void
    {
        $this->fakeApi();

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/externalcontact/add_contact_way*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'config_id' => '42b34949e138eb6e027c123cba77faaa',
                'qr_code' => 'https://p.qpic.cn/wwhead/duc2TvpEgSdicZ9RrdUtBkv2UiaA/0',
            ]),
        ]);

        $result = $this->client()->addContactWay([
            'type' => 1,
            'scene' => 2,
            'user' => ['zhangsan'],
            'skip_verify' => true,
            'state' => 'c01:zs',
        ]);

        $this->assertSame('42b34949e138eb6e027c123cba77faaa', $result['config_id']);
        $this->assertSame('https://p.qpic.cn/wwhead/duc2TvpEgSdicZ9RrdUtBkv2UiaA/0', $result['qr_code']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/externalcontact/add_contact_way')) {
                return false;
            }

            $body = $request->data();
            $this->assertSame(1, $body['type']);
            $this->assertSame(2, $body['scene']);
            $this->assertSame(['zhangsan'], $body['user']);
            $this->assertTrue($body['skip_verify']);
            $this->assertSame('c01:zs', $body['state']);

            return true;
        });
    }

    public function test_add_contact_way_throws_on_errcode(): void
    {
        $this->fakeApi();

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/externalcontact/add_contact_way*' => Http::response([
                'errcode' => 60020,
                'errmsg' => 'not allow to access from your ip',
            ]),
        ]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('60020');

        $this->client()->addContactWay(['type' => 1, 'scene' => 2, 'user' => ['zhangsan']]);
    }

    // ---------- addJoinWay ----------

    public function test_add_join_way_success(): void
    {
        $this->fakeApi();

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/externalcontact/groupchat/add_join_way*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'config_id' => '9ad7fa5cdaa6511298498f979c472aaa',
            ]),
        ]);

        $result = $this->client()->addJoinWay([
            'scene' => 2,
            'chat_id_list' => ['wrOgQhDgAAH2Yy-CTZ6POca8mlBEdaaa'],
            'auto_create_room' => 1,
            'state' => 'c01:zs',
        ]);

        $this->assertSame('9ad7fa5cdaa6511298498f979c472aaa', $result['config_id']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/externalcontact/groupchat/add_join_way')) {
                return false;
            }

            $body = $request->data();
            $this->assertSame(2, $body['scene']);
            $this->assertSame(['wrOgQhDgAAH2Yy-CTZ6POca8mlBEdaaa'], $body['chat_id_list']);
            $this->assertSame(1, $body['auto_create_room']);
            $this->assertSame('c01:zs', $body['state']);

            return true;
        });
    }

    public function test_add_join_way_throws_on_errcode(): void
    {
        $this->fakeApi();

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/externalcontact/groupchat/add_join_way*' => Http::response([
                'errcode' => 48002,
                'errmsg' => 'api forbidden',
            ]),
        ]);

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('48002');

        $this->client()->addJoinWay(['scene' => 2, 'chat_id_list' => ['wrOgQhDgAAH2Yy-CTZ6POca8mlBEdaaa']]);
    }

    // ---------- 温和失败（get/update/del） ----------

    public function test_get_update_del_contact_way_gentle_failure(): void
    {
        $this->fakeApi();

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/externalcontact/*' => Http::response(['errcode' => 40058, 'errmsg' => 'not found']),
        ]);

        $this->assertSame([], $this->client()->getContactWay('cfg-404'));
        $this->assertFalse($this->client()->updateContactWay('cfg-404', ['remark' => 'x']));
        $this->assertFalse($this->client()->delContactWay('cfg-404'));
    }

    public function test_get_update_del_join_way_gentle_failure(): void
    {
        $this->fakeApi();

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/externalcontact/groupchat/*' => Http::response(['errcode' => 40058, 'errmsg' => 'not found']),
        ]);

        $this->assertSame([], $this->client()->getJoinWay('cfg-404'));
        $this->assertFalse($this->client()->updateJoinWay('cfg-404', ['remark' => 'x']));
        $this->assertFalse($this->client()->delJoinWay('cfg-404'));
    }

    // ---------- WechatWorkExternalEvent state ----------

    public function test_external_event_carries_state(): void
    {
        $event = new WechatWorkExternalEvent(
            tenantId: 1001,
            eventType: WechatWorkExternalEvent::TYPE_CONTACT,
            changeType: 'add_external_contact',
            chatId: '',
            externalUserId: 'woAJ2GCAAAXtWyujaWJHDDGi0mACAAAA',
            welcomeCode: 'welcome-code-1',
            state: 'c01:zs',
            raw: ['State' => 'c01:zs'],
        );

        $this->assertSame('c01:zs', $event->state);
    }

    public function test_external_event_state_defaults_empty(): void
    {
        $event = new WechatWorkExternalEvent(
            tenantId: 1001,
            eventType: WechatWorkExternalEvent::TYPE_CHAT,
            changeType: 'add_member',
            chatId: 'wrOgQhDgAAH2Yy-CTZ6POca8mlBEdaaa',
            externalUserId: '',
            welcomeCode: '',
        );

        $this->assertSame('', $event->state);
        $this->assertSame([], $event->raw);
    }
}
