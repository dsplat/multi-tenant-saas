<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;

/**
 * @extends Factory<TenantUser>
 */
class TenantUserFactory extends Factory
{
    protected $model = TenantUser::class;

    public function definition(): array
    {
        return [
            'role' => 'end_user',
            'is_active' => true,
            'joined_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'tenant_admin']);
    }

    public function endUser(): static
    {
        return $this->state(fn () => ['role' => 'end_user']);
    }
}
