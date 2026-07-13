<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Facades\Date;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\PasswordHistory;
use MultiTenantSaas\Modules\Auth\Models\User;

/**
 * 密码策略服务
 *
 * 功能：
 *  1. 最小长度与复杂度（大写/小写/数字/特殊字符）
 *  2. 密码过期天数
 *  3. 历史禁止重复（最近 N 次）
 *  4. 暴力破解锁定（N 次失败锁定 M 分钟）
 *  5. 密码强度评分（0-100）
 *
 * 策略默认值通过类常量定义，可通过 config('auth.password_policy.*') 覆盖。
 */
class PasswordPolicyService
{
    /** 最小密码长度 */
    private const DEFAULT_MIN_LENGTH = 8;

    /** 最大密码长度（防止 bcrypt DoS） */
    private const MAX_LENGTH = 72;

    /** 要求大写字母 */
    private const DEFAULT_REQUIRE_UPPER = true;

    /** 要求小写字母 */
    private const DEFAULT_REQUIRE_LOWER = true;

    /** 要求数字 */
    private const DEFAULT_REQUIRE_DIGIT = true;

    /** 要求特殊字符 */
    private const DEFAULT_REQUIRE_SPECIAL = true;

    /** 密码过期天数（0 表示不过期） */
    private const DEFAULT_EXPIRE_DAYS = 90;

    /** 历史禁止重复次数 */
    private const DEFAULT_HISTORY_COUNT = 5;

    /** 触发锁定的失败次数 */
    private const DEFAULT_MAX_LOGIN_ATTEMPTS = 5;

    /** 锁定时长（分钟） */
    private const DEFAULT_LOCK_MINUTES = 15;

    /**
     * 综合校验密码（策略 + 历史）
     *
     * @return array{valid: bool, score: int, errors: array<int,string>}
     */
    public function validate(User $user, string $newPassword): array
    {
        $policy = $this->validatePolicy($newPassword);
        $errors = $policy['errors'];
        $score = $policy['score'];

        if ($this->isInHistory($user->user_id, $newPassword)) {
            $errors[] = trans('auth.password_in_history');
        }

        return [
            'valid' => $errors === [],
            'score' => $score,
            'errors' => $errors,
        ];
    }

    /**
     * 校验密码策略（长度/复杂度），并计算强度评分
     *
     * @return array{valid: bool, score: int, errors: array<int,string>}
     */
    public function validatePolicy(string $password): array
    {
        $errors = [];

        $minLength = (int) $this->config('min_length', self::DEFAULT_MIN_LENGTH);
        if (mb_strlen($password) < $minLength) {
            $errors[] = trans('auth.password_too_short', ['min' => $minLength]);
        }

        if (mb_strlen($password) > self::MAX_LENGTH) {
            $errors[] = trans('auth.password_too_long', ['max' => self::MAX_LENGTH]);
        }

        if ($this->config('require_upper', self::DEFAULT_REQUIRE_UPPER) && ! preg_match('/[A-Z]/', $password)) {
            $errors[] = trans('auth.password_requires_upper');
        }

        if ($this->config('require_lower', self::DEFAULT_REQUIRE_LOWER) && ! preg_match('/[a-z]/', $password)) {
            $errors[] = trans('auth.password_requires_lower');
        }

        if ($this->config('require_digit', self::DEFAULT_REQUIRE_DIGIT) && ! preg_match('/[0-9]/', $password)) {
            $errors[] = trans('auth.password_requires_digit');
        }

        if ($this->config('require_special', self::DEFAULT_REQUIRE_SPECIAL) && ! preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = trans('auth.password_requires_special');
        }

        return [
            'valid' => $errors === [],
            'score' => $this->scorePassword($password),
            'errors' => $errors,
        ];
    }

    /**
     * 密码强度评分（0-100）
     *
     * 评分维度：长度、字符种类多样性、是否包含常见弱密码特征
     */
    public function scorePassword(string $password): int
    {
        $length = mb_strlen($password);
        $variety = 0;
        if (preg_match('/[a-z]/', $password)) {
            $variety++;
        }
        if (preg_match('/[A-Z]/', $password)) {
            $variety++;
        }
        if (preg_match('/[0-9]/', $password)) {
            $variety++;
        }
        if (preg_match('/[^A-Za-z0-9]/', $password)) {
            $variety++;
        }

        // 长度分（最高 50）
        $lengthScore = min(50, $length * 4);

        // 多样性分（最高 30）
        $varietyScore = $variety * 8;

        // 基础分
        $score = $lengthScore + $varietyScore;

        // 惩罚：纯字母 / 纯数字
        if (preg_match('/^[A-Za-z]+$/', $password) || preg_match('/^[0-9]+$/', $password)) {
            $score = (int) ($score * 0.5);
        }

        // 惩罚：常见弱密码
        if ($this->isCommonWeakPassword($password)) {
            $score = min($score, 20);
        }

        return max(0, min(100, $score));
    }

