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
            'is_active' => true,
            'joined_at' => now(),
        ];
    }
}
