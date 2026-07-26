<?php

namespace MultiTenantSaas\Tests;

use Laravel\Sanctum\PersonalAccessToken;
use MultiTenantSaas\Modules\Auth\Models\User;

/**
 * 滑动续期测试：InfrastructureServiceProvider 监听 TokenAuthenticated 事件，
 * token created_at 超过 10 分钟时刷新，使活跃会话不受 sanctum.expiration 固定窗口限制。
 */
class TokenSlidingRenewalTest extends TestCase
{
    private function createUserWithToken(): array
    {
        $user = User::create([
            'name' => 'Renewal User',
            'email' => 'renewal@example.com',
            'password' => bcrypt('password123'),
            'role' => 'platform_user',
            'email_verified_at' => now(),
        ]);

        $plainToken = $user->createToken('test-token')->plainTextToken;
        $tokenModel = PersonalAccessToken::findToken($plainToken);

        return [$user, $plainToken, $tokenModel];
    }

    public function test_stale_token_created_at_is_refreshed_on_request(): void
    {
        [, $plainToken, $tokenModel] = $this->createUserWithToken();

        // 回拨 15 分钟（超过 10 分钟节流阈值，未超 30 分钟过期窗口）
        $tokenModel->forceFill(['created_at' => now()->subMinutes(15)])->save();

        $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200);

        $tokenModel->refresh();
        $this->assertTrue(
            $tokenModel->created_at->gt(now()->subMinute()),
            'stale token created_at should be refreshed to now'
        );
    }

    public function test_fresh_token_created_at_is_not_refreshed(): void
    {
        [, $plainToken, $tokenModel] = $this->createUserWithToken();

        // 回拨 5 分钟（未达 10 分钟阈值，节流不落库）
        $original = now()->subMinutes(5)->startOfSecond();
        $tokenModel->forceFill(['created_at' => $original])->save();

        $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200);

        $tokenModel->refresh();
        $this->assertSame(
            $original->timestamp,
            $tokenModel->created_at->timestamp,
            'fresh token created_at should stay unchanged'
        );
    }

    public function test_expired_token_is_rejected_and_not_resurrected(): void
    {
        config(['sanctum.expiration' => 30]);

        [, $plainToken, $tokenModel] = $this->createUserWithToken();

        // 回拨 45 分钟（超过 30 分钟过期窗口）：应 401，且监听器不得复活过期 token
        $expiredAt = now()->subMinutes(45)->startOfSecond();
        $tokenModel->forceFill(['created_at' => $expiredAt])->save();

        $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $tokenModel->refresh();
        $this->assertSame(
            $expiredAt->timestamp,
            $tokenModel->created_at->timestamp,
            'expired token created_at must not be refreshed'
        );
    }
}
