<?php

namespace MultiTenantSaas\Modules\Knowledge\Services\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Knowledge\Contracts\ExternalKbProviderContract;

/**
 * 阿里云百炼（Model Studio）知识库 Provider
 *
 * 与 Dify 等 Bearer 鉴权不同，百炼走阿里云 OpenAPI（产品线 bailian，版本 2023-12-29），
 * 鉴权为 AccessKey + ACS3-HMAC-SHA256 签名（本类自实现，不依赖官方 SDK）。
 *
 * config 键约定：
 * - api_url        endpoint（默认 https://bailian.cn-beijing.aliyuncs.com）
 * - api_key        AccessKey Secret（加密存储于连接密钥字段）
 * - access_key_id  AccessKey ID
 * - workspace_id   业务空间 ID
 * - index_id       知识库（索引）ID
 * - category_id    数据中心类目 ID（可选，默认 default，仅推送文档时使用）
 */
class BailianProvider implements ExternalKbProviderContract
{
    protected const API_VERSION = '2023-12-29';

    protected const DEFAULT_ENDPOINT = 'https://bailian.cn-beijing.aliyuncs.com';

    protected string $endpoint = self::DEFAULT_ENDPOINT;

    protected string $accessKeyId = '';

    protected string $accessKeySecret = '';

    protected string $workspaceId = '';

    protected string $indexId = '';

    protected string $categoryId = 'default';

    public function configure(array $config): void
    {
        $this->endpoint = rtrim($config['api_url'] ?? '', '/') ?: self::DEFAULT_ENDPOINT;
        $this->accessKeyId = $config['access_key_id'] ?? '';
        $this->accessKeySecret = $config['api_key'] ?? '';
        $this->workspaceId = $config['workspace_id'] ?? '';
        $this->indexId = $config['index_id'] ?? '';
        $this->categoryId = ($config['category_id'] ?? '') ?: 'default';
    }

