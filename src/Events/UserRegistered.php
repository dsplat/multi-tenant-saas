<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MultiTenantSaas\Models\User;

class UserRegistered
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public ?int $tenantId = null
    ) {}
}
