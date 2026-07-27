<?php

namespace MultiTenantSaas\Modules\Knowledge\Contracts;

/**
 * 外部知识库 Provider 契约
 *
 * config 约定键：api_url、api_key、dataset_id（各 Provider 可扩展自有键）。
 */
interface ExternalKbProviderContract
{
    /**
     * 配置连接参数
     */
    public function configure(array $config): void;

    /**
     * 检索知识库
     *
     * @return array<int, array{content: string, score: float|int, document_id: string, document_name: string, metadata: array}>
     */
    public function search(string $query, int $limit = 10): array;

    /**
     * 测试连接可用性
     *
     * @return array{success: bool, message: string}
     */
    public function test(): array;
}
