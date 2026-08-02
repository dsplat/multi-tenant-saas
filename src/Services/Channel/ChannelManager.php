<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use InvalidArgumentException;
use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Services\Channel\Providers\EnterpriseWechatAppDriver;
use MultiTenantSaas\Services\Channel\Providers\EnterpriseWechatKfDriver;

/**
 * 渠道管理器 —— 租户感知的 Provider 工厂
 *
 * 按 (渠道类型, 租户) 从 tenant_settings 读取凭证并实例化驱动。
 * 凭证约定：group=channel，key={type}，value=JSON（加密存储），如
 *   enterprise_wechat_app => {"corp_id","corp_secret","agent_id","token","encoding_aes_key","enabled"}
 *   enterprise_wechat_kf  => {"corp_id","kf_secret","token","encoding_aes_key","enabled"}
 *
 * 下游可经 extend() 注册自定义驱动（无需改框架）。
 */
class ChannelManager
{
    /** 渠道类型 => 驱动类名 */
    protected array $drivers = [];

    /** 已实例化的驱动缓存（key: type:tenantId） */
    protected array $resolved = [];

    public function __construct()
    {
        // 驱动按需注册（下游可经 extend() 追加）
        $this->drivers[EnterpriseWechatAppDriver::TYPE] = EnterpriseWechatAppDriver::class;
        $this->drivers[EnterpriseWechatKfDriver::TYPE] = EnterpriseWechatKfDriver::class;
    }

    /**
     * 注册/覆盖驱动（下游扩展入口）。
     *
     * @param  class-string<ChannelContract>  $driverClass
     */
    public function extend(string $type, string $driverClass): void
    {
        $this->drivers[$type] = $driverClass;
    }

    public function hasDriver(string $type): bool
    {
        return isset($this->drivers[$type]);
    }

    /**
     * 按租户解析驱动实例（凭证从 tenant_settings 读取，缓存复用）。
     */
    public function resolve(string $type, int $tenantId): ChannelContract
    {
        $cacheKey = $type . ':' . $tenantId;

        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $class = $this->drivers[$type] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unsupported channel type: {$type}");
        }

        $config = $this->credentials($type, $tenantId);

        if ($config === []) {
            throw new ServiceUnavailableException("Channel [{$type}] not configured for tenant {$tenantId}");
        }

        return $this->resolved[$cacheKey] = new $class($config);
    }

    /**
     * 读取租户渠道凭证（解密 + JSON 解码）。
     *
     * @return array<string, mixed>
     */
    public function credentials(string $type, int $tenantId): array
    {
        // TenantSetting::get 按 tenant_id 显式查询（绕过 TenantScope），webhook 无上下文亦安全
        $value = TenantSetting::get($tenantId, 'channel', $type);

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * 租户已启用（配置且 enabled）的渠道类型列表。
     *
     * @return string[]
     */
    public function enabledChannels(int $tenantId): array
    {
        $enabled = [];

        foreach (array_keys($this->drivers) as $type) {
            $config = $this->credentials($type, $tenantId);

            if ($config !== [] && ($config['enabled'] ?? false)) {
                $enabled[] = $type;
            }
        }

        return $enabled;
    }
}
