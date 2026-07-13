<?php

namespace MultiTenantSaas\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * SSO 提供方（租户级 IdP 配置）
 *
 * 支持 SAML 2.0 与 OIDC 两类 IdP 集成。
 * 每个租户可配置多个 IdP，相互隔离。
 */
class SsoProvider extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const TYPE_SAML = 'saml';

    public const TYPE_OIDC = 'oidc';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $primaryKey = 'sso_provider_id';

    protected $fillable = [
        'sso_provider_id',
        'tenant_id',
        'type',
        'name',
        'display_name',
        'entity_id',
        'metadata_url',
        'certificate',
        'sso_url',
        'slo_url',
        'client_id',
        'client_secret',
        'authorize_url',
        'token_url',
        'userinfo_url',
        'scope',
        'attribute_mapping',
        'status',
    ];

    protected $hidden = [
        'client_secret',
        'certificate',
    ];

    protected function casts(): array
    {
        return [
            'attribute_mapping' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    /**
     * 是否为 SAML 类型
     */
    public function isSaml(): bool
    {
        return $this->type === self::TYPE_SAML;
    }

    /**
     * 是否为 OIDC 类型
     */
    public function isOidc(): bool
    {
        return $this->type === self::TYPE_OIDC;
    }

    /**
     * 是否启用
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
