<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Product\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Product\Services\ProductService;

class ProductListHandler implements ToolHandlerContract
{
    public function __construct(private readonly ProductService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getProducts(
            $tenantId,
            $arguments['status'] ?? null,
            $arguments['category_id'] ?? null,
            $arguments['per_page'] ?? 20,
        );
    }
}
