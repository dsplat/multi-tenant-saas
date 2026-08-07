<?php

namespace MultiTenantSaas\Modules\Ai\Services;

use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Modules\Ai\Models\AiProvider;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;

/**
 * AI 平台级配置覆盖层（静态，无租户上下文依赖）
 *
 * 提供商连接解析优先级：
 *   ai_providers 多源管理表（系统级且启用）→ system_settings 覆盖组 → config（env 引导层）。
 * admin 后台修改后立即（缓存 TTL 内）生效，无需改 .env / config:cache；
 * env 未配置 url/key 时也可后续在后台补录。
 *
 * DB 分组约定：
 *  - group='ai'                    默认模型组（default_chat_model / default_completion_model /
 *                                  default_embedding_model / default_provider）
 *  - group='ai_provider_{code}'    提供商连接覆盖（base_url / api_key，api_key 加密存储）
 *  - ai_providers 表（tenant_id=null）  提供商多源管理（优先级高于 system_settings）
 *
 * 注意：本项目跑在 Octane 上，进程内静态缓存会跨请求残留，故一律用 Cache 短 TTL。
 */
class AiPlatformConfigService
{
    public const GROUP_DEFAULTS = 'ai';

    private const CACHE_PREFIX = 'ai_platform_config:';

    private const CACHE_TTL = 60;

    /**
     * 解析默认文本模型
     *
     * @param  string  $kind  chat / completion / embedding
     * @param  string  $configKey  config 兜底键（如 ai.text.default_chat_model）
     */
    public static function resolveTextDefault(string $kind, string $configKey, string $fallback): string
    {
        $db = static::defaultSetting('default_' . $kind . '_model');

        return $db ?? (string) config($configKey, $fallback);
    }

    /**
     * 解析默认提供商（DB → config → openai）
     */
    public static function resolveDefaultProvider(): string
    {
        return static::defaultSetting('default_provider')
            ?? (string) config('ai.default_provider', 'openai');
    }

    /**
     * 解析提供商连接配置（ai_providers 表 → system_settings → env/config）
     *
     * DB 中 base_url/api_key 非空时同时写入 url/base_url、key/api_key 双字段，
     * 兼容各 Provider 的不同读取习惯。
     */
    public static function resolveProviderConfig(string $code): array
    {
        $config = (array) config("ai.providers.{$code}", []);

        // 1) ai_providers 多源管理表（系统级且启用）优先
        $provider = static::providerRecord($code);
        if ($provider !== null) {
            $baseUrl = (string) ($provider->base_url ?? '');
            if ($baseUrl !== '') {
                $config['base_url'] = $baseUrl;
                $config['url'] = $baseUrl;
            }

            $apiKey = (string) ($provider->api_key ?? '');
            if ($apiKey !== '') {
                $config['api_key'] = $apiKey;
                $config['key'] = $apiKey;
            }

            return $config;
        }

        // 2) system_settings 覆盖组（P1 兼容层）
        $overrides = Cache::remember(
            self::CACHE_PREFIX . 'provider:' . $code,
            self::CACHE_TTL,
            function () use ($code) {
                try {
                    return SystemSetting::getGroup('ai_provider_' . $code);
                } catch (\Throwable $e) {
                    // 表不存在等安装初期异常 → 视为无覆盖
                    return [];
                }
            }
        );

        $baseUrl = (string) ($overrides['base_url'] ?? '');
        if ($baseUrl !== '') {
            $config['base_url'] = $baseUrl;
            $config['url'] = $baseUrl;
        }

        $apiKey = (string) ($overrides['api_key'] ?? '');
        if ($apiKey !== '') {
            $config['api_key'] = $apiKey;
            $config['key'] = $apiKey;
        }

        return $config;
    }

    /**
     * 读取系统级（tenant_id=null）且启用的提供商记录（带缓存）
     */
    public static function providerRecord(string $code): ?AiProvider
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'provider_record:' . $code,
            self::CACHE_TTL,
            function () use ($code) {
                try {
                    return AiProvider::query()
                        ->whereNull('tenant_id')
                        ->byCode($code)
                        ->active()
                        ->first();
                } catch (\Throwable $e) {
                    // 表不存在等安装初期异常 → 视为无记录
                    return null;
                }
            }
        );
    }

    /**
     * 失效指定提供商（或默认组）的覆盖缓存（后台保存后调用）
     */
    public static function forgetCached(?string $providerCode = null): void
    {
        Cache::forget(self::CACHE_PREFIX . 'defaults');

        if ($providerCode !== null) {
            Cache::forget(self::CACHE_PREFIX . 'provider:' . $providerCode);
            Cache::forget(self::CACHE_PREFIX . 'provider_record:' . $providerCode);
        }
    }

    /**
     * 读取默认组（group='ai'）中的单个键
     */
    protected static function defaultSetting(string $key): ?string
    {
        $defaults = Cache::remember(
            self::CACHE_PREFIX . 'defaults',
            self::CACHE_TTL,
            function () {
                try {
                    return SystemSetting::getGroup(self::GROUP_DEFAULTS);
                } catch (\Throwable $e) {
                    return [];
                }
            }
        );

        $value = $defaults[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
