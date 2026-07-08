<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\TenantMemberService;

class TenantMemberServiceTest extends TestCase
{
    public function test_service_exists(): void
    {
        $this->assertInstanceOf(TenantMemberService::class, app(TenantMemberService::class));
    }
}
