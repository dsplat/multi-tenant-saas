<?php

namespace MultiTenantSaas\Services;

/**
 * 用户偏好设置服务
 *
 * 委托给 UserProfileService 处理实际逻辑，
 * 作为独立服务注册便于 DI 和后续扩展。
 */
class UserPreferenceService
{
    public function __construct(
        protected UserProfileService $profileService
    ) {}

    public function getPreferences(int $userId): array
    {
        return $this->profileService->getPreferences($userId);
    }

    public function updatePreferences(int $userId, array $preferences): array
    {
        return $this->profileService->updatePreferences($userId, $preferences);
    }

    public function resetPreferences(int $userId): array
    {
        return $this->profileService->resetPreferences($userId);
    }
}
