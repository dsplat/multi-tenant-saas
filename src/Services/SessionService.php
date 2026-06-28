<?php

namespace MultiTenantSaas\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\UserSession;

/**
 * 会话管理服务
 *
 * 功能：
 *  - 活跃会话列表
 *  - 设备指纹（User-Agent + IP）
 *  - 强制下线（单个/全部）
 *  - 异常登录检测（新设备/新IP 标记）
 *  - 会话超时配置
 */
class SessionService
{
    /** 默认会话超时（分钟） */
    private const DEFAULT_TIMEOUT_MINUTES = 60;

    /**
     * 记录登录会话（登录成功时调用）
     */
    public function recordSession(
        int $userId,
        int $tokenId,
        string $ip,
        string $userAgent,
        ?string $tenantId = null
    ): UserSession {
        $fingerprint = $this->generateFingerprint($userAgent, $ip);
        $anomaly = $this->detectAnomaly($userId, $fingerprint, $ip);

        return UserSession::create([
            'tenant_id' => $tenantId ?? TenantContext::getId(),
            'user_id' => $userId,
            'token_id' => $tokenId,
            'session_id' => $this->generateSessionId(),
            'ip_address' => $ip,
            'device_info' => $userAgent,
            'device_fingerprint' => $fingerprint,
            'login_at' => now(),
            'last_active_at' => now(),
            'is_anomalous' => $anomaly['new_device'] || $anomaly['new_ip'],
        ]);
    }

    /**
     * 生成设备指纹（User-Agent + IP 的 hash）
     */
    public function generateFingerprint(string $userAgent, string $ip): string
    {
        return hash('sha256', $userAgent.'|'.$ip);
    }

    /**
     * 从请求生成设备指纹
     */
    public function fingerprintFromRequest(Request $request): string
    {
        return $this->generateFingerprint($request->userAgent() ?: '', $request->ip() ?: '');
    }

    /**
     * 活跃会话列表
     */
    public function listSessions(int $userId)
    {
        return UserSession::where('user_id', $userId)
            ->orderByDesc('last_active_at')
            ->get();
    }

    /**
     * 强制下线单个会话
     */
    public function revokeSession(int $userId, int $sessionId): bool
    {
        $session = UserSession::where('user_id', $userId)
            ->where('user_session_id', $sessionId)
            ->first();

        if (! $session) {
            return false;
        }

        // 删除对应的 Sanctum Token，使该会话立即失效
        if ($session->token_id) {
            DB::table('personal_access_tokens')
                ->where('id', $session->token_id)
                ->delete();
        }

        $session->delete();

        Log::info('SessionService revoke session', [
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);

        return true;
    }

    /**
     * 强制下线所有会话（排除指定 token）
     *
     * @return int 被下线的会话数量
     */
    public function revokeAllSessions(int $userId, ?int $exceptTokenId = null): int
    {
        $query = UserSession::where('user_id', $userId);

        if ($exceptTokenId !== null) {
            $query->where('token_id', '!=', $exceptTokenId);
        }

        $sessions = $query->get();
        $count = $sessions->count();

        $tokenIds = $sessions->pluck('token_id')->filter()->all();

        if (! empty($tokenIds)) {
            DB::table('personal_access_tokens')
                ->whereIn('id', $tokenIds)
                ->delete();
        }

        $query->delete();

        Log::info('SessionService revoke all sessions', [
            'user_id' => $userId,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * 更新会话活跃时间（按 token_id）
     */
    public function updateActivity(int $tokenId): void
    {
        UserSession::where('token_id', $tokenId)->update([
            'last_active_at' => now(),
        ]);
    }

    /**
     * 异常登录检测：新设备/新IP
     *
     * @return array{new_device: bool, new_ip: bool}
     */
    public function detectAnomaly(int $userId, string $fingerprint, string $ip): array
    {
        $deviceSeen = UserSession::where('user_id', $userId)
            ->where('device_fingerprint', $fingerprint)
            ->exists();

        $ipSeen = UserSession::where('user_id', $userId)
            ->where('ip_address', $ip)
            ->exists();

        return [
            'new_device' => ! $deviceSeen,
            'new_ip' => ! $ipSeen,
        ];
    }

    /**
     * 查询异常会话
     */
    public function listAnomalousSessions(int $userId)
    {
        return UserSession::where('user_id', $userId)
            ->where('is_anomalous', true)
            ->orderByDesc('login_at')
            ->get();
    }

    /**
     * 清理过期会话
     *
     * @return int 清理数量
     */
    public function purgeExpiredSessions(?int $timeoutMinutes = null): int
    {
        $timeout = $timeoutMinutes ?? $this->getSessionTimeout();
        $threshold = now()->subMinutes($timeout);

        $sessions = UserSession::where('last_active_at', '<', $threshold)->get();
        $count = $sessions->count();

        $tokenIds = $sessions->pluck('token_id')->filter()->all();

        if (! empty($tokenIds)) {
            DB::table('personal_access_tokens')
                ->whereIn('id', $tokenIds)
                ->delete();
        }

        UserSession::where('last_active_at', '<', $threshold)->delete();

        return $count;
    }

    /**
     * 获取会话超时配置（分钟）
     */
    public function getSessionTimeout(): int
    {
        return (int) config('tenancy.session_timeout', self::DEFAULT_TIMEOUT_MINUTES);
    }

    /**
     * 设置会话超时配置
     */
    public function setSessionTimeout(int $minutes): void
    {
        config(['tenancy.session_timeout' => $minutes]);
    }

    /**
     * 生成会话标识
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
