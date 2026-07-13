<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\TrustedDevice;

/**
 * 信任设备服务
 *
 * 功能：
 *  - 设备信任（记住此设备 N 天免二次验证）
 *  - 设备指纹（IP + User-Agent 的 SHA256 哈希）
 *  - 信任设备列表管理
 *  - 信任到期自动失效
 */
class TrustedDeviceService
{
    /** 默认信任天数 */
    public const DEFAULT_TRUST_DAYS = 30;

    /**
     * 生成设备指纹（IP + User-Agent 的 SHA256）
     */
    public function generateFingerprint(string $ip, string $userAgent): string
    {
        return hash('sha256', $ip . '|' . $userAgent);
    }

    /**
     * 从请求生成设备指纹
     */
    public function fingerprintFromRequest(Request $request): string
    {
        return $this->generateFingerprint($request->ip() ?: '', $request->userAgent() ?: '');
    }

    /**
     * 信任当前设备
     *
     * @param  int  $userId  用户 ID
     * @param  Request  $request  当前请求
     * @param  int  $days  信任天数
     * @param  string|null  $deviceName  设备名称
     */
    public function trustCurrentDevice(int $userId, Request $request, int $days = self::DEFAULT_TRUST_DAYS, ?string $deviceName = null): TrustedDevice
    {
        $ip = $request->ip() ?: '';
        $userAgent = $request->userAgent() ?: '';
        $fingerprint = $this->generateFingerprint($ip, $userAgent);

        return $this->trustDevice($userId, $fingerprint, $ip, $userAgent, $days, $deviceName);
    }

    /**
     * 信任指定设备（按指纹），已存在则续期
     */
    public function trustDevice(int $userId, string $fingerprint, string $ip, string $userAgent, int $days = self::DEFAULT_TRUST_DAYS, ?string $deviceName = null): TrustedDevice
    {
        $device = TrustedDevice::where('user_id', $userId)
            ->where('device_fingerprint', $fingerprint)
            ->first();

        if ($device) {
            $device->fill([
                'device_name' => $deviceName ?? $device->device_name,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'expires_at' => now()->addDays($days),
                'last_used_at' => now(),
            ])->save();
        } else {
            $device = TrustedDevice::create([
                'tenant_id' => TenantContext::getId(),
                'user_id' => $userId,
                'device_fingerprint' => $fingerprint,
                'device_name' => $deviceName,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'expires_at' => now()->addDays($days),
                'last_used_at' => now(),
            ]);
        }

        return $device;
    }

    /**
     * 检查当前请求设备是否受信任（未到期）
     */
    public function isCurrentDeviceTrusted(int $userId, Request $request): bool
    {
        $fingerprint = $this->fingerprintFromRequest($request);

        return $this->isDeviceTrusted($userId, $fingerprint, $request);
    }

    /**
     * 检查指定指纹设备是否受信任（未到期），命中时刷新最后使用时间
     */
    public function isDeviceTrusted(int $userId, string $fingerprint, ?Request $request = null): bool
    {
        $device = TrustedDevice::where('user_id', $userId)
            ->where('device_fingerprint', $fingerprint)
            ->first();

        if (! $device) {
            return false;
        }

        if ($device->isExpired()) {
            return false;
        }

        // 异步刷新 last_used_at，失败不影响校验
        try {
            $device->last_used_at = now();
            $device->save();
        } catch (\Throwable $e) {
            Log::warning('TrustedDeviceService touch last_used_at failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * 信任设备列表
     */
    public function listDevices(int $userId)
    {
        return TrustedDevice::where('user_id', $userId)
            ->orderByDesc('last_used_at')
            ->get();
    }

    /**
     * 活跃（未到期）的信任设备列表
     */
    public function listActiveDevices(int $userId)
    {
        return TrustedDevice::where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')
            ->get();
    }

    /**
     * 撤销指定设备信任
     */
    public function revokeDevice(int $userId, int $deviceId): bool
    {
        $device = $this->findDevice($userId, $deviceId);

        if (! $device) {
            return false;
        }

        $device->delete();

        return true;
    }

    /**
     * 撤销该用户所有设备信任
     *
     * @return int 撤销数量
     */
    public function revokeAllDevices(int $userId): int
    {
        return TrustedDevice::where('user_id', $userId)->delete();
    }

    /**
     * 重命名设备
     */
    public function renameDevice(int $userId, int $deviceId, string $name): ?TrustedDevice
    {
        $device = $this->findDevice($userId, $deviceId);
        if (! $device) {
            return null;
        }

        $device->device_name = $name;
        $device->save();

        return $device;
    }

    /**
     * 续期设备信任
     */
    public function extendTrust(int $userId, int $deviceId, int $days = self::DEFAULT_TRUST_DAYS): ?TrustedDevice
    {
        $device = $this->findDevice($userId, $deviceId);
        if (! $device) {
            return null;
        }

        $device->expires_at = now()->addDays($days);
        $device->save();

        return $device;
    }

    /**
     * 清理已过期信任设备
     *
     * @return int 清理数量
     */
    public function purgeExpired(): int
    {
        return TrustedDevice::where('expires_at', '<', now())->delete();
    }

    /**
     * 查找指定用户的设备
     */
    public function findDevice(int $userId, int $deviceId): ?TrustedDevice
    {
        return TrustedDevice::where('user_id', $userId)
            ->where('trusted_device_id', $deviceId)
            ->first();
    }
}
