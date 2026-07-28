<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Coupon\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Coupon\Services\CouponService;

class CouponDeactivateHandler implements ToolHandlerContract
{
    public function __construct(private readonly CouponService $service) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->service->deactivate((int) $arguments['coupon_id']);
    }
}
