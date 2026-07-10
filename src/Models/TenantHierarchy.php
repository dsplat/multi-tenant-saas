<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 租户层级关系模型
 *
 * 表达父-子租户关系（企业集团场景）。模型以父租户视角应用 TenantScope，
 * 即在普通租户上下文下仅可见其作为父租户建立的关系记录；
 * 跨租户（admin / 显式 tenant_id）操作请使用 withoutGlobalScope。
 *
 * permission_scope JSON 结构示例：
 * [
 *   'shared_resources' => [
 *     ['resource_type' => 'ai_prompt', 'resource_id' => 123, 'permissions' => ['read']]
 *   ],
 *   'cross_tenant_access' => ['billing_view' => true, 'user_management' => false],
 * ]
 */
class TenantHierarchy extends Model
{
    use BelongsToTenant, HasGlobalId;

    /** 关系类型：子公司 */
    public const TYPE_SUBSIDIARY = 'subsidiary';

    /** 关系类型：分支机构 */
    public const TYPE_BRANCH = 'branch';

    /** 关系类型：事业部门 */
    public const TYPE_DIVISION = 'division';

    /** 全部关系类型 */
    public const TYPES = [
        self::TYPE_SUBSIDIARY,
        self::TYPE_BRANCH,
        self::TYPE_DIVISION,
    ];

    protected $primaryKey = 'tenant_hierarchy_id';

    protected $fillable = [
        'tenant_id',
        'child_tenant_id',
        'relation_type',
        'permission_scope',
        'is_active',
    ];

    protected $attributes = [
        'relation_type' => self::TYPE_SUBSIDIARY,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'tenant_hierarchy_id' => 'integer',
            'tenant_id' => 'integer',
            'child_tenant_id' => 'integer',
            'permission_scope' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 父租户关联
     */
    public function parentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    /**
     * 子租户关联
     */
    public function childTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'child_tenant_id', 'tenant_id');
    }

    /**
     * 反向关系：子租户作为父租户时建立的下级关系
     */
    public function grandchildHierarchies(): HasMany
    {
        return $this->hasMany(self::class, 'tenant_id', 'child_tenant_id');
    }

    /**
     * 资源是否在共享池中
     */
    public function isResourceShared(string $resourceType, int $resourceId): bool
    {
        $resources = $this->sharedResources();

        foreach ($resources as $resource) {
            if (($resource['resource_type'] ?? '') === $resourceType
                && (int) ($resource['resource_id'] ?? 0) === $resourceId
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取共享资源列表
     */
    public function sharedResources(): array
    {
        $scope = $this->permission_scope ?? [];

        return $scope['shared_resources'] ?? [];
    }

    /**
     * 添加共享资源
     */
    public function addSharedResource(string $resourceType, int $resourceId, array $permissions = ['read']): bool
    {
        if ($this->isResourceShared($resourceType, $resourceId)) {
            return false;
        }

        $scope = $this->permission_scope ?? [];
        $resources = $scope['shared_resources'] ?? [];
        $resources[] = [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'permissions' => $permissions,
        ];
        $scope['shared_resources'] = $resources;

        $this->permission_scope = $scope;

        return $this->save();
    }

    /**
     * 撤销共享资源
     */
    public function removeSharedResource(string $resourceType, int $resourceId): bool
    {
        $scope = $this->permission_scope ?? [];
        $resources = $scope['shared_resources'] ?? [];

        $remaining = array_values(array_filter(
            $resources,
            fn ($r) => ! (
                ($r['resource_type'] ?? '') === $resourceType
                && (int) ($r['resource_id'] ?? 0) === $resourceId
            )
        ));

        if (count($remaining) === count($resources)) {
            return false;
        }

        $scope['shared_resources'] = $remaining;
        $this->permission_scope = $scope;

        return $this->save();
    }

    /**
     * 跨租户访问授权：是否允许指定 scope
     */
    public function canAccess(string $accessScope): bool
    {
        $scope = $this->permission_scope ?? [];
        $access = $scope['cross_tenant_access'] ?? [];

        return (bool) ($access[$accessScope] ?? false);
    }

    /**
     * 授予跨租户访问 scope
     */
    public function grantAccess(string $accessScope, bool $allowed = true): bool
    {
        $scope = $this->permission_scope ?? [];
        $access = $scope['cross_tenant_access'] ?? [];
        $access[$accessScope] = $allowed;
        $scope['cross_tenant_access'] = $access;

        $this->permission_scope = $scope;

        return $this->save();
    }

    /**
     * 撤销跨租户访问 scope
     */
    public function revokeAccess(string $accessScope): bool
    {
        return $this->grantAccess($accessScope, false);
    }

    /**
     * 按 child_tenant_id 显式查询（绕过 TenantScope）
     */
    public static function findByChild(int $parentTenantId, int $childTenantId): ?self
    {
        return static::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $parentTenantId)
            ->where('child_tenant_id', $childTenantId)
            ->first();
    }
}
