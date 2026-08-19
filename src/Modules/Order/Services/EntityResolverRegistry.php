<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Order\Services;

/**
 * 实体解析注册表（按 entity_type 字符串解析业务实体）
 *
 * 跨层解耦第三层：项目层在 ServiceProvider boot 中注册解析器：
 *   app(EntityResolverRegistry::class)->register('activity', function (string $entityId, int $tenantId) {
 *       return Activity::where('activity_id', $entityId)->where('tenant_id', $tenantId)->first();
 *   });
 *
 * 供支付成功回调等场景按字符串解析实体（如自动增加活动参与人数）。
 * 未注册的 entity_type 返回 null（静默跳过，不影响订单主流程）。
 */
class EntityResolverRegistry
{
    /** @var array<string, callable(string, int): mixed> */
    protected array $resolvers = [];

    /**
     * @param callable(string $entityId, int $tenantId): mixed $resolver
     */
    public function register(string $entityType, callable $resolver): void
    {
        $this->resolvers[$entityType] = $resolver;
    }

    public function has(string $entityType): bool
    {
        return isset($this->resolvers[$entityType]);
    }

    /**
     * 解析实体；未注册或实体不存在时返回 null
     */
    public function resolve(?string $entityType, ?string $entityId, int $tenantId): mixed
    {
        if (! $entityType || $entityId === null || $entityId === '' || ! $this->has($entityType)) {
            return null;
        }

        return ($this->resolvers[$entityType])($entityId, $tenantId);
    }

    /** @return array<string, callable> */
    public function all(): array
    {
        return $this->resolvers;
    }
}
