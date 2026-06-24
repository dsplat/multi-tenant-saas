<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MultiTenantSaas\Models\Tenant;

class TenantActivated
{
    use Dispatchable;

    public function __construct(
        public Tenant $tenant
    ) {}
}
