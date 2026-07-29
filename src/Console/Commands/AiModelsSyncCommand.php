<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Ai\Services\AiModelCatalogService;

/**
 * ai:models:sync — 拉取 provider 真实可用模型清单并缓存
 *
 * 调各 provider 的 OpenAI 兼容 /models 端点刷新动态清单缓存（TTL 1 天），
 * config('ai.providers.*.models') 手写数组仅作网络不可达时的离线兜底。
 * 缺省同步所有 url/key 齐备的 provider；--provider 仅同步指定项。
 */
class AiModelsSyncCommand extends Command
{
    protected $signature = 'ai:models:sync
        {--provider= : 仅同步指定 provider（缺省为全部已配置 url/key 的 provider）}';

    protected $description = '拉取 AI provider 的 /models 动态模型清单并缓存（手写清单降级为兜底）';

    public function handle(AiModelCatalogService $catalog): int
    {
        $providerOption = (string) $this->option('provider');

        $providers = $providerOption !== ''
            ? [$providerOption]
            : $catalog->syncableProviders();

        if ($providers === []) {
            $this->warn('没有可同步的 provider（需在 config/ai.php 配置 url 与 key）。');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($providers as $provider) {
            $models = $catalog->sync($provider);

            if ($models === []) {
                $fallback = $catalog->fallbackModels($provider);
                $this->warn("{$provider}: 同步失败，沿用兜底清单（" . count($fallback) . ' 个模型）');
                $failed++;

                continue;
            }

            $this->info("{$provider}: 同步成功，缓存 " . count($models) . ' 个模型');
            $this->line('  ' . implode(', ', array_slice($models, 0, 10)) . (count($models) > 10 ? ' …' : ''));
        }

        return $failed === count($providers) ? self::FAILURE : self::SUCCESS;
    }
}
