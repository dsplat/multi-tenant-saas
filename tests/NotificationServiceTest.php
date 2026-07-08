<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\NotificationService;

class NotificationServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(NotificationService::class, app(NotificationService::class));
    }
}
