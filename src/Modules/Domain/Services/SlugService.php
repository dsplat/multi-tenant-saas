<?php

namespace MultiTenantSaas\Modules\Domain\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * Slug 治理服务
 *
 * 三层防护：
 * 1. 黑名单硬拒（config + system_settings 动态）
 * 2. AI 风险评估（软警示，允许设置但标记）
 * 3. 后台打回（slug_status=rejected，降级为 tenant_id 访问）
 *
 * 状态机：null → active → rejected → (重新设置) → active
 */
class SlugService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    /**
     * 设置/变更租户 slug
     *
     * @return array{slug: string, status: string, risk_level: string, risk_reason: ?string}
     *
     * @throws ValidationException
     */
    public function setSlug(int $tenantId, string $slug): array
    {
        $slug = mb_strtolower(trim($slug));

        // 格式校验
        $this->validateFormat($slug);

        // 层级一：黑名单硬拒
        if ($this->isBlacklisted($slug)) {
            throw ValidationException::withMessages([
                'slug' => [trans('tenant.slug.blacklisted', ['slug' => $slug])],
            ]);
        }

        // 唯一性校验（排除自身）
        $exists = Tenant::where('slug', $slug)
            ->where('tenant_id', '!=', $tenantId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => [trans('tenant.slug.taken', ['slug' => $slug])],
            ]);
        }

        // 层级二：AI 风险评估（软警示）
        $risk = $this->assessRisk($slug);

        // 更新租户
        $tenant = Tenant::findOrFail($tenantId);
        $tenant->slug = $slug;
        $tenant->slug_status = self::STATUS_ACTIVE;
        $tenant->save();

        // 清除 slug 缓存
        $this->clearSlugCache($slug);

        Log::info('tenant.slug.set', [
            'tenant_id' => $tenantId,
            'slug' => $slug,
            'risk_level' => $risk['level'],
        ]);

        return [
            'slug' => $slug,
            'status' => self::STATUS_ACTIVE,
            'risk_level' => $risk['level'],
            'risk_reason' => $risk['reason'],
        ];
    }

    /**
     * 后台打回 slug
     *
     * 打回后：
     * - slug_status = rejected
     * - 路径 /{slug}/ 失效
     * - 二级域名从白名单移除（由 NginxConfigService 重新生成时自动处理）
     * - 租户降级为 /{tenant_id}/ 访问
     */
    public function rejectSlug(int $tenantId, ?string $reason = null): void
    {
        $tenant = Tenant::findOrFail($tenantId);

        if (! $tenant->slug) {
            return;
        }

        $oldSlug = $tenant->slug;
        $tenant->slug_status = self::STATUS_REJECTED;
        $tenant->save();

        // 清除缓存
        $this->clearSlugCache($oldSlug);

        Log::info('tenant.slug.rejected', [
            'tenant_id' => $tenantId,
            'slug' => $oldSlug,
            'reason' => $reason,
        ]);
    }

    /**
     * 恢复 slug（从 rejected 恢复为 active）
     */
    public function restoreSlug(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);

        if (! $tenant->slug || $tenant->slug_status !== self::STATUS_REJECTED) {
            return;
        }

        $tenant->slug_status = self::STATUS_ACTIVE;
        $tenant->save();

        $this->clearSlugCache($tenant->slug);

        Log::info('tenant.slug.restored', [
            'tenant_id' => $tenantId,
            'slug' => $tenant->slug,
        ]);
    }

    /**
     * 检查 slug 是否可用（供前端实时校验）
     *
     * @return array{available: bool, reason: ?string, risk_level: ?string}
     */
    public function checkAvailability(string $slug): array
    {
        $slug = mb_strtolower(trim($slug));

        // 格式
        $pattern = config('domain.slug_pattern', '/^[a-z0-9]([a-z0-9\-]{1,61}[a-z0-9])?$/');
        if (! preg_match($pattern, $slug)) {
            return ['available' => false, 'reason' => 'invalid_format', 'risk_level' => null];
        }

        $minLength = config('domain.slug_min_length', 3);
        if (mb_strlen($slug) < $minLength) {
            return ['available' => false, 'reason' => 'too_short', 'risk_level' => null];
        }

        // 黑名单
        if ($this->isBlacklisted($slug)) {
            return ['available' => false, 'reason' => 'blacklisted', 'risk_level' => null];
        }

        // 唯一性
        if (Tenant::where('slug', $slug)->exists()) {
            return ['available' => false, 'reason' => 'taken', 'risk_level' => null];
        }

        // AI 风险
        $risk = $this->assessRisk($slug);

        return [
            'available' => true,
            'reason' => null,
            'risk_level' => $risk['level'],
            'risk_reason' => $risk['reason'],
        ];
    }

    /**
     * 层级一：黑名单校验
     */
    protected function isBlacklisted(string $slug): bool
    {
        // 静态配置黑名单
        $reserved = config('domain.reserved_slugs', []);
        if (in_array($slug, $reserved, true)) {
            return true;
        }

        // 动态黑名单（system_settings 表，Admin 后台可管理）
        try {
            $dynamicBlacklist = \MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting::get(
                'slug_blacklist',
                []
            );
            if (is_array($dynamicBlacklist) && in_array($slug, $dynamicBlacklist, true)) {
                return true;
            }
        } catch (\Throwable) {
            // system_settings 表不存在时静默跳过
        }

        return false;
    }

    /**
     * 层级二：AI 风险评估
     *
     * 当前为规则引擎实现（编辑距离 + 关键词匹配），
     * 未来可替换为 LLM 调用（接口不变）。
     *
     * @return array{level: string, reason: ?string}
     */
    protected function assessRisk(string $slug): array
    {
        // 与已有高流量租户 slug 编辑距离 ≤ 1 → typosquatting 风险
        $existingSlugs = Tenant::whereNotNull('slug')
            ->where('slug_status', self::STATUS_ACTIVE)
            ->pluck('slug')
            ->toArray();

        foreach ($existingSlugs as $existing) {
            if ($existing === $slug) {
                continue;
            }
            $distance = levenshtein($slug, $existing);
            if ($distance <= 1) {
                return [
                    'level' => 'high',
                    'reason' => "与已有租户 slug「{$existing}」过于相似（编辑距离={$distance}）",
                ];
            }
        }

        // 与平台品牌 slug 编辑距离 ≤ 2 → 品牌混淆
        $brandSlug = env('PLATFORM_BRAND_SLUG');
        if ($brandSlug && levenshtein($slug, $brandSlug) <= 2) {
            return [
                'level' => 'high',
                'reason' => '与平台品牌标识过于相似',
            ];
        }

        // 通用高风险关键词
        $riskyPatterns = ['bank', 'gov', 'hospital', 'police', 'court', 'official'];
        foreach ($riskyPatterns as $pattern) {
            if (str_contains($slug, $pattern)) {
                return [
                    'level' => 'medium',
                    'reason' => "包含敏感关键词「{$pattern}」，可能被误认为官方机构",
                ];
            }
        }

        return ['level' => 'low', 'reason' => null];
    }

    /**
     * 格式校验
     *
     * @throws ValidationException
     */
    protected function validateFormat(string $slug): void
    {
        $pattern = config('domain.slug_pattern', '/^[a-z0-9]([a-z0-9\-]{1,61}[a-z0-9])?$/');
        $minLength = config('domain.slug_min_length', 3);

        $errors = [];

        if (mb_strlen($slug) < $minLength) {
            $errors[] = trans('tenant.slug.too_short', ['min' => $minLength]);
        }

        if (mb_strlen($slug) > 63) {
            $errors[] = trans('tenant.slug.too_long');
        }

        if (! preg_match($pattern, $slug)) {
            $errors[] = trans('tenant.slug.invalid_format');
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages(['slug' => $errors]);
        }
    }

    /**
     * 清除 slug 相关缓存
     */
    protected function clearSlugCache(string $slug): void
    {
        $prefix = config('tenancy.cache.prefix', 'tenant:');
        cache()->forget($prefix . 'slug:' . $slug);
    }
}
