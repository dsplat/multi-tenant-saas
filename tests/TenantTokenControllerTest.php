<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\UserApiToken;

class TenantTokenControllerTest extends TestCase
{
    public function test_create_token(): void
    {
        $user = User::create([
            'name' => 'Token User',
            'email' => 'token@example.com',
            'password' => bcrypt('password'),
            'role' => 'end_user',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $this->assertNotEmpty($token);
        $this->assertStringContainsString('|', $token);
    }

    public function test_token_has_abilities(): void
    {
        $user = User::create([
            'name' => 'Token User 2',
            'email' => 'token2@example.com',
            'password' => bcrypt('password'),
            'role' => 'end_user',
        ]);

        $token = $user->createToken('test-token', ['tenant.view', 'member.view'])->plainTextToken;

        $this->assertNotEmpty($token);
    }
}
