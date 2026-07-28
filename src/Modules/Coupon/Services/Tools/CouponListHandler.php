<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Coupon\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Coupon\Services\CouponService;

class CouponListHandler implements ToolHandlerContract
{
    public function __construct(private readonly CouponService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->getCoupons(array_filter([
            'status' => $arguments['status'] ?? null,
            'type' => $arguments['type'] ?? null,
        ]));
    }
}
