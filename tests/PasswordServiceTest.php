<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\PasswordService;

class PasswordServiceTest extends TestCase
{
    protected PasswordService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PasswordService::class);

        // 创建 password_histories 表（测试环境）
        if (! Schema::hasTable('password_histories')) {
            Schema::create('password_histories', function ($table) {
                $table->id('password_history_id');
                $table->unsignedBigInteger('user_id');
                $table->string('password_hash');
                $table->timestamps();
            });
        }

        $this->user = User::create([
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => bcrypt('oldpassword'),
            'role' => 'end_user',
        ]);
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(PasswordService::class, $this->service);
    }

    public function test_change_password_with_correct_old(): void
    {
        $result = $this->service->changePassword($this->user, 'oldpassword', 'newpassword123');
        $this->assertTrue($result);
    }

    public function test_change_password_with_wrong_old_fails(): void
    {
        $result = $this->service->changePassword($this->user, 'wrongold', 'newpassword123');
        $this->assertFalse($result);
    }

    public function test_reset_password_succeeds(): void
    {
        $result = $this->service->resetPassword($this->user, 'newpassword123');
        $this->assertTrue($result);
    }
}
