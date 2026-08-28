<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Channel;

use InvalidArgumentException;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\ChannelContract;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Services\Channel\Providers\EnterpriseWechatAppDriver;
use MultiTenantSaas\Services\Channel\Providers\EnterpriseWechatKfDriver;
use MultiTenantSaas\Support\WechatWork\WechatWorkProxy;

/**
 * 渠道管理器 —— 租户感知的 Provider 工厂
 *
 * 按 (渠道类型, 租户) 从 tenant_settings 读取凭证并实例化驱动。
 * 凭证约定：group=channel，key={type}，value=JSON（加密存储），如
 *   enterprise_wechat_app => {"corp_id","corp_secret","agent_id","token","encoding_aes_key","enabled"}
 *   enterprise_wechat_kf  => {"corp_id","kf_secret","token","encoding_aes_key","enabled"}
 *
 * 凭证双轨（9.4）：企微渠道租户无自建凭证（corp_secret/kf_secret）时，
 * 若已有代开发授权则注入 mode=suite + tenant_id + 出口代理，驱动内
 * 经 WechatWorkSuiteService::corpAccessToken 解析企业 token（permanent_code
 * 充当 secret）；WechatWork 模块为可选拆包，未安装时 class_exists 守卫回退。
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

        // 9.4：企微渠道双轨凭证注入（套件授权租户无需手填 corp_secret/kf_secret）
        if (in_array($type, [EnterpriseWechatAppDriver::TYPE, EnterpriseWechatKfDriver::TYPE], true)) {
            $config = $this->withSuiteCredentials($type, $config, $tenantId);
        }

        return $this->resolved[$cacheKey] = new $class($config);
    }

    /**
     * 企微渠道套件授权凭证注入（9.4）
     *
     * 已有自建凭证（corp_secret / kf_secret）时保持原样；否则租户存在代开发
     * 授权时注入 mode=suite + tenant_id + 出口代理，驱动内解析企业 token。
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function withSuiteCredentials(string $type, array $config, int $tenantId): array
    {
        $secretKey = $type === EnterpriseWechatKfDriver::TYPE ? 'kf_secret' : 'corp_secret';

        if (! empty($config[$secretKey] ?? '')) {
            return $config;
        }

        // WechatWork 模块可选拆包：未安装/未迁移时 class_exists + hasTable 守卫
        if (! class_exists(WechatWorkSuiteService::class) || ! Schema::hasTable('wechat_work_authorizations')) {
            return $config;
        }

        $authorization = app(WechatWorkSuiteService::class)->authorization($tenantId);

        if ($authorization === null || ! $authorization->isAuthorized()) {
            return $config;
        }

        $config['mode'] = 'suite';
        $config['tenant_id'] = $tenantId;
        $config['corp_id'] = (string) ($config['corp_id'] ?? '') ?: (string) ($authorization->corp_id ?? '');
        $config['agent_id'] = (string) ($config['agent_id'] ?? '') ?: (string) ($authorization->agent_id ?? '');

        // 企业侧接口走租户出口代理（9.1，可信 IP 白名单出网）
        $proxy = WechatWorkProxy::resolve($tenantId);
        if (isset($proxy['proxy'])) {
            $config['proxy'] = $proxy['proxy'];
        }

        return $config;
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
