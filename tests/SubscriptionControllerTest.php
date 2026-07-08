<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\SubscriptionService;

class SubscriptionControllerTest extends TestCase
{
    // ========== 订阅服务测试 ==========

    public function test_subscription_service_exists(): void
    {
        $service = app(SubscriptionService::class);

        $this->assertInstanceOf(SubscriptionService::class, $service);
    }
}