    /**
     * 密码是否已过期
     */
    public function isPasswordExpired(User $user): bool
    {
        $expireDays = (int) $this->config('expire_days', self::DEFAULT_EXPIRE_DAYS);
        if ($expireDays <= 0) {
            return false;
        }

        $changedAt = $user->password_changed_at;

        // 兼容历史数据：未记录修改时间则取 email_verified_at 或创建时间
        if (! $changedAt) {
            $changedAt = $user->email_verified_at ?: $user->created_at;
        }

        if (! $changedAt) {
            return false;
        }

        return Date::parse($changedAt)->diffInDays(now()) >= $expireDays;
    }

    /**
     * 密码是否在最近历史中重复
     */
    public function isInHistory(int $userId, string $password): bool
    {
        $limit = (int) $this->config('history_count', self::DEFAULT_HISTORY_COUNT);
        if ($limit <= 0) {
            return false;
        }

        $recent = PasswordHistory::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['password_hash']);

        foreach ($recent as $record) {
            if (password_verify($password, $record->password_hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 记录密码历史（存储 hash）
     *
     * 仅存储 bcrypt hash，永不存储明文。
     */
    public function recordPasswordHistory(int $userId, string $passwordHash): void
    {
        PasswordHistory::create([
            'tenant_id' => TenantContext::getId(),
            'user_id' => $userId,
            'password_hash' => $passwordHash,
        ]);
    }

    /**
     * 修改密码：校验策略 + 记录历史 + 更新时间戳 + 重置锁定状态
     *
     * @return array{valid: bool, score: int, errors: array<int,string>}
     */
    public function changePassword(User $user, string $newPassword): array
    {
        $result = $this->validate($user, $newPassword);

        if (! $result['valid']) {
            return $result;
        }

        // 记录旧密码到历史（避免存储未变更的相同密码）
        $currentHash = $user->getRawOriginal('password');
        if ($currentHash !== null && ! password_verify($newPassword, $currentHash)) {
            $this->recordPasswordHistory($user->user_id, $currentHash);
        }

        // 设置新密码（'hashed' cast 自动加密）
        $user->password = $newPassword;
        $user->password_changed_at = now();
        $user->login_attempts = 0;
        $user->locked_until = null;
        $user->save();

        return $result;
    }

    /**
     * 记录一次登录失败，达到阈值后锁定账号
     */
    public function recordFailedLogin(User $user): void
    {
        $maxAttempts = (int) $this->config('max_login_attempts', self::DEFAULT_MAX_LOGIN_ATTEMPTS);
        $lockMinutes = (int) $this->config('lock_minutes', self::DEFAULT_LOCK_MINUTES);

        $user->increment('login_attempts');
        $user->refresh();

        if ($user->login_attempts >= $maxAttempts) {
            $user->locked_until = now()->addMinutes($lockMinutes);
            $user->save();
        }
    }

    /**
     * 账号是否已被锁定
     */
    public function isLocked(User $user): bool
    {
        $lockedUntil = $user->locked_until;

        return $lockedUntil !== null && Date::parse($lockedUntil)->isFuture();
    }

    /**
     * 锁定剩余秒数（未锁定或已过期返回 0）
     */
    public function getLockRemainingSeconds(User $user): int
    {
        $lockedUntil = $user->locked_until;
        if (! $lockedUntil) {
            return 0;
        }

        $remaining = Date::parse($lockedUntil)->getTimestamp() - now()->getTimestamp();

        return max(0, $remaining);
    }

    /**
     * 重置登录失败计数（登录成功或解锁后调用）
     */
    public function resetLoginAttempts(User $user): void
    {
        if ($user->login_attempts === 0 && $user->locked_until === null) {
            return;
        }

        $user->login_attempts = 0;
        $user->locked_until = null;
        $user->save();
    }

    /**
     * 记录成功登录（重置失败计数 + 更新最后活跃时间由调用方处理）
     */
    public function recordSuccessfulLogin(User $user): void
    {
        $this->resetLoginAttempts($user);
    }

    /**
     * 手动解锁账号
     */
    public function unlock(User $user): void
    {
        $user->login_attempts = 0;
        $user->locked_until = null;
        $user->save();
    }

    // ----------------------------------------
    // 私有辅助方法
    // ----------------------------------------

    /**
     * 读取策略配置（带默认值）
     */
    private function config(string $key, mixed $default): mixed
    {
        return config("auth.password_policy.{$key}", $default);
    }

    /**
     * 是否为常见弱密码
     */
    private function isCommonWeakPassword(string $password): bool
    {
        $weak = ['password', '123456', '12345678', 'qwerty', 'admin', 'letmein', 'welcome', '111111', '000000'];

        return in_array(strtolower($password), $weak, true);
    }
}
