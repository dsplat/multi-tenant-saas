<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Models\AiTenantConfig;

/**
 * 租户 AI 配置服务
 *
 * 管理租户级 AI 能力开关、自定义 API Key、模型白名单、月度预算上限与超额处理
 * 策略。提供配置导入导出与 API Key 解析（租户自定义覆盖系统默认）。
 *
 * 依赖：AiTenantConfig（租户级配置）、TenantContextContract（租户上下文）。
 * 租户隔离由 AiTenantConfig 的 BelongsToTenant 全局作用域保障。
 */
class AiConfigService
{
    public function __construct(
        protected TenantContextContract $tenantContext,
    ) {}

    /**
     * 获取当前租户的 AI 配置（不存在则按默认值初始化）
     */
    public function getOrCreateConfig(): AiTenantConfig
    {
        $config = AiTenantConfig::query()->first();

        if ($config !== null) {
            return $config;
        }

        return $this->createWithDefaults();
    }

    /**
     * 仅获取配置（不创建）
     */
    public function getConfig(): ?AiTenantConfig
    {
        return AiTenantConfig::query()->first();
    }

    /**
     * 创建默认配置
     */
    public function createWithDefaults(array $overrides = []): AiTenantConfig
    {
        return AiTenantConfig::create(array_merge([
            'text_enabled' => (bool) config('ai.tenant.default_text_enabled', true),
            'image_enabled' => (bool) config('ai.tenant.default_image_enabled', true),
            'video_enabled' => (bool) config('ai.tenant.default_video_enabled', true),
            'monthly_budget_limit' => (float) config('ai.tenant.default_monthly_budget_limit', 0),
            'overage_action' => (string) config('ai.tenant.default_overage_action', AiTenantConfig::OVERAGE_BLOCK),
        ], $overrides));
    }

    /**
     * 指定 AI 能力是否启用
     */
    public function isCategoryEnabled(string $category): bool
    {
        return $this->getOrCreateConfig()->isCategoryEnabled($category);
    }

    /**
     * 启用指定 AI 能力
     */
    public function enableCategory(string $category): AiTenantConfig
    {
        return $this->setCategoryEnabled($category, true);
    }

    /**
     * 禁用指定 AI 能力
     */
    public function disableCategory(string $category): AiTenantConfig
    {
        return $this->setCategoryEnabled($category, false);
    }

    /**
     * 设置指定 AI 能力的开关
     *
     * @throws \RuntimeException 能力分类非法时抛出
     */
    public function setCategoryEnabled(string $category, bool $enabled): AiTenantConfig
    {
        if (! in_array($category, AiTenantConfig::CATEGORIES, true)) {
            throw new \RuntimeException(trans('ai.ai_capability_disabled', ['category' => $category]));
        }

        $config = $this->getOrCreateConfig();

        $field = $category.'_enabled';
        $config->{$field} = $enabled;
        $config->save();

        return $config;
    }

    /**
     * 设置指定提供商的自定义 API Key（覆盖系统默认）
     */
    public function setCustomApiKey(string $provider, string $key): AiTenantConfig
    {
        $config = $this->getOrCreateConfig();

        $keys = is_array($config->custom_api_keys) ? $config->custom_api_keys : [];
        $keys[$provider] = $key;

        $config->custom_api_keys = $keys;
        $config->save();

        return $config;
    }

    /**
     * 移除指定提供商的自定义 API Key（回退系统默认）
     */
    public function removeCustomApiKey(string $provider): AiTenantConfig
    {
        $config = $this->getOrCreateConfig();

        $keys = is_array($config->custom_api_keys) ? $config->custom_api_keys : [];
        unset($keys[$provider]);

        $config->custom_api_keys = $keys ?: null;
        $config->save();

        return $config;
    }

    /**
     * 设置允许的模型列表（null 表示继承系统默认）
     */
    public function setAllowedModels(?array $models): AiTenantConfig
    {
        $config = $this->getOrCreateConfig();

        $config->allowed_models = $models === [] ? null : array_values(array_unique($models));
        $config->save();

        return $config;
    }

    /**
     * 新增允许的模型
     */
    public function addAllowedModel(string $model): AiTenantConfig
    {
        $config = $this->getOrCreateConfig();

        $models = is_array($config->allowed_models) ? $config->allowed_models : [];
        if (! in_array($model, $models, true)) {
            $models[] = $model;
        }

        $config->allowed_models = $models;
        $config->save();

        return $config;
    }

