<?php

namespace MultiTenantSaas\Modules\Infrastructure\Observers;

use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 租户设置缓存失效观察者。
 *
 * 下游服务（邮件、OAuth、功能开关等）可能缓存租户设置，
 * 本 Observer 确保设置变更时相关缓存立即失效。
 */
class TenantSettingObserver
{
    public function saved(TenantSetting $setting): void
    {
        $this->clearCache($setting);
    }

    public function deleted(TenantSetting $setting): void
    {
        $this->clearCache($setting);
    }

    private function clearCache(TenantSetting $setting): void
    {
        $tenantId = $setting->tenant_id;

        // 清理按租户聚合的设置缓存
        Cache::forget("tenant_settings:{$tenantId}");

        // 清理按 key 粒度的缓存（如 mail.host, oauth.wechat_appid 等）
        if ($setting->key) {
            Cache::forget("tenant_setting:{$tenantId}:{$setting->key}");
        }

        // 通配清理：使用 tag 如果可用，否则清理已知前缀
        Cache::forget("tenant_config:{$tenantId}");
    }
}
