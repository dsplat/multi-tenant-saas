<?php

namespace MultiTenantSaas\Modules\Knowledge\Services\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Knowledge\Contracts\ExternalKbProviderContract;

class RagFlowProvider implements ExternalKbProviderContract
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
     * 调用 RAGFlow 检索 API
     */
    public function search(string $query, int $limit = 10): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->apiUrl . '/api/v1/retrieval', [
            'question' => $query,
            'dataset_ids' => [$this->datasetId],
            'top_k' => $limit,
        ]);

        if ($response->failed()) {
            Log::error('RagFlowProvider::search failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $chunks = $response->json()['data']['chunks'] ?? [];

        return array_map(fn (array $chunk) => [
            'content' => $chunk['content'] ?? '',
            'score' => $chunk['similarity'] ?? 0,
            'document_id' => $chunk['document_id'] ?? '',
            'document_name' => $chunk['document_keyword'] ?? '',
            'metadata' => [
                'chunk_id' => $chunk['id'] ?? '',
            ],
        ], $chunks);
    }

    public function test(): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
                ->get($this->apiUrl . '/api/v1/datasets', ['page_size' => 1]);

            return $response->successful()
                ? ['success' => true, 'message' => trans('common.connection_success')]
                : ['success' => false, 'message' => "HTTP {$response->status()}"];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
