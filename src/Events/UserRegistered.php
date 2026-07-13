<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MultiTenantSaas\Modules\Auth\Models\User;

class UserRegistered
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?string $tenantId = null
    ) {}
}
