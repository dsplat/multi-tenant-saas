<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\InAppNotificationService;
use MultiTenantSaas\Services\NotificationService;

class NotificationControllerTest extends TestCase
{
    public function test_notification_service_exists(): void
    {
        $this->assertInstanceOf(NotificationService::class, app(NotificationService::class));
    }

    public function test_in_app_notification_service_exists(): void
    {
        $this->assertInstanceOf(InAppNotificationService::class, app(InAppNotificationService::class));
    }
}
