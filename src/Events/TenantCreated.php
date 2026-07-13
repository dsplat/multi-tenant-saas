<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

class TenantCreated
{
    use Dispatchable;

    public function __construct(
        public Tenant $tenant
    ) {}
}
