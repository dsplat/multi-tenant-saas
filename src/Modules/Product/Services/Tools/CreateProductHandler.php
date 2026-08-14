<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Product\Services\ProductService;

class CreateProductHandler implements ToolHandlerContract
{
    public function __construct(private readonly ProductService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->create($tenantId, [
            'name' => $arguments['name'],
            'description' => $arguments['description'] ?? null,
            'category_id' => $arguments['category_id'] ?? null,
            'price' => $arguments['price'] ?? 0,
            'market_price' => $arguments['market_price'] ?? null,
            'stock' => $arguments['stock'] ?? 0,
            'status' => $arguments['status'] ?? 'draft',
            'type' => $arguments['type'] ?? null,
            'sale_mode' => $arguments['sale_mode'] ?? null,
        ]);
    }
}
