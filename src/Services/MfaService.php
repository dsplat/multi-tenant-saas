<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Models\MfaDevice;
use MultiTenantSaas\Models\MfaRecoveryCode;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Modules\Sms\Services\SmsService;

/**
 * 多因素认证服务
 *
 * 支持：
 *  - TOTP（RFC 6238，兼容 Google Authenticator）
 *  - 邮箱验证码
 *  - 短信验证码
 *  - 恢复码生成/验证
 *  - MFA 设备管理（绑定/解绑/重命名/设为主设备）
 */
class MfaService
{
    /** Base32 字母表（RFC 4648） */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** TOTP 时间步长（秒） */
    private const TOTP_STEP = 30;

    /** TOTP 位数 */
    private const TOTP_DIGITS = 6;

    /** 验证码有效期（秒） */
    private const CODE_TTL = 300;

    /** 恢复码默认数量 */
    private const RECOVERY_CODE_COUNT = 10;

    /**
     * 生成 TOTP 密钥（Base32 编码）
     */
    public function generateTotpSecret(int $length = 20): string
    {
        return $this->base32Encode(random_bytes($length));
    }

    /**
     * 生成 otpauth:// URI（供前端渲染二维码）
     */
    public function getOtpauthUri(string $secret, string $label, string $issuer = 'MultiTenantSaas'): string
    {
        $labelEncoded = rawurlencode($label);
        $issuerEncoded = rawurlencode($issuer);

        return "otpauth://totp/{$issuerEncoded}:{$labelEncoded}?secret={$secret}&issuer={$issuerEncoded}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * 根据密钥与时间戳生成 TOTP
     */
    public function generateTotp(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, self::TOTP_STEP);
        $binary = $this->base32Decode($secret);
        $message = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $message, $binary, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            (ord($hash[$offset + 1]) << 16) |
            (ord($hash[$offset + 2]) << 8) |
            ord($hash[$offset + 3])
        );
        $otp = $truncated % (10 ** self::TOTP_DIGITS);

        return str_pad((string) $otp, self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 校验 TOTP 验证码（允许前后时间窗口）
     */
    public function verifyTotp(string $secret, string $code, int $window = 1): bool
    {
        if (strlen($code) !== self::TOTP_DIGITS || ! ctype_digit($code)) {
            return false;
        }

        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = $this->generateTotp($secret, $now + $i * self::TOTP_STEP);
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成邮箱验证码并缓存
     */
    public function generateEmailCode(int $userId): string
    {
        $code = $this->generateNumericCode();
        Cache::put($this->emailCacheKey($userId), $code, self::CODE_TTL);

        return $code;
    }

    /**
     * 发送邮箱验证码
     */
    public function sendEmailCode(int $userId): string
    {
        $code = $this->generateEmailCode($userId);
        $user = User::find($userId);

        if ($user && $user->email) {
            Mail::raw(trans('auth.mfa_email_body', ['code' => $code]), function ($message) use ($user) {
                $message->to($user->email)->subject(trans('auth.mfa_email_subject'));
            });
        }

        return $code;
    }

    /**
     * 校验邮箱验证码
     */
    public function verifyEmailCode(int $userId, string $code): bool
    {
        $stored = Cache::get($this->emailCacheKey($userId));

        if ($stored !== null && hash_equals((string) $stored, (string) $code)) {
            Cache::forget($this->emailCacheKey($userId));

            return true;
        }

        return false;
    }

    /**
     * 生成短信验证码并缓存
     */
    public function generateSmsCode(int $userId): string
    {
        $code = $this->generateNumericCode();
        Cache::put($this->smsCacheKey($userId), $code, self::CODE_TTL);

        return $code;
    }

    /**
     * 发送短信验证码
     */
    public function sendSmsCode(int $userId, ?string $phone = null): string
    {
        $code = $this->generateSmsCode($userId);
        $user = User::find($userId);
        $target = $phone ?: ($user->phone ?? null);

        if ($target) {
            SmsService::send($target, $code, 'mfa');
        }

        return $code;
    }

    /**
     * 校验短信验证码
     */
    public function verifySmsCode(int $userId, string $code): bool
    {
        $stored = Cache::get($this->smsCacheKey($userId));

        if ($stored !== null && hash_equals((string) $stored, (string) $code)) {
            Cache::forget($this->smsCacheKey($userId));

            return true;
        }

        return false;
    }

    /**
     * 生成恢复码（明文，仅在生成时返回一次）
     *
     * @return array<string>
     */
    public function generateRecoveryCodes(int $count = self::RECOVERY_CODE_COUNT): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateRecoveryCode();
        }

        return $codes;
    }

