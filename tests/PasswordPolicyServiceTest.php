<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\PasswordPolicyService;

/**
 * TASK-016 PasswordPolicyService 单元测试
 *
 * 覆盖：长度/复杂度校验、强度评分、密码过期、历史禁止重复、暴力破解锁定
 */
class PasswordPolicyServiceTest extends TestCase
{
    private PasswordPolicyService $service;

    private int $userId = 3001;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        User::unguarded(function () {
            User::create([
                'user_id' => $this->userId,
                'name' => 'Bob',
                'email' => 'bob@example.com',
                'phone' => '13900139000',
                'password' => bcrypt('OldPass123!'),
            ]);
        });

        TenantContext::setTenantId('1001');

        $this->service = app(PasswordPolicyService::class);
    }

    // ---------- 长度 / 复杂度 ----------

    public function test_short_password_fails_policy(): void
    {
        $result = $this->service->validatePolicy('Ab1!');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_password_missing_uppercase_fails(): void
    {
        $result = $this->service->validatePolicy('pass1234!');

        $this->assertFalse($result['valid']);
    }

    public function test_password_missing_digit_fails(): void
    {
        $result = $this->service->validatePolicy('PassWord!');

        $this->assertFalse($result['valid']);
    }

    public function test_password_missing_special_fails(): void
    {
        $result = $this->service->validatePolicy('Pass1234');

        $this->assertFalse($result['valid']);
    }

    public function test_strong_password_passes_policy(): void
    {
        $result = $this->service->validatePolicy('Str0ng!Pass#2024');

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_policy_respects_config_min_length(): void
    {
        Config::set('auth.password_policy.min_length', 12);

        $result = $this->service->validatePolicy('Short1!ab');

        $this->assertFalse($result['valid']);
    }

    // ---------- 强度评分 ----------

    public function test_weak_password_scores_low(): void
    {
        $this->assertLessThan(40, $this->service->scorePassword('password'));
    }

    public function test_strong_password_scores_high(): void
    {
        $this->assertGreaterThan(60, $this->service->scorePassword('Str0ng!Pass#2024'));
    }

    public function test_common_weak_password_is_capped(): void
    {
        $this->assertLessThanOrEqual(20, $this->service->scorePassword('password'));
        $this->assertLessThanOrEqual(20, $this->service->scorePassword('123456'));
    }

    public function test_alpha_only_password_is_penalized(): void
    {
        $this->assertLessThan(
            $this->service->scorePassword('Abcdefgh1!'),
            $this->service->scorePassword('abcdefghij')
        );
    }

    // ---------- 密码过期 ----------

    public function test_password_not_expired_when_recently_changed(): void
    {
        $user = $this->user();
        $user->password_changed_at = now()->subDays(10);
        $user->save();

        $this->assertFalse($this->service->isPasswordExpired($user));
    }

    public function test_password_expired_after_threshold(): void
    {
        $user = $this->user();
        $user->password_changed_at = now()->subDays(100);
        $user->save();

        $this->assertTrue($this->service->isPasswordExpired($user));
    }

    public function test_password_expiry_disabled_when_zero(): void
    {
        Config::set('auth.password_policy.expire_days', 0);

        $user = $this->user();
        $user->password_changed_at = now()->subDays(3650);
        $user->save();

        $this->assertFalse($this->service->isPasswordExpired($user));
    }

    // ---------- 历史禁止重复 ----------

    public function test_password_history_records_and_detects_repeat(): void
    {
        $user = $this->user();

        $this->service->recordPasswordHistory($user->user_id, bcrypt('History1!'));
        $this->service->recordPasswordHistory($user->user_id, bcrypt('History2!'));

        $this->assertTrue($this->service->isInHistory($user->user_id, 'History1!'));
        $this->assertTrue($this->service->isInHistory($user->user_id, 'History2!'));
        $this->assertFalse($this->service->isInHistory($user->user_id, 'NeverUsed!'));
    }

    public function test_history_respects_count_limit(): void
    {
        Config::set('auth.password_policy.history_count', 2);

        $user = $this->user();

        // 使用不同时间戳，确保 ORDER BY created_at DESC 的顺序确定
        $this->withFixedTime(now()->subDays(3), fn () => $this->service->recordPasswordHistory($user->user_id, bcrypt('Old1!')));
        $this->withFixedTime(now()->subDays(2), fn () => $this->service->recordPasswordHistory($user->user_id, bcrypt('Old2!')));
        $this->withFixedTime(now()->subDays(1), fn () => $this->service->recordPasswordHistory($user->user_id, bcrypt('Old3!')));

        // 只校验最近 2 条（Old2!, Old3!），Old1! 应不再命中
        $this->assertFalse($this->service->isInHistory($user->user_id, 'Old1!'));
        $this->assertTrue($this->service->isInHistory($user->user_id, 'Old3!'));
    }

    // ---------- changePassword 综合流程 ----------

    public function test_change_password_rejects_weak_new_password(): void
    {
        $user = $this->user();
        $result = $this->service->changePassword($user, 'weak');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_change_password_succeeds_and_records_history(): void
    {
        $user = $this->user();

        $result = $this->service->changePassword($user, 'NewStr0ng!Pass');

        $this->assertTrue($result['valid']);

        // 重新加载，校验新密码生效
        $reloaded = User::find($user->user_id);
        $this->assertTrue(password_verify('NewStr0ng!Pass', $reloaded->password));
        $this->assertFalse(password_verify('OldPass123!', $reloaded->password));
        $this->assertNotNull($reloaded->password_changed_at);
        $this->assertSame(0, (int) $reloaded->login_attempts);

        // 旧密码应进入历史
        $this->assertTrue($this->service->isInHistory($user->user_id, 'OldPass123!'));
    }

    public function test_change_password_blocked_when_reusing_recent_password(): void
    {
        $user = $this->user();
        $this->service->recordPasswordHistory($user->user_id, bcrypt('Recent1!'));

        $result = $this->service->validate($user, 'Recent1!');

        $this->assertFalse($result['valid']);
    }

    // ---------- 暴力破解锁定 ----------

    public function test_failed_login_increments_attempts(): void
    {
        $user = $this->user();
        $this->service->recordFailedLogin($user);

        $this->assertSame(1, (int) User::find($user->user_id)->login_attempts);
    }

    public function test_account_locks_after_threshold(): void
    {
        Config::set('auth.password_policy.max_login_attempts', 3);
        Config::set('auth.password_policy.lock_minutes', 15);

        $user = $this->user();

        $this->service->recordFailedLogin($user);
        $this->service->recordFailedLogin($user);
        $this->assertFalse($this->service->isLocked($user));

        $this->service->recordFailedLogin($user); // 第 3 次触发锁定
        $reloaded = User::find($user->user_id);

        $this->assertTrue($this->service->isLocked($reloaded));
        $this->assertGreaterThan(0, $this->service->getLockRemainingSeconds($reloaded));
        $this->assertNotNull($reloaded->locked_until);
    }

    public function test_reset_login_attempts_clears_lock(): void
    {
        $user = $this->user();
        $user->login_attempts = 5;
        $user->locked_until = now()->addMinutes(10);
        $user->save();

        $this->service->resetLoginAttempts($user);

        $reloaded = User::find($user->user_id);
        $this->assertSame(0, (int) $reloaded->login_attempts);
        $this->assertNull($reloaded->locked_until);
        $this->assertFalse($this->service->isLocked($reloaded));
    }

    public function test_successful_login_resets_attempts(): void
    {
        $user = $this->user();
        $user->login_attempts = 2;
        $user->save();

        $this->service->recordSuccessfulLogin($user);

        $this->assertSame(0, (int) User::find($user->user_id)->login_attempts);
    }

    public function test_unlock_clears_lock_state(): void
    {
        $user = $this->user();
        $user->login_attempts = 5;
        $user->locked_until = now()->addMinutes(15);
        $user->save();

        $this->service->unlock($user);

        $reloaded = User::find($user->user_id);
        $this->assertSame(0, (int) $reloaded->login_attempts);
        $this->assertNull($reloaded->locked_until);
    }

    public function test_expired_lock_is_not_active(): void
    {
        $user = $this->user();
        $user->locked_until = now()->subMinute();
        $user->save();

        $this->assertFalse($this->service->isLocked($user));
        $this->assertSame(0, $this->service->getLockRemainingSeconds($user));
    }

    // ---------- 辅助 ----------

    private function user(): User
    {
        return User::find($this->userId);
    }

    /**
     * 在固定时间上下文中执行回调（用于控制 created_at 顺序）
     *
     * @param  callable(): void  $callback
     */
    private function withFixedTime($time, callable $callback): void
    {
        Carbon::setTestNow($time);
        try {
            $callback();
        } finally {
            Carbon::setTestNow();
        }
    }
}
