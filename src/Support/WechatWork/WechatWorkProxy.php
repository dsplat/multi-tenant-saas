<?php

declare(strict_types=1);

namespace MultiTenantSaas\Support\WechatWork;

use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 企微出口代理解析（9.1）
 *
 * 企微 API 要求服务器出口 IP 在企业应用「可信 IP」白名单内（否则报 60020），
 * 且同一出口 IP 只能归属一个企业（IP 刚性，无法跨租户共享摊薄）。
 * 平台为租户分配代理出口 IP 时，配置存 tenant_settings group='wechatwork'
 * key='proxy' 的 JSON（enabled / scheme / host / port / username / password，
 * password 随 JSON 整体加密存储）。
 *
 * 应用边界：
 * - 企业侧接口（企业 token / 消息 / 客户联系 / 会话存档 / OAuth）→ 走代理
 * - 服务商侧接口（get_suite_token / get_provider_token / get_pre_auth_code /
 *   get_customized_auth_url / get_permanent_code）→ 永不走代理（服务商凭证
 *   是平台级配置，出口 IP 归平台服务器，与租户代理无关）
 * - fail-fast：配置了代理但解析失败时不回退直连，避免静默绕过可信 IP 白名单
 */
class WechatWorkProxy
{
    public const GROUP = 'wechatwork';

    public const KEY = 'proxy';

    /**
     * 解析租户企微出口代理
     *
     * @return array{proxy?: string} Guzzle withOptions 参数；未启用返回空数组（直连）
     */
    public static function resolve(int $tenantId): array
    {
        $config = TenantSetting::get($tenantId, self::GROUP, self::KEY, null);

        if (! is_array($config) || empty($config['enabled']) || empty($config['host'])) {
            return [];
        }

        $auth = '';
        if (! empty($config['username']) || ! empty($config['password'])) {
            $auth = rawurlencode((string) ($config['username'] ?? ''))
                . ':' . rawurlencode((string) ($config['password'] ?? '')) . '@';
        }

        $port = ! empty($config['port']) ? ':' . (int) $config['port'] : '';
        $scheme = ! empty($config['scheme']) ? (string) $config['scheme'] : 'http';

        return ['proxy' => "{$scheme}://{$auth}{$config['host']}{$port}"];
    }
}
