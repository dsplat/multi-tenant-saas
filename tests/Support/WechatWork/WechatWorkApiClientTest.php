<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Support\WechatWork;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Support\WechatWork\WechatWorkApiClient;
use MultiTenantSaas\Tests\TestCase;

class WechatWorkApiClientTest extends TestCase
{
    private WechatWorkApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new WechatWorkApiClient('corp123', 'secret456', 'agent1');
    }

    private function fakeToken(): void
    {
        Http::fake([
            '*/gettoken*' => Http::response(['errcode' => 0, 'access_token' => 'fake-token', 'expires_in' => 7200]),
        ]);
    }

    public function test_add_msg_template_success(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/add_msg_template*' => Http::response([
                'errcode' => 0,
                'errmsg' => 'ok',
                'msgid' => 'msgGCAAAXtWyujaWJHDDGi0mAAAA',
                'fail_list' => ['wmqfasd1e1927831123109rBAAAA'],
            ]),
        ]);

        $result = $this->client->addMsgTemplate([
            'chat_type' => 'group',
            'chat_id_list' => ['wr2GCAAAXtWyujaWJHDDGasdadAAA'],
            'sender' => 'zhangsan',
            'text' => ['content' => 'hello'],
        ]);

        $this->assertSame('msgGCAAAXtWyujaWJHDDGi0mAAAA', $result['msgid']);
        $this->assertSame(['wmqfasd1e1927831123109rBAAAA'], $result['fail_list']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/externalcontact/add_msg_template')
            && $request['chat_type'] === 'group'
            && $request['sender'] === 'zhangsan');
    }

    public function test_add_msg_template_failure_returns_empty_msgid(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/add_msg_template*' => Http::response(['errcode' => 40058, 'errmsg' => 'invalid msgid']),
        ]);

        $result = $this->client->addMsgTemplate(['chat_type' => 'single']);

        $this->assertSame('', $result['msgid']);
        $this->assertSame([], $result['fail_list']);
    }

    public function test_remind_msg_template_success(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/remind_msg_template*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $ok = $this->client->remindMsgTemplate('msgGCAAAXtWyujaWJHDDGi0mAAAA', ['zhangsan']);

        $this->assertTrue($ok);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/remind_msg_template')
            && $request['msgid'] === 'msgGCAAAXtWyujaWJHDDGi0mAAAA'
            && $request['userid_list'] === ['zhangsan']);
    }

    public function test_remind_msg_template_without_user_list(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/remind_msg_template*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $this->assertTrue($this->client->remindMsgTemplate('msgGCAAAXtWyujaWJHDDGi0mAAAA'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/remind_msg_template')
            && ! isset($request['userid_list']));
    }

    public function test_cancel_msg_template(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/cancel_msg_template*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $this->assertTrue($this->client->cancelMsgTemplate('msgGCAAAXtWyujaWJHDDGi0mAAAA'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/cancel_msg_template')
            && $request['msgid'] === 'msgGCAAAXtWyujaWJHDDGi0mAAAA');
    }

    public function test_group_msg_send_result_with_pagination(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/get_group_msg_send_result*' => Http::response([
                'errcode' => 0,
                'send_list' => [
                    ['userid' => 'zhangsan', 'status' => 1, 'send_time' => 1600000000],
                    ['userid' => 'lisi', 'status' => 0, 'send_time' => 0],
                ],
                'next_cursor' => 'CURSOR_1',
            ]),
        ]);

        $result = $this->client->groupMsgSendResult('msgGCAAAXtWyujaWJHDDGi0mAAAA', 500, 'PREV_CURSOR');

        $this->assertCount(2, $result['send_list']);
        $this->assertSame(1, $result['send_list'][0]['status']);
        $this->assertSame('CURSOR_1', $result['next_cursor']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/get_group_msg_send_result')
            && $request['msgid'] === 'msgGCAAAXtWyujaWJHDDGi0mAAAA'
            && $request['limit'] === 500
            && $request['cursor'] === 'PREV_CURSOR');
    }

    public function test_group_msg_list_v2(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/get_group_msg_list_v2*' => Http::response([
                'errcode' => 0,
                'group_msg_list' => [['msgid' => 'msgGCAAAXtWyujaWJHDDGi0mAAAA', 'creator' => 'zhangsan']],
                'next_cursor' => '',
            ]),
        ]);

        $result = $this->client->groupMsgListV2(['chat_type' => 'group', 'creator' => 'zhangsan']);

        $this->assertCount(1, $result['group_msg_list']);
        $this->assertSame('msgGCAAAXtWyujaWJHDDGi0mAAAA', $result['group_msg_list'][0]['msgid']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/get_group_msg_list_v2')
            && $request['chat_type'] === 'group');
    }

    public function test_external_contact_list(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/list*' => Http::response([
                'errcode' => 0,
                'external_userid' => ['woAJ2GCAAAXtWyujaWJHDDGi0mACAAAA', 'wmqfasd1e1927831123109rBAAAA'],
            ]),
        ]);

        $list = $this->client->externalContactList('zhangsan');

        $this->assertCount(2, $list);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/externalcontact/list')
            && $request['userid'] === 'zhangsan');
    }

    public function test_external_contact_get(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/get*' => Http::response([
                'errcode' => 0,
                'external_contact' => ['external_userid' => 'woAJ2GCAAAXtWyujaWJHDDGi0mACAAAA', 'name' => '张三'],
                'follow_user' => [['userid' => 'zhangsan']],
            ]),
        ]);

        $detail = $this->client->externalContactGet('woAJ2GCAAAXtWyujaWJHDDGi0mACAAAA');

        $this->assertSame('张三', $detail['external_contact']['name']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/externalcontact/get')
            && $request['external_userid'] === 'woAJ2GCAAAXtWyujaWJHDDGi0mACAAAA');
    }

    public function test_external_contact_batch_get(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/batch/get_by_user*' => Http::response([
                'errcode' => 0,
                'external_contact_list' => [
                    ['external_contact' => ['external_userid' => 'woAJ2GCAAAXtWyujaWJHDDGi0mACAAAA']],
                ],
                'next_cursor' => 'NEXT',
            ]),
        ]);

        $result = $this->client->externalContactBatchGet(['zhangsan', 'lisi']);

        $this->assertCount(1, $result['external_contact_list']);
        $this->assertSame('NEXT', $result['next_cursor']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/batch/get_by_user')
            && $request['userid_list'] === ['zhangsan', 'lisi']);
    }

    public function test_media_upload_success(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/media/upload*' => Http::response(['errcode' => 0, 'media_id' => 'MEDIA_ID_123']),
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'ww_upload_');
        file_put_contents($tmp, 'fake image bytes');

        try {
            $mediaId = $this->client->mediaUpload('image', $tmp);
            $this->assertSame('MEDIA_ID_123', $mediaId);
            Http::assertSent(fn ($request) => str_contains($request->url(), '/media/upload')
                && str_contains($request->url(), 'type=image'));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_media_upload_missing_file_returns_null(): void
    {
        $this->fakeToken();

        $this->assertNull($this->client->mediaUpload('image', '/nonexistent/file.png'));
    }

    public function test_update_app_chat(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/appchat/update*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $ok = $this->client->updateAppChat('wr2GCAAAXtWyujaWJHDDGasdadAAA', ['del_user_list' => ['wmqfasd1e1927831123109rBAAAA']]);

        $this->assertTrue($ok);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/appchat/update')
            && $request['chatid'] === 'wr2GCAAAXtWyujaWJHDDGasdadAAA');
    }

    public function test_group_chat_update(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/groupchat/update*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $ok = $this->client->groupChatUpdate('wrNplhCwAAX8B-WVqS3Ls4oBzU8LbQAAA', ['announcement' => '新群公告']);

        $this->assertTrue($ok);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/externalcontact/groupchat/update')
            && $request['chat_id'] === 'wrNplhCwAAX8B-WVqS3Ls4oBzU8LbQAAA'
            && $request['announcement'] === '新群公告');
    }

    public function test_send_welcome_msg(): void
    {
        $this->fakeToken();
        Http::fake([
            '*/externalcontact/send_welcome_msg*' => Http::response(['errcode' => 0, 'errmsg' => 'ok']),
        ]);

        $ok = $this->client->sendWelcomeMsg('CALLBACK_WELCOME_CODE', ['text' => ['content' => '欢迎加入']]);

        $this->assertTrue($ok);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/externalcontact/send_welcome_msg')
            && $request['welcome_code'] === 'CALLBACK_WELCOME_CODE');
    }
}
