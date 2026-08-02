<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Modules\Auth\Models\User;

class UserProfileControllerTest extends TestCase
{
    private function createUser(): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'role' => 'platform_user',
        ]);
    }

    // ========== 头像上传 ==========

    public function test_upload_avatar_success(): void
    {
        Storage::fake('public');
        $user = $this->createUser();

        $response = $this->actingAs($user)
            ->post('/api/v1/auth/profile/avatar', [
                'avatar' => UploadedFile::fake()->image('avatar.png', 128, 128),
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['avatar']]);

        // 文件按 user_id 固定命名存入 public 磁盘
        Storage::disk('public')->assertExists("avatars/{$user->user_id}.png");
        $this->assertNotNull($user->fresh()->avatar);
    }

    public function test_upload_avatar_replaces_previous(): void
    {
        Storage::fake('public');
        $user = $this->createUser();

        $this->actingAs($user)->post('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('first.png', 64, 64),
        ]);
        $this->actingAs($user)->post('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg', 64, 64),
        ]);

        // 固定文件名覆盖：目录内只应有一个头像文件
        $files = Storage::disk('public')->files('avatars');
        $this->assertCount(1, $files);
    }

    public function test_upload_avatar_rejects_non_image(): void
    {
        Storage::fake('public');
        $user = $this->createUser();

        $response = $this->actingAs($user)
            ->post('/api/v1/auth/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        Storage::disk('public')->assertMissing("avatars/{$user->user_id}.pdf");
    }

    public function test_upload_avatar_requires_auth(): void
    {
        $response = $this->post('/api/v1/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 64, 64),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401);
    }

    // ========== 资料更新 ==========

    public function test_update_profile_with_avatar_url(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)
            ->putJson('/api/v1/auth/profile', [
                'name' => 'New Name',
                'avatar' => 'https://example.com/avatar.png',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['name' => 'New Name', 'avatar' => 'https://example.com/avatar.png']]);
    }
}
