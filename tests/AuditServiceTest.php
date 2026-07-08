<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Tests\Schema\MfaModule;

class AuditServiceTest extends TestCase
{
    protected array $uses = [MfaModule::class];

    public function test_service_exists(): void
    {
        $this->assertInstanceOf(AuditService::class, app(AuditService::class));
    }

    public function test_log_creates_entry(): void
    {
        $log = AuditService::log('test_action', 'test_resource', 123, null, ['key' => 'value']);
        $this->assertNotNull($log->log_id);
        $this->assertEquals('test_action', $log->action);
    }
}
