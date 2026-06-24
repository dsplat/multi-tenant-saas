<?php

namespace MultiTenantSaas\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MultiTenantSaas\Models\User;

class UserLoggedIn
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public string $ip
    ) {}
}
