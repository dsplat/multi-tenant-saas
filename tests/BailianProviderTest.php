<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Knowledge\Services\Providers\BailianProvider;

/**
 * 阿里云百炼 Provider 测试
 *
 * 覆盖：ACS3 签名头、检索结果映射、连接测试、文档推送四步链路、失败降级。
 */
class BailianProviderTest extends TestCase
{
    protected BailianProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new BailianProvider;
        $this->provider->configure([
            'api_url' => 'https://bailian.cn-beijing.aliyuncs.com',
            'api_key' => 'test-secret',
            'access_key_id' => 'test-ak-id',
            'workspace_id' => 'ws-1',
            'index_id' => 'idx-1',
        ]);
    }

    public function test_search_sends_signed_request_and_maps_nodes(): void
    {
        Http::fake([
            'bailian.cn-beijing.aliyuncs.com/ws-1/index/retrieve*' => Http::response([
                'Success' => true,
                'Data' => [
                    'Nodes' => [
                        [
                            'Text' => '百炼是一站式大模型开发平台',
                            'Score' => 0.87,
                            'Metadata' => json_encode(['doc_id' => 'doc_1', 'doc_name' => '产品介绍.pdf', 'title' => '产品介绍']),
                        ],
                    ],
                ],
            ]),
        ]);

        $results = $this->provider->search('百炼是什么', 5);

        $this->assertCount(1, $results);
        $this->assertSame('百炼是一站式大模型开发平台', $results[0]['content']);
        $this->assertSame(0.87, $results[0]['score']);
        $this->assertSame('doc_1', $results[0]['document_id']);
        $this->assertSame('产品介绍.pdf', $results[0]['document_name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/ws-1/index/retrieve')
                && str_contains($request->url(), 'IndexId=idx-1')
                && str_starts_with($request->header('Authorization')[0] ?? '', 'ACS3-HMAC-SHA256 Credential=test-ak-id,')
                && $request->hasHeader('x-acs-date')
                && $request->hasHeader('x-acs-signature-nonce')
                && $request->hasHeader('x-acs-content-sha256');
        });
    }

    public function test_search_returns_empty_on_failure(): void
    {
        Http::fake([
            'bailian.cn-beijing.aliyuncs.com/*' => Http::response(['Success' => false, 'Message' => 'Forbidden'], 403),
        ]);

        $this->assertSame([], $this->provider->search('任意问题'));
    }

    public function test_connection_test_uses_list_indices(): void
    {
        Http::fake([
            'bailian.cn-beijing.aliyuncs.com/ws-1/index/list_indices*' => Http::response(['Success' => true]),
        ]);

        $result = $this->provider->test();

        $this->assertTrue($result['success']);
    }

    public function test_push_document_runs_full_chain(): void
    {
        Http::fake([
            'bailian.cn-beijing.aliyuncs.com/ws-1/datacenter/category/default*' => Http::response([
                'Success' => 'true',
                'Data' => [
                    'FileUploadLeaseId' => 'lease-1',
                    'Param' => [
                        'Url' => 'https://upload.example.com/tmp/file.txt',
                        'Method' => 'PUT',
                        'Headers' => ['X-bailian-extra' => 'extra-token', 'Content-Type' => 'text/plain'],
                    ],
                ],
            ]),
            'upload.example.com/*' => Http::response('', 200),
            'bailian.cn-beijing.aliyuncs.com/ws-1/datacenter/file*' => Http::response([
                'Success' => 'true',
                'Data' => ['FileId' => 'file-99'],
            ]),
            'bailian.cn-beijing.aliyuncs.com/ws-1/index/add_documents_to_index*' => Http::response(['Success' => true]),
        ]);

        $result = $this->provider->pushDocument('企业FAQ', 'Q: 如何退款? A: 联系客服。');

        $this->assertTrue($result['success']);
        $this->assertSame('file-99', $result['external_id']);

        // 追加索引任务须带上目标知识库与文件 ID
        Http::assertSent(fn ($request) => str_contains($request->url(), '/index/add_documents_to_index')
            && str_contains($request->url(), 'IndexId=idx-1')
            && str_contains($request->url(), 'DocumentIds.1=file-99'));
    }

    public function test_push_document_fails_gracefully_when_lease_rejected(): void
    {
        Http::fake([
            'bailian.cn-beijing.aliyuncs.com/*' => Http::response(['Success' => false, 'Message' => 'quota exceeded'], 200),
        ]);

        $result = $this->provider->pushDocument('doc', 'content');

        $this->assertFalse($result['success']);
        $this->assertNull($result['external_id']);
        $this->assertSame('quota exceeded', $result['message']);
    }
}
