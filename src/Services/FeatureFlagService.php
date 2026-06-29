<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\FeatureFlag;

/**
 * 功能开关服务
 *
 * 提供：
 *  - 全局 / 租户 / 用户级开关
 *  - 灰度发布（基于哈希的百分比滚动）
 *  - A/B 测试分组
 *  - 开关依赖关系（含循环依赖保护）
 *  - 开关变更历史（基于审计日志）
 *
 * 开关定义使用缓存（Redis 优先，异常时降级为数组缓存 / 直查 DB）。
 */
class FeatureFlagService
{
    /** 缓存 key 前缀 */
    public const CACHE_PREFIX = 'feature_flag:';

    /** 缓存 TTL（秒） */
    public const CACHE_TTL = 300;

    protected const TABLE = 'feature_flags';

    /**
     * 预置开关定义
     */
    public const PRESETS = [
        [
            'name' => 'ai_text',
            'description' => 'AI 文本生成',
            'scope' => FeatureFlag::SCOPE_GLOBAL,
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ],
        [
            'name' => 'ai_image',
            'description' => 'AI 图像生成',
            'scope' => FeatureFlag::SCOPE_GLOBAL,
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ],
        [
            'name' => 'ai_video',
            'description' => 'AI 视频生成',
            'scope' => FeatureFlag::SCOPE_GLOBAL,
            'status' => FeatureFlag::STATUS_ACTIVE,
            'rollout_percentage' => 100,
        ],
        [
            'name' => 'beta_features',
            'description' => 'Beta 功能集合',
            'scope' => FeatureFlag::SCOPE_TENANT,
            'status' => FeatureFlag::STATUS_INACTIVE,
            'rollout_percentage' => 0,
        ],
        [
            'name' => 'new_dashboard',
            'description' => '新版控制台',
            'scope' => FeatureFlag::SCOPE_TENANT,
            'status' => FeatureFlag::STATUS_INACTIVE,
            'rollout_percentage' => 0,
        ],
    ];