    /**
     * 调用百炼 Retrieve API 检索知识库
     */
    public function search(string $query, int $limit = 10): array
    {
        $response = $this->request('POST', "/{$this->workspaceId}/index/retrieve", [
            'IndexId' => $this->indexId,
            'Query' => $query,
            'RerankTopN' => (string) min(max($limit, 1), 20),
        ]);

        if ($response->failed() || $response->json('Success') !== true) {
            Log::error('BailianProvider::search failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $nodes = $response->json('Data.Nodes') ?? [];

        return array_map(function (array $node) {
            $metadata = $node['Metadata'] ?? [];
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?: [];
            }

            return [
                'content' => $node['Text'] ?? '',
                'score' => $node['Score'] ?? 0,
                'document_id' => $metadata['doc_id'] ?? '',
                'document_name' => $metadata['doc_name'] ?? ($metadata['title'] ?? ''),
                'metadata' => [
                    'title' => $metadata['title'] ?? '',
                    'workspace_id' => $this->workspaceId,
                ],
            ];
        }, $nodes);
    }

    public function test(): array
    {
        try {
            $response = $this->request('GET', "/{$this->workspaceId}/index/list_indices", [
                'PageSize' => '1',
            ], [], 10);

            if ($response->successful() && $response->json('Success') === true) {
                return ['success' => true, 'message' => trans('common.connection_success')];
            }

            return ['success' => false, 'message' => $response->json('Message') ?? "HTTP {$response->status()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 推送文本文档到百炼知识库
     *
     * 四步链路：申请上传租约 → 上传临时存储 → AddFile 入类目 → 追加进知识库索引
     */
    public function pushDocument(string $name, string $content): array
    {
        try {
            $fileName = str_ends_with($name, '.txt') ? $name : "{$name}.txt";

            // 1. 申请上传租约
            $lease = $this->request('POST', "/{$this->workspaceId}/datacenter/category/{$this->categoryId}", [], [
                'FileName' => $fileName,
                'Md5' => md5($content),
                'SizeInBytes' => (string) strlen($content),
            ]);

            if ($lease->failed() || ! in_array($lease->json('Success'), [true, 'true'], true)) {
                return $this->pushFailure('apply lease', $lease);
            }

            $leaseId = $lease->json('Data.FileUploadLeaseId');
            $param = $lease->json('Data.Param') ?? [];
            $uploadHeaders = $param['Headers'] ?? [];
            if (is_string($uploadHeaders)) {
                $uploadHeaders = json_decode($uploadHeaders, true) ?: [];
            }

            // 2. 上传文件内容到临时存储（预签名 URL）
            $upload = Http::timeout(60)
                ->withHeaders($uploadHeaders)
                ->withBody($content, $uploadHeaders['Content-Type'] ?? 'text/plain')
                ->send($param['Method'] ?? 'PUT', $param['Url'] ?? '');

            if ($upload->failed()) {
                return $this->pushFailure('upload', $upload);
            }

            // 3. 添加文件到类目（解析器按类目配置自动选择）
            $addFile = $this->request('PUT', "/{$this->workspaceId}/datacenter/file", [], [
                'LeaseId' => $leaseId,
                'Parser' => 'AUTO_SELECT',
                'CategoryId' => $this->categoryId,
            ]);

            $fileId = $addFile->json('Data.FileId');
            if ($addFile->failed() || ! $fileId) {
                return $this->pushFailure('add file', $addFile);
            }

            // 4. 提交知识库追加任务（异步索引）
            $submit = $this->request('POST', "/{$this->workspaceId}/index/add_documents_to_index", [
                'IndexId' => $this->indexId,
                'SourceType' => 'DATA_CENTER_FILE',
                'DocumentIds.1' => $fileId,
            ]);

            if ($submit->failed() || $submit->json('Success') !== true) {
                return $this->pushFailure('submit index job', $submit);
            }

            return ['success' => true, 'message' => 'ok', 'external_id' => (string) $fileId];
        } catch (\Exception $e) {
            Log::error('BailianProvider::pushDocument exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage(), 'external_id' => null];
        }
    }

    protected function pushFailure(string $step, Response $response): array
    {
        Log::error("BailianProvider::pushDocument failed at {$step}", [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return [
            'success' => false,
            'message' => $response->json('Message') ?? "{$step}: HTTP {$response->status()}",
            'external_id' => null,
        ];
    }

    /**
     * 发送 ACS3-HMAC-SHA256 签名请求（阿里云 OpenAPI V3 签名规范）
     */
    protected function request(string $method, string $path, array $query = [], array $form = [], int $timeout = 30): Response
    {
        $method = strtoupper($method);
        $host = parse_url($this->endpoint, PHP_URL_HOST) ?: $this->endpoint;
        $body = $form === [] ? '' : http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $hashedBody = hash('sha256', $body);

        $headers = [
            'host' => $host,
            'x-acs-content-sha256' => $hashedBody,
            'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'),
            'x-acs-signature-nonce' => bin2hex(random_bytes(16)),
            'x-acs-version' => self::API_VERSION,
        ];
        ksort($headers);

        ksort($query);
        $canonicalQuery = implode('&', array_map(
            fn ($key) => $this->percentEncode($key) . '=' . $this->percentEncode((string) $query[$key]),
            array_keys($query)
        ));

        $canonicalUri = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        $canonicalHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= $key . ':' . trim($value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = "{$method}\n{$canonicalUri}\n{$canonicalQuery}\n{$canonicalHeaders}\n{$signedHeaders}\n{$hashedBody}";
        $stringToSign = "ACS3-HMAC-SHA256\n" . hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, $this->accessKeySecret);

        $headers['Authorization'] = "ACS3-HMAC-SHA256 Credential={$this->accessKeyId},SignedHeaders={$signedHeaders},Signature={$signature}";

        $url = $this->endpoint . $canonicalUri . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');

        $pending = Http::timeout($timeout)->withHeaders($headers);
        if ($body !== '') {
            $pending = $pending->withBody($body, 'application/x-www-form-urlencoded');
        }

        return $pending->send($method, $url);
    }

    /**
     * RFC3986 编码（阿里云签名规范：~ 不编码）
     */
    protected function percentEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }
}
