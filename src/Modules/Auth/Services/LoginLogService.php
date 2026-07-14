<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;
use MultiTenantSaas\Modules\User\Services\UserProfileService;

/**
 * 登录日志服务
 *
 * 委托给 UserProfileService 处理实际逻辑，
 * 作为独立服务注册便于 DI 和后续扩展。
 */
class LoginLogService
{
    public function __construct(
        protected UserProfileService $profileService
    ) {}

    public function recordLogin(int $userId, ?Request $request = null): AuditLog
    {
        return $this->profileService->recordLogin($userId, $request);
    }

    public function getLoginLogs(int $userId, int $limit = 20): Collection
    {
        return $this->profileService->getLoginLogs($userId, $limit);
    }

    public function detectAnomalousLogin(int $userId, string $currentIp): bool
    {
        return $this->profileService->detectAnomalousLogin($userId, $currentIp);
    }
}
