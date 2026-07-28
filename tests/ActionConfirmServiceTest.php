<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Carbon;
use MultiTenantSaas\Modules\Ai\Services\Agent\ActionConfirmService;

/**
 * L2 操作确认令牌服务测试
 *
 * 覆盖：签发结构 / 消费成功 / 不存在或过期 / 哈希不符 / 重放（二次消费）/
 * 租户与会话归属不符 / 参数哈希键序无关性
 */
class ActionConfirmServiceTest extends TestCase
{
    protected ActionConfirmService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActionConfirmService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_issue_returns_token_structure(): void
    {
        $issued = $this->service->issue(1001, 2001, 'tag_customer', ['user_id' => 5, 'tag_names' => ['VIP']]);

        $this->assertArrayHasKey('token', $issued);
        $this->assertArrayHasKey('args_hash', $issued);
        $this->assertSame(ActionConfirmService::TTL_SECONDS, $issued['expires_in']);
        $this->assertSame(48, strlen($issued['token']));
        $this->assertSame(64, strlen($issued['args_hash']));
    }

    public function test_consume_success_returns_payload(): void
    {
        $arguments = ['user_id' => 5, 'tag_names' => ['VIP', '高意向']];
        $issued = $this->service->issue(1001, 2001, 'tag_customer', $arguments, 'call_abc');

        $payload = $this->service->consume($issued['token'], 1001, 2001, $issued['args_hash']);

        $this->assertSame('tag_customer', $payload['tool_slug']);
        $this->assertSame($arguments, $payload['arguments']);
        $this->assertSame('call_abc', $payload['tool_call_id']);
        $this->assertSame(1001, $payload['tenant_id']);
        $this->assertSame(2001, $payload['conversation_id']);
    }

    public function test_consume_unknown_token_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不存在或已过期');

        $this->service->consume('not-a-real-token', 1001, 2001, str_repeat('a', 64));
    }

    public function test_consume_expired_token_throws(): void
    {
        $issued = $this->service->issue(1001, 2001, 'tag_customer', ['user_id' => 5]);

        // 时间穿越到 TTL 之后
        Carbon::setTestNow(now()->addSeconds(ActionConfirmService::TTL_SECONDS + 10));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不存在或已过期');

        $this->service->consume($issued['token'], 1001, 2001, $issued['args_hash']);
    }

    public function test_consume_wrong_args_hash_throws_and_invalidates_token(): void
    {
        $issued = $this->service->issue(1001, 2001, 'tag_customer', ['user_id' => 5]);

        try {
            $this->service->consume($issued['token'], 1001, 2001, str_repeat('f', 64));
            $this->fail('哈希不符时应抛出异常');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('参数与确认时不一致', $e->getMessage());
        }

        // 先删后校验：即便首次消费失败，令牌也已作废
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不存在或已过期');
        $this->service->consume($issued['token'], 1001, 2001, $issued['args_hash']);
    }

    public function test_consume_replay_throws(): void
    {
        $issued = $this->service->issue(1001, 2001, 'tag_customer', ['user_id' => 5]);

        $this->service->consume($issued['token'], 1001, 2001, $issued['args_hash']);

        // 重放：同一令牌二次消费必须失败
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('不存在或已过期');
        $this->service->consume($issued['token'], 1001, 2001, $issued['args_hash']);
    }

    public function test_consume_wrong_tenant_throws(): void
    {
        $issued = $this->service->issue(1001, 2001, 'tag_customer', ['user_id' => 5]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('与当前会话不匹配');

        $this->service->consume($issued['token'], 9999, 2001, $issued['args_hash']);
    }

    public function test_consume_wrong_conversation_throws(): void
    {
        $issued = $this->service->issue(1001, 2001, 'tag_customer', ['user_id' => 5]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('与当前会话不匹配');

        $this->service->consume($issued['token'], 1001, 9999, $issued['args_hash']);
    }

    public function test_hash_arguments_is_key_order_independent(): void
    {
        $hashA = $this->service->hashArguments([
            'user_id' => 5,
            'meta' => ['b' => 2, 'a' => 1],
            'tag_names' => ['VIP', '高意向'],
        ]);
        $hashB = $this->service->hashArguments([
            'tag_names' => ['VIP', '高意向'],
            'user_id' => 5,
            'meta' => ['a' => 1, 'b' => 2],
        ]);

        $this->assertSame($hashA, $hashB);

        // 列表数组保持原序：顺序不同视为不同参数
        $hashC = $this->service->hashArguments(['tag_names' => ['高意向', 'VIP'], 'user_id' => 5]);
        $this->assertNotSame($hashA, $hashC);
    }
}