    /**
     * 移除允许的模型
     */
    public function removeAllowedModel(string $model): AiTenantConfig
    {
        $config = $this->getOrCreateConfig();

        $models = is_array($config->allowed_models) ? $config->allowed_models : [];
        $models = array_values(array_filter($models, fn ($m) => $m !== $model));

        $config->allowed_models = $models === [] ? null : $models;
        $config->save();

        return $config;
    }

    /**
     * 设置月度预算上限（0 表示不限）
     */
    public function setMonthlyBudgetLimit(float $amount): AiTenantConfig
    {
        $config = $this->getOrCreateConfig();

        $config->monthly_budget_limit = max(0, $amount);
        $config->save();

        return $config;
    }

    /**
     * 设置超额处理策略
     *
     * @throws \RuntimeException 策略非法时抛出
     */
    public function setOverageAction(string $action): AiTenantConfig
    {
        if (! in_array($action, AiTenantConfig::OVERAGE_ACTIONS, true)) {
            throw new \RuntimeException(trans('ai.overage_action_invalid', ['action' => $action]));
        }

        $config = $this->getOrCreateConfig();

        $config->overage_action = $action;
        $config->save();

        return $config;
    }

    /**
     * 解析指定提供商的 API Key（租户自定义优先，回退系统默认）
     */
    public function resolveApiKey(string $provider): ?string
    {
        $config = $this->getConfig();

        if ($config !== null) {
            $custom = $config->getCustomApiKey($provider);
            if ($custom !== null) {
                return $custom;
            }
        }

        $systemKey = config("ai.providers.{$provider}.api_key");

        return is_string($systemKey) && $systemKey !== '' ? $systemKey : null;
    }

    /**
     * 指定模型是否在允许列表中（未配置白名单时继承系统默认，返回 true）
     */
    public function isModelAllowed(string $model): bool
    {
        $config = $this->getConfig();

        return $config === null ? true : $config->isModelAllowed($model);
    }

    /**
     * 导出当前租户的 AI 配置
     */
    public function export(): array
    {
        $config = $this->getOrCreateConfig();

        return [
            'text_enabled' => $config->text_enabled,
            'image_enabled' => $config->image_enabled,
            'video_enabled' => $config->video_enabled,
            'custom_api_keys' => $config->custom_api_keys,
            'allowed_models' => $config->allowed_models,
            'monthly_budget_limit' => (float) $config->monthly_budget_limit,
            'overage_action' => $config->overage_action,
        ];
    }

    /**
     * 导入配置（按数组覆盖当前租户配置，不存在则创建）
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \RuntimeException 数据格式非法时抛出
     */
    public function import(array $data): AiTenantConfig
    {
        if ($data === []) {
            throw new \RuntimeException(trans('ai.config_import_invalid'));
        }

        $config = $this->getOrCreateConfig();

        if ($config === null) {
            $config = $this->createWithDefaults();
        }

        if (array_key_exists('text_enabled', $data)) {
            $config->text_enabled = (bool) $data['text_enabled'];
        }
        if (array_key_exists('image_enabled', $data)) {
            $config->image_enabled = (bool) $data['image_enabled'];
        }
        if (array_key_exists('video_enabled', $data)) {
            $config->video_enabled = (bool) $data['video_enabled'];
        }
        if (array_key_exists('custom_api_keys', $data)) {
            $config->custom_api_keys = is_array($data['custom_api_keys']) ? $data['custom_api_keys'] : null;
        }
        if (array_key_exists('allowed_models', $data)) {
            $config->allowed_models = is_array($data['allowed_models']) ? $data['allowed_models'] : null;
        }
        if (array_key_exists('monthly_budget_limit', $data)) {
            $config->monthly_budget_limit = max(0, (float) $data['monthly_budget_limit']);
        }
        if (array_key_exists('overage_action', $data)) {
            $action = (string) $data['overage_action'];
            if (! in_array($action, AiTenantConfig::OVERAGE_ACTIONS, true)) {
                throw new \RuntimeException(trans('ai.overage_action_invalid', ['action' => $action]));
            }
            $config->overage_action = $action;
        }

        $config->save();

        Log::info('[AiConfigService] tenant config imported');

        return $config;
    }
}
