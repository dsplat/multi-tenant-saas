<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use MultiTenantSaas\Modules\Operator\Database\Seeders\PlatformInitSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformInitSeeder::class,
        ]);
    }
}