    /**
     * 持久化恢复码（hash 存储），并清除旧恢复码
     *
     * @param  array<string>  $plainCodes
     */
    public function storeRecoveryCodes(int $userId, array $plainCodes): void
    {
        MfaRecoveryCode::where('user_id', $userId)->delete();

        $tenantId = TenantContext::getId();
        $now = now();
        $generator = app(IdGeneratorContract::class);

        $rows = [];
        foreach ($plainCodes as $code) {
            $rows[] = [
                'recovery_code_id' => $generator->generate(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'code' => hash('sha256', strtolower($code)),
                'is_used' => false,
                'used_at' => null,
                'created_at' => $now,
            ];
        }

        MfaRecoveryCode::insert($rows);
    }

    /**
     * 生成并存储恢复码，返回明文（仅此一次）
     *
     * @return array<string>
     */
    public function regenerateRecoveryCodes(int $userId, int $count = self::RECOVERY_CODE_COUNT): array
    {
        $codes = $this->generateRecoveryCodes($count);
        $this->storeRecoveryCodes($userId, $codes);

        return $codes;
    }

    /**
     * 校验恢复码（命中即标记已使用）
     */
    public function verifyRecoveryCode(int $userId, string $code): bool
    {
        $hashed = hash('sha256', strtolower(trim($code)));

        /** @var MfaRecoveryCode|null $record */
        $record = MfaRecoveryCode::where('user_id', $userId)
            ->where('is_used', false)
            ->where('code', $hashed)
            ->first();

        if (! $record) {
            return false;
        }

        $record->is_used = true;
        $record->used_at = now();
        $record->save();

        return true;
    }

    /**
     * 查询恢复码使用情况（不返回明文）
     *
     * @return array{total: int, used: int, remaining: int}
     */
    public function getRecoveryCodeStatus(int $userId): array
    {
        $stats = MfaRecoveryCode::where('user_id', $userId)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_used THEN 1 ELSE 0 END) as used')
            ->first();

        $total = (int) ($stats->total ?? 0);
        $used = (int) ($stats->used ?? 0);

        return [
            'total' => $total,
            'used' => $used,
            'remaining' => $total - $used,
        ];
    }

    /**
     * 绑定 TOTP 设备（密钥由前端在 setup 阶段获取，此处持久化）
     */
    public function setupTotpDevice(int $userId, string $secret, string $label): MfaDevice
    {
        return $this->createDevice([
            'user_id' => $userId,
            'type' => 'totp',
            'secret' => $secret,
            'label' => $label,
            'is_primary' => ! $this->hasMfaEnabled($userId),
            'is_verified' => true,
        ]);
    }

    /**
     * 绑定邮箱验证码设备
     */
    public function setupEmailDevice(int $userId, string $label): MfaDevice
    {
        return $this->createDevice([
            'user_id' => $userId,
            'type' => 'email',
            'secret' => null,
            'label' => $label,
            'is_primary' => ! $this->hasMfaEnabled($userId),
            'is_verified' => true,
        ]);
    }

    /**
     * 绑定短信验证码设备
     */
    public function setupSmsDevice(int $userId, string $phone, string $label): MfaDevice
    {
        return $this->createDevice([
            'user_id' => $userId,
            'type' => 'sms',
            'secret' => $phone,
            'label' => $label,
            'is_primary' => ! $this->hasMfaEnabled($userId),
            'is_verified' => true,
        ]);
    }

    /**
     * 列出用户的所有 MFA 设备
     */
    public function listDevices(int $userId): Collection
    {
        return MfaDevice::where('user_id', $userId)->orderByDesc('is_primary')->orderByDesc('created_at')->get();
    }

    /**
     * 解绑 MFA 设备
     */
    public function deleteDevice(int $userId, int $deviceId): bool
    {
        $device = $this->findDevice($userId, $deviceId);

        if (! $device) {
            return false;
        }

        $wasPrimary = $device->is_primary;
        $device->delete();

        // 若删除的是主设备，自动提升首个剩余设备为主设备
        if ($wasPrimary) {
            $next = MfaDevice::where('user_id', $userId)->orderBy('created_at')->first();
            if ($next) {
                $next->is_primary = true;
                $next->save();
            }
        }

        return true;
    }

