<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\SDK\Client;
use MultiTenantSaas\SDK\Exceptions\SdkException;
use MultiTenantSaas\SDK\Resources\AiResource;
use MultiTenantSaas\SDK\Resources\PaymentResource;
use MultiTenantSaas\SDK\Resources\TenantResource;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * 可控的 HTTP 处理器（用于注入 SDK Client，避免真实网络调用）
 *
 * 按脚本顺序返回响应，并记录每次调用的方法、URL、请求头与请求体。
 */
class FakeHttpHandler
{
    /** @var array<int, array{method:string,url:string,headers:array<string,string>,body:string}> */
    public array $calls = [];

    /** @var array<int, array{status:int, body:string, error?:?string}> */
    private array $script;

    private int $index = 0;

    public function __construct(array $script)
    {
        $this->script = $script;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status:int, body:string, error:?string}
     */
    public function __invoke(string $method, string $url, array $headers, string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $response = $this->script[$this->index] ?? $this->script[count($this->script) - 1];
        $this->index++;

        return [
            'status' => $response['status'],
            'body' => $response['body'] ?? '',
            'error' => $response['error'] ?? null,
        ];
    }
}

/**
 * TASK-021 PHP SDK 单元测试
 *
 * 覆盖：链式调用、API Key 鉴权、请求构建、JSON 解析、
 * 4xx 立即失败、5xx/连接错误重试、重试耗尽抛出、异常辅助方法。
 *
 * 通过注入 http_handler（callable）避免真实网络调用，
 * 同时完整验证请求构建与重试逻辑。
 */
class SdkTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    private string $apiBaseUrl = 'https://api.example.com';

    private string $apiKeyValue = 'sk_test_abc123';

    /**
     * 构造一个按脚本返回响应的 http_handler
     *
     * @param  array<int, array{status:int, body:string, error?:?string}>  $script
     */
    private function makeScriptedHandler(array $script): FakeHttpHandler
    {
        return new FakeHttpHandler($script);
    }

    /**
     * 构造一个始终返回固定响应的 http_handler
     */
    private function makeFixedHandler(int $status, string $body = '{}', ?string $error = null): FakeHttpHandler
    {
        return $this->makeScriptedHandler([['status' => $status, 'body' => $body, 'error' => $error]]);
    }

    private function client(FakeHttpHandler $handler, array $options = []): Client
    {
        return new Client($this->apiBaseUrl, $this->apiKeyValue, array_merge([
            'http_handler' => $handler,
            'retries' => 3,
            'retry_base_delay_ms' => 0, // 测试中不引入真实延迟
        ], $options));
    }

    // ---------- 链式调用 ----------

    public function test_tenant_resource_is_chainable(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true,"data":{"tenant_id":1001}}');
        $client = $this->client($handler);

        $resource = $client->tenant();
        $this->assertInstanceOf(TenantResource::class, $resource);

        $result = $client->tenant()->find(1001);
        $this->assertTrue($result['success']);
        $this->assertSame(1001, $result['data']['tenant_id']);
    }

    public function test_payment_resource_is_chainable(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true,"data":{"order_no":"ORD1"}}');
        $client = $this->client($handler);

        $this->assertInstanceOf(PaymentResource::class, $client->payment());
        $result = $client->payment()->createOrder(1001, ['amount' => 10.5]);
        $this->assertSame('ORD1', $result['data']['order_no']);
    }

    public function test_ai_resource_is_chainable(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true,"data":{"text":"hello"}}');
        $client = $this->client($handler);

        $this->assertInstanceOf(AiResource::class, $client->ai());
        $result = $client->ai()->textCompletion(['prompt' => 'hi']);
        $this->assertSame('hello', $result['data']['text']);
    }

    // ---------- 鉴权 ----------

    public function test_request_sends_bearer_auth_header(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true}');
        $client = $this->client($handler);

        $client->tenant()->list();

        $this->assertNotEmpty($handler->calls);
        $this->assertSame('Bearer '.$this->apiKeyValue, $handler->calls[0]['headers']['Authorization']);
    }

    public function test_request_sends_accept_and_content_type_headers(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true}');
        $client = $this->client($handler);

        $client->tenant()->create(['name' => 'Acme']);

        $this->assertSame('application/json', $handler->calls[0]['headers']['Accept']);
        $this->assertSame('application/json', $handler->calls[0]['headers']['Content-Type']);
        $this->assertStringContainsString('MultiTenantSaas-PHP-SDK', $handler->calls[0]['headers']['User-Agent']);
    }

    // ---------- 请求构建 ----------

    public function test_request_appends_query_params(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true}');
        $client = $this->client($handler);

        $client->tenant()->list(['page' => 2, 'size' => 20]);

        $this->assertStringContainsString('page=2', $handler->calls[0]['url']);
        $this->assertStringContainsString('size=20', $handler->calls[0]['url']);
        $this->assertStringStartsWith($this->apiBaseUrl.'/v1/tenants', $handler->calls[0]['url']);
    }

    public function test_request_encodes_json_body(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true}');
        $client = $this->client($handler);

        $client->tenant()->create(['name' => 'Acme', 'slug' => 'acme']);

        $decoded = json_decode($handler->calls[0]['body'], true);
        $this->assertSame('Acme', $decoded['name']);
        $this->assertSame('acme', $decoded['slug']);
        $this->assertSame('POST', $handler->calls[0]['method']);
    }

    public function test_custom_api_prefix(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true}');
        $client = $this->client($handler, ['api_prefix' => '/api/v2']);

        $client->tenant()->find(1);

        $this->assertStringContainsString('/api/v2/tenants/1', $handler->calls[0]['url']);
    }

    public function test_tenant_find_uses_correct_method_and_path(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true}');
        $client = $this->client($handler);

        $client->tenant()->find(1001);

        $this->assertSame('GET', $handler->calls[0]['method']);
        $this->assertSame($this->apiBaseUrl.'/v1/tenants/1001', $handler->calls[0]['url']);
    }

    public function test_payment_refund_uses_correct_method_and_path(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true}');
        $client = $this->client($handler);

        $client->payment()->refund(1001, ['order_no' => 'ORD1']);

        $this->assertSame('POST', $handler->calls[0]['method']);
        $this->assertSame($this->apiBaseUrl.'/v1/tenants/1001/payment-orders/refund', $handler->calls[0]['url']);
    }

    public function test_ai_text_completion_uses_correct_path(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true}');
        $client = $this->client($handler);

        $client->ai()->textCompletion(['prompt' => 'hi']);

        $this->assertSame('POST', $handler->calls[0]['method']);
        $this->assertSame($this->apiBaseUrl.'/v1/ai/text', $handler->calls[0]['url']);
    }

    // ---------- 响应解析 ----------

    public function test_success_returns_decoded_json(): void
    {
        $handler = $this->makeFixedHandler(200, '{"success":true,"data":{"id":42}}');
        $client = $this->client($handler);

        $result = $client->tenant()->find(42);

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['data']['id']);
    }

    public function test_non_json_success_response_returns_raw(): void
    {
        $handler = $this->makeFixedHandler(200, 'plain text response');
        $client = $this->client($handler);

        $result = $client->tenant()->find(1);

        $this->assertArrayHasKey('raw', $result);
        $this->assertSame('plain text response', $result['raw']);
    }

    // ---------- 错误处理 ----------

    public function test_4xx_throws_sdk_exception_without_retry(): void
    {
        $handler = $this->makeFixedHandler(
            404,
            '{"success":false,"message":"not found","error_code":"not_found"}',
        );
        $client = $this->client($handler);

        try {
            $client->tenant()->find(999);
            $this->fail('Expected SdkException was not thrown');
        } catch (SdkException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('not_found', $e->getErrorCode());
            $this->assertTrue($e->isClientError());
            $this->assertFalse($e->isServerError());
            $this->assertFalse($e->isConnectionError());
        }

        // 4xx 不重试，仅调用一次
        $this->assertCount(1, $handler->calls);
    }

    public function test_5xx_retries_then_succeeds(): void
    {
        $handler = $this->makeScriptedHandler([
            ['status' => 500, 'body' => '{"success":false,"message":"server error"}'],
            ['status' => 500, 'body' => '{"success":false,"message":"server error"}'],
            ['status' => 200, 'body' => '{"success":true,"data":{"ok":true}}'],
        ]);
        $client = $this->client($handler);

        $result = $client->tenant()->list();

        $this->assertTrue($result['success']);
        // 首次 2 次 5xx + 1 次成功 = 3 次调用
        $this->assertCount(3, $handler->calls);
    }

    public function test_5xx_retries_exhausted_throws_server_error(): void
    {
        $handler = $this->makeFixedHandler(503, '{"success":false,"message":"unavailable"}');
        $client = $this->client($handler, ['retries' => 2]);

        try {
            $client->tenant()->list();
            $this->fail('Expected SdkException was not thrown');
        } catch (SdkException $e) {
            $this->assertSame(503, $e->getStatusCode());
            $this->assertTrue($e->isServerError());
        }

        // retries=2 → 首次 + 2 次重试 = 3 次调用
        $this->assertCount(3, $handler->calls);
    }

    public function test_connection_error_retries_then_succeeds(): void
    {
        $handler = $this->makeScriptedHandler([
            ['status' => 0, 'body' => '', 'error' => 'Connection refused'],
            ['status' => 200, 'body' => '{"success":true}'],
        ]);
        $client = $this->client($handler);

        $result = $client->tenant()->list();

        $this->assertTrue($result['success']);
        $this->assertCount(2, $handler->calls);
    }

    public function test_connection_error_retries_exhausted_throws(): void
    {
        $handler = $this->makeFixedHandler(0, '', 'Connection timed out');
        $client = $this->client($handler, ['retries' => 1]);

        try {
            $client->tenant()->list();
            $this->fail('Expected SdkException was not thrown');
        } catch (SdkException $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertTrue($e->isConnectionError());
            $this->assertFalse($e->isServerError());
        }

        // retries=1 → 首次 + 1 次重试 = 2 次调用
        $this->assertCount(2, $handler->calls);
    }

    public function test_no_retries_option(): void
    {
        $handler = $this->makeFixedHandler(500, '{"success":false,"message":"err"}');
        $client = $this->client($handler, ['retries' => 0]);

        try {
            $client->tenant()->list();
        } catch (SdkException $e) {
            $this->assertTrue($e->isServerError());
        }

        $this->assertCount(1, $handler->calls);
    }

    public function test_non_json_error_response_throws(): void
    {
        $handler = $this->makeFixedHandler(500, 'Internal Server Error (html)');
        $client = $this->client($handler, ['retries' => 0]);

        $this->expectException(SdkException::class);
        $client->tenant()->list();
    }

    public function test_sdk_exception_carries_context(): void
    {
        $handler = $this->makeFixedHandler(400, '{"success":false,"message":"bad request"}');
        $client = $this->client($handler);

        try {
            $client->tenant()->find(1);
        } catch (SdkException $e) {
            $this->assertSame('bad request', $e->getMessage());
            $this->assertNotEmpty($e->getResponseBody());
            $this->assertArrayHasKey('path', $e->getContext());
        }
    }
}
