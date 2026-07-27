<?php

namespace MultiTenantSaas\Modules\Knowledge\Services\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Knowledge\Contracts\ExternalKbProviderContract;

class FastGptProvider implements ExternalKbProviderContract
{
    protected string $apiUrl = '';

    protected string $apiKey = '';

    protected string $datasetId = '';

    public function configure(array $config): void
    {
        $this->apiUrl = rtrim($config['api_url'] ?? '', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->datasetId = $config['dataset_id'] ?? '';
    }

    /**
     * 调用 FastGPT 检索 API
     */
    public function search(string $query, int $limit = 10): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl . '/api/core/dataset/searchTest', [
            'datasetId' => $this->datasetId,
            'text' => $query,
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            Log::error('FastGptProvider::search failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $list = $response->json()['list'] ?? [];

        return array_map(fn (array $item) => [
            'content' => $item['q'] ?? $item['a'] ?? '',
            'score' => $item['score'] ?? 0,
            'document_id' => $item['collectionId'] ?? '',
            'document_name' => $item['datasetName'] ?? '',
            'metadata' => [
                'id' => $item['id'] ?? '',
            ],
        ], $list);
    }

    public function test(): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                ->get($this->apiUrl . '/api/core/dataset/list');

            return $response->successful()
                ? ['success' => true, 'message' => trans('common.connection_success')]
                : ['success' => false, 'message' => "HTTP {$response->status()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