    /**
     * 重命名 MFA 设备
     */
    public function renameDevice(int $userId, int $deviceId, string $label): ?MfaDevice
    {
        $device = $this->findDevice($userId, $deviceId);
        if (! $device) {
            return null;
        }

        $device->label = $label;
        $device->save();

        return $device;
    }

    /**
     * 设置主设备
     */
    public function setPrimaryDevice(int $userId, int $deviceId): ?MfaDevice
    {
        $device = $this->findDevice($userId, $deviceId);
        if (! $device) {
            return null;
        }

        MfaDevice::where('user_id', $userId)->where('is_primary', true)->update(['is_primary' => false]);

        $device->is_primary = true;
        $device->save();

        return $device;
    }

    /**
     * 用户是否已启用 MFA
     */
    public function hasMfaEnabled(int $userId): bool
    {
        return MfaDevice::where('user_id', $userId)->where('is_verified', true)->exists();
    }

    /**
     * 获取用户可用的验证方式
     *
     * @return array<string>
     */
    public function getAvailableChallengeTypes(int $userId): array
    {
        $types = MfaDevice::where('user_id', $userId)
            ->where('is_verified', true)
            ->pluck('type')
            ->unique()
            ->values()
            ->all();

        if (MfaRecoveryCode::where('user_id', $userId)->where('is_used', false)->exists()) {
            $types[] = 'recovery';
        }

        return $types;
    }

    /**
     * 综合校验 MFA 验证码（登录时使用）
     */
    public function verifyChallenge(int $userId, string $code, string $type = 'totp'): bool
    {
        return match ($type) {
            'totp' => $this->verifyTotpChallenge($userId, $code),
            'email' => $this->verifyEmailCode($userId, $code),
            'sms' => $this->verifySmsCode($userId, $code),
            'recovery' => $this->verifyRecoveryCode($userId, $code),
            default => false,
        };
    }

    /**
     * 标记设备最后使用时间
     */
    public function touchDevice(int $userId, string $type): void
    {
        MfaDevice::where('user_id', $userId)
            ->where('type', $type)
            ->update(['last_used_at' => now()]);
    }

    // ----------------------------------------
    // 私有辅助方法
    // ----------------------------------------

    /**
     * 创建 MFA 设备记录
     */
    private function createDevice(array $attributes): MfaDevice
    {
        $attributes['tenant_id'] = $attributes['tenant_id'] ?? TenantContext::getId();

        return MfaDevice::create($attributes);
    }

    /**
     * 查找用户的指定设备
     */
    private function findDevice(int $userId, int $deviceId): ?MfaDevice
    {
        return MfaDevice::where('user_id', $userId)
            ->where('mfa_device_id', $deviceId)
            ->first();
    }

    /**
     * 校验 TOTP 挑战（尝试该用户所有 TOTP 设备）
     */
    private function verifyTotpChallenge(int $userId, string $code): bool
    {
        $devices = MfaDevice::where('user_id', $userId)
            ->where('type', 'totp')
            ->where('is_verified', true)
            ->get();

        foreach ($devices as $device) {
            if ($this->verifyTotp($device->secret, $code)) {
                $this->touchDevice($userId, 'totp');

                return true;
            }
        }

        return false;
    }

    /**
     * 生成 6 位数字验证码
     */
    private function generateNumericCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 生成单个恢复码（格式 XXXX-XXXX-XXXX）
     */
    private function generateRecoveryCode(): string
    {
        $hex = bin2hex(random_bytes(6));

        return strtoupper(substr($hex, 0, 4).'-'.substr($hex, 4, 4).'-'.substr($hex, 8, 4));
    }

    private function emailCacheKey(int $userId): string
    {
        return "mfa:email:{$userId}";
    }

    private function smsCacheKey(int $userId): string
    {
        return "mfa:sms:{$userId}";
    }

    /**
     * Base32 编码
     */
    private function base32Encode(string $binary): string
    {
        $bin = '';
        foreach (str_split($binary) as $char) {
            $bin .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bin, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= self::BASE32_ALPHABET[(int) bindec($chunk)];
        }

        while (strlen($out) % 8 !== 0) {
            $out .= '=';
        }

        return $out;
    }

    /**
     * Base32 解码
     */
    private function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));
        $bin = '';
        foreach (str_split($base32) as $char) {
            $idx = strpos(self::BASE32_ALPHABET, $char);
            if ($idx === false) {
                continue;
            }
            $bin .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bin, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
