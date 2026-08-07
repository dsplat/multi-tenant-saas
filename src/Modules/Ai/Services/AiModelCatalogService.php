<?php

namespace MultiTenantSaas\Modules\Ai\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI 模型动态清单服务
 *
 * 调用 provider 的 OpenAI 兼容 /models 端点拉取真实可用模型清单并缓存
 * （TTL 由 config('ai.model_catalog.cache_ttl') 控制，默认 1 天）。
 * config('ai.providers.*.models') 手写数组降级为网络不可达时的离线兜底，
 * 避免手写清单过时导致分配到不可用模型（如套餐外的 gpt-4o）。
 *
 * 使用方：
 * - ai:models:sync 命令：手动/定时刷新缓存
 * - Admin 模型配置界面（后续迭代）：消费 models() 动态清单
 */
class AiModelCatalogService
{
    /**
     * 缓存键前缀（按 provider 区分）
     */
    private const CACHE_PREFIX = 'ai:model_catalog:';

    /**
     * 获取指定 provider 的可用模型清单
     *
     * 优先返回缓存的动态清单；缓存未命中时实时拉取一次，
     * 拉取失败回退 config 手写兜底清单。
     *
     * @return list<string>
     */
    public function models(string $provider): array
    {
        $cached = Cache::get(self::CACHE_PREFIX . $provider);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $fetched = $this->sync($provider);

        return $fetched !== [] ? $fetched : $this->fallbackModels($provider);
    }

    /**
     * 仅读缓存清单（不触发实时拉取，供 admin 后台展示）
     *
     * @return list<string>|null 无缓存时返回 null
     */
    public function cachedModels(string $provider): ?array
    {
        $cached = Cache::get(self::CACHE_PREFIX . $provider);

        return is_array($cached) && $cached !== [] ? $cached : null;
    }

    /**
     * 从 provider 的 /models 端点拉取清单并写入缓存
     *
     * 拉取成功返回模型 ID 列表并缓存；失败（网络异常、配置缺失、
     * 响应格式异常）返回空数组且不覆盖已有缓存。
     *
     * @return list<string>
     */
    public function sync(string $provider): array
    {
        // DB 覆盖层（admin 后台补录的 url/key）优先，env/config 兜底
        $config = AiPlatformConfigService::resolveProviderConfig($provider);

        $baseUrl = rtrim((string) ($config['url'] ?? $config['base_url'] ?? ''), '/');
        $apiKey = (string) ($config['key'] ?? $config['api_key'] ?? '');

        if ($baseUrl === '' || $apiKey === '') {
            Log::info('[AiModelCatalogService] provider 未配置 url/key，跳过同步', ['provider' => $provider]);

            return [];
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('ai.model_catalog.timeout', 15))
                ->get("{$baseUrl}/models");

            if (! $response->successful()) {
                Log::warning('[AiModelCatalogService] /models 请求失败', [
                    'provider' => $provider,
                    'status' => $response->status(),
                ]);

                return [];
            }

            // OpenAI 兼容格式：{ "data": [ { "id": "qwen3.7-plus", ... }, ... ] }
            $ids = collect((array) $response->json('data', []))
                ->pluck('id')
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                Log::warning('[AiModelCatalogService] /models 响应无有效模型', ['provider' => $provider]);

                return [];
            }

            Cache::put(
                self::CACHE_PREFIX . $provider,
                $ids,
                (int) config('ai.model_catalog.cache_ttl', 86400),
            );

            return $ids;
        } catch (\Throwable $e) {
            Log::warning('[AiModelCatalogService] 同步异常，保留旧缓存', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 指定模型是否在 provider 可用清单内
     */
    public function isAvailable(string $provider, string $model): bool
    {
        return in_array($model, $this->models($provider), true);
    }

    /**
     * 清除指定 provider 的清单缓存
     */
    public function forget(string $provider): void
    {
        Cache::forget(self::CACHE_PREFIX . $provider);
    }

    /**
     * config 手写兜底清单
     *
     * @return list<string>
     */
    public function fallbackModels(string $provider): array
    {
        $models = config("ai.providers.{$provider}.models", []);

        return is_array($models) ? array_values(array_filter($models, 'is_string')) : [];
    }

    /**
     * 具备同步条件（url + key 齐备）的 provider 列表
     *
     * @return list<string>
     */
    public function syncableProviders(): array
    {
        $providers = [];

        foreach ((array) config('ai.providers', []) as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $url = (string) ($config['url'] ?? $config['base_url'] ?? '');
            $key = (string) ($config['key'] ?? $config['api_key'] ?? '');

            if ($url !== '' && $key !== '') {
                $providers[] = (string) $name;
            }
        }

        return $providers;
    }
}