    /**
     * 创建功能开关
     *
     * @param  array{
     *   name: string,
     *   description?: string|null,
     *   scope?: string,
     *   rollout_percentage?: int,
     *   conditions?: array|null,
     *   dependencies?: array|null,
     *   status?: string
     * }  $data  开关定义
     */
    public function create(array $data): FeatureFlag
    {
        $flag = FeatureFlag::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'scope' => $data['scope'] ?? FeatureFlag::SCOPE_GLOBAL,
            'rollout_percentage' => $data['rollout_percentage'] ?? 0,
            'conditions' => $data['conditions'] ?? null,
            'dependencies' => $data['dependencies'] ?? null,
            'status' => $data['status'] ?? FeatureFlag::STATUS_INACTIVE,
        ]);

        $this->clearCache($flag->name);
        $this->logChange($flag, 'feature_flag_created', null, $this->flagToArray($flag));

        return $flag;
    }

    /**
     * 按名称查找开关（带缓存）
     */
    public function find(string $name): ?FeatureFlag
    {
        $key = self::CACHE_PREFIX.$name;
        $ttl = (int) config('tenancy.feature_flags.cache_ttl', self::CACHE_TTL);

        try {
            $value = Cache::remember($key, $ttl, function () use ($name) {
                return FeatureFlag::findByName($name);
            });
        } catch (\Throwable $e) {
            Log::warning('[FeatureFlagService] 缓存不可用，降级直查 DB', [
                'flag' => $name,
                'error' => $e->getMessage(),
            ]);

            return FeatureFlag::findByName($name);
        }

        // 缓存可能存储了 null 值
        return $value instanceof FeatureFlag ? $value : null;
    }

    /**
     * 判断开关是否启用（核心方法）
     *
     * 判定顺序：开关存在且 active -> 依赖全部满足 -> 用户级覆盖 -> 租户级覆盖 -> 灰度比例
     *
     * @param  string  $name  开关名称
     * @param  int|null  $tenantId  租户 ID（为空时取当前租户上下文）
     * @param  int|null  $userId  用户 ID
     */
    public function isEnabled(string $name, ?int $tenantId = null, ?int $userId = null): bool
    {
        $tenantId = $tenantId ?? $this->resolveTenantId();

        return $this->checkEnabled($name, $tenantId, $userId, []);
    }

    /**
     * 启用开关
     */
    public function enable(string $name): FeatureFlag
    {
        return $this->updateStatus($name, FeatureFlag::STATUS_ACTIVE);
    }

    /**
     * 禁用开关
     */
    public function disable(string $name): FeatureFlag
    {
        return $this->updateStatus($name, FeatureFlag::STATUS_INACTIVE);
    }

    /**
     * 设置灰度发布比例
     *
     * @param  string  $name  开关名称
     * @param  int  $percentage  百分比 0-100
     *
     * @throws \InvalidArgumentException 百分比越界
     */
    public function setRolloutPercentage(string $name, int $percentage): FeatureFlag
    {
        if ($percentage < 0 || $percentage > 100) {
            throw new \InvalidArgumentException(trans('common.feature_flag_percentage_invalid'));
        }

        $flag = $this->requireFlag($name);
        $old = $this->flagToArray($flag);

        $flag->rollout_percentage = $percentage;
        $flag->save();

        $this->clearCache($name);
        $this->logChange($flag, 'feature_flag_rollout_updated', $old, $this->flagToArray($flag));

        return $flag;
    }

    /**
     * 设置 A/B 测试分组
     *
     * @param  string  $name  开关名称
     * @param  array<string,int>  $groups  分组配置，如 {"control": 50, "treatment": 50}
     */
    public function setAbGroups(string $name, array $groups): FeatureFlag
    {
        return DB::transaction(function () use ($name, $groups) {
            $flag = FeatureFlag::where('name', $name)->lockForUpdate()->firstOrFail();
            $old = $this->flagToArray($flag);

            $conditions = $flag->conditions ?? [];
            $conditions['ab_groups'] = $groups;
            $flag->conditions = $conditions;
            $flag->save();

            $this->clearCache($name);
            $this->logChange($flag, 'feature_flag_ab_groups_updated', $old, $this->flagToArray($flag));

            return $flag;
        });
    }

    /**
     * 获取当前对象命中的 A/B 分组
     *
     * @param  string  $name  开关名称
     * @param  int|null  $tenantId  租户 ID
     * @param  int|null  $userId  用户 ID
     * @return string|null 分组名；未配置或开关未启用时返回 null
     */
    public function getAbGroup(string $name, ?int $tenantId = null, ?int $userId = null): ?string
    {
        $flag = $this->find($name);
        if (! $flag || $flag->status !== FeatureFlag::STATUS_ACTIVE) {
            return null;
        }

        $groups = $flag->conditions['ab_groups'] ?? null;
        if (empty($groups) || ! is_array($groups)) {
            return null;
        }

        $tenantId = $tenantId ?? $this->resolveTenantId();
        $bucket = $this->hashBucket($tenantId, $userId, $name);

        $cumulative = 0;
        foreach ($groups as $groupName => $percentage) {
            $cumulative += (int) $percentage;
            if ($bucket < $cumulative) {
                return (string) $groupName;
            }
        }

        return null;
    }

    /**
     * 设置租户级覆盖
     */
    public function setTenantOverride(string $name, int $tenantId, bool $enabled): FeatureFlag
    {
        return DB::transaction(function () use ($name, $tenantId, $enabled) {
            $flag = FeatureFlag::where('name', $name)->lockForUpdate()->firstOrFail();
            $old = $this->flagToArray($flag);

            $conditions = $flag->conditions ?? [];
            $overrides = $conditions['tenant_overrides'] ?? [];
            $overrides[(string) $tenantId] = $enabled;
            $conditions['tenant_overrides'] = $overrides;
            $flag->conditions = $conditions;
            $flag->save();

            $this->clearCache($name);
            $this->logChange($flag, 'feature_flag_tenant_override_updated', $old, $this->flagToArray($flag));

            return $flag;
        });
    }

    /**
     * 设置用户级覆盖
     */
    public function setUserOverride(string $name, int $userId, bool $enabled): FeatureFlag
    {
        return DB::transaction(function () use ($name, $userId, $enabled) {
            $flag = FeatureFlag::where('name', $name)->lockForUpdate()->firstOrFail();
            $old = $this->flagToArray($flag);

            $conditions = $flag->conditions ?? [];
            $overrides = $conditions['user_overrides'] ?? [];
            $overrides[(string) $userId] = $enabled;
            $conditions['user_overrides'] = $overrides;
            $flag->conditions = $conditions;
            $flag->save();

            $this->clearCache($name);
            $this->logChange($flag, 'feature_flag_user_override_updated', $old, $this->flagToArray($flag));

            return $flag;
        });
    }

    /**
     * 添加依赖关系
     *
     * @param  string  $name  开关名称
     * @param  string  $dependsOn  依赖的开关名称
     */
    public function addDependency(string $name, string $dependsOn): FeatureFlag
    {
        return DB::transaction(function () use ($name, $dependsOn) {
            $flag = FeatureFlag::where('name', $name)->lockForUpdate()->firstOrFail();
            $old = $this->flagToArray($flag);

            $dependencies = $flag->dependencies ?? [];
            if (! in_array($dependsOn, $dependencies, true)) {
                $dependencies[] = $dependsOn;
            }
            $flag->dependencies = $dependencies;
            $flag->save();

            $this->clearCache($name);
            $this->logChange($flag, 'feature_flag_dependency_added', $old, $this->flagToArray($flag));

            return $flag;
        });
    }

    /**
     * 检查开关的所有依赖是否均已启用
     *
     * @param  string  $name  开关名称
     * @param  int|null  $tenantId  租户 ID
     * @param  int|null  $userId  用户 ID
     */
    public function checkDependencies(string $name, ?int $tenantId = null, ?int $userId = null): bool
    {
        $flag = $this->find($name);
        if (! $flag) {
            return false;
        }

        $dependencies = $flag->dependencies ?? [];
        if (empty($dependencies)) {
            return true;
        }

        $tenantId = $tenantId ?? $this->resolveTenantId();
        foreach ($dependencies as $depName) {
            if (! $this->checkEnabled($depName, $tenantId, $userId, [$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 获取开关变更历史（基于审计日志）
     */
    public function getHistory(string $name): Collection
    {
        $flag = $this->find($name);
        if (! $flag) {
            return collect();
        }

        return DB::table('audit_logs')
            ->where('resource_type', 'feature_flag')
            ->where('resource_id', $flag->feature_flag_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    /**
     * 列出全部开关
     */
    public function list(): Collection
    {
        return FeatureFlag::query()->orderBy('name')->get();
    }

    /**
     * 初始化预置开关（幂等）
     */
    public function seedPresets(): void
    {
        $presets = config('tenancy.feature_flags.presets', self::PRESETS);

        foreach ($presets as $preset) {
            $existing = FeatureFlag::findByName($preset['name']);
            if ($existing) {
                continue;
            }

            $this->create($preset);
        }
    }

    /**
     * 清除开关缓存
     *
     * @param  string|null  $name  开关名称；为 null 时清除全部开关缓存
     */
    public function clearCache(?string $name = null): void
    {
        try {
            if ($name !== null) {
                Cache::forget(self::CACHE_PREFIX.$name);

                return;
            }

            foreach (FeatureFlag::withTrashed()->pluck('name') as $flagName) {
                Cache::forget(self::CACHE_PREFIX.$flagName);
            }
        } catch (\Throwable $e) {
            Log::debug('[FeatureFlagService] 清除缓存失败（忽略）', [
                'flag' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 递归判定开关启用状态（带循环依赖保护）
     *
     * @param  array<int,string>  $visited  已访问的开关名链
     */
    protected function checkEnabled(string $name, ?int $tenantId, ?int $userId, array $visited): bool
    {
        if (in_array($name, $visited, true)) {
            // 循环依赖，视为未启用
            return false;
        }

        $visited[] = $name;

        $flag = $this->find($name);
        if (! $flag || $flag->status !== FeatureFlag::STATUS_ACTIVE) {
            return false;
        }

        // 依赖关系检查
        $dependencies = $flag->dependencies ?? [];
        foreach ($dependencies as $depName) {
            if (! $this->checkEnabled($depName, $tenantId, $userId, $visited)) {
                return false;
            }
        }

        $conditions = $flag->conditions ?? [];

        // 用户级覆盖（最高优先级）
        if ($userId !== null) {
            $userOverrides = $conditions['user_overrides'] ?? [];
            $key = (string) $userId;
            if (array_key_exists($key, $userOverrides)) {
                return (bool) $userOverrides[$key];
            }
        }

        // 租户级覆盖
        if ($tenantId !== null) {
            $tenantOverrides = $conditions['tenant_overrides'] ?? [];
            $key = (string) $tenantId;
            if (array_key_exists($key, $tenantOverrides)) {
                return (bool) $tenantOverrides[$key];
            }
        }

        // 灰度发布（百分比滚动）
        $percentage = (int) $flag->rollout_percentage;
        if ($percentage >= 100) {
            return true;
        }
        if ($percentage <= 0) {
            return false;
        }

        return $this->hashBucket($tenantId, $userId, $name) < $percentage;
    }

    /**
     * 基于哈希的计算桶值（0-99）
     *
     * 优先以租户 ID 为种子，其次用户 ID，保证同一对象在同一开关下结果稳定。
     */
    protected function hashBucket(?int $tenantId, ?int $userId, string $flagName): int
    {
        $seed = $tenantId ?? $userId ?? 0;

        return abs(crc32($flagName.':'.$seed)) % 100;
    }

    /**
     * 从租户上下文解析租户 ID
     */
    protected function resolveTenantId(): ?int
    {
        $id = TenantContext::getId();

        return $id !== null ? (int) $id : null;
    }

    /**
     * 获取开关或抛出异常
     *
     * @throws \RuntimeException 开关不存在
     */
    protected function requireFlag(string $name): FeatureFlag
    {
        $flag = $this->find($name);
        if (! $flag) {
            throw new \RuntimeException(trans('common.feature_flag_not_found', ['name' => $name]));
        }

        return $flag;
    }

    /**
     * 更新开关状态
     */
    protected function updateStatus(string $name, string $status): FeatureFlag
    {
        $flag = $this->requireFlag($name);
        $old = $this->flagToArray($flag);

        $flag->status = $status;
        $flag->save();

        $this->clearCache($name);
        $action = $status === FeatureFlag::STATUS_ACTIVE
            ? 'feature_flag_enabled'
            : 'feature_flag_disabled';
        $this->logChange($flag, $action, $old, $this->flagToArray($flag));

        return $flag;
    }

    /**
     * 记录审计日志（封装异常，避免审计失败影响主流程）
     */
    protected function logChange(FeatureFlag $flag, string $action, ?array $old, ?array $new): void
    {
        try {
            AuditService::log(
                action: $action,
                resourceType: 'feature_flag',
                resourceId: (int) $flag->feature_flag_id,
                oldValues: $old,
                newValues: $new
            );
        } catch (\Throwable $e) {
            Log::warning('[FeatureFlagService] 审计日志记录失败', [
                'action' => $action,
                'flag' => $flag->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 开关对象转数组（用于审计快照）
     *
     * @return array<string,mixed>
     */
    protected function flagToArray(FeatureFlag $flag): array
    {
        return [
            'name' => $flag->name,
            'scope' => $flag->scope,
            'status' => $flag->status,
            'rollout_percentage' => $flag->rollout_percentage,
            'conditions' => $flag->conditions,
            'dependencies' => $flag->dependencies,
        ];
    }
}
