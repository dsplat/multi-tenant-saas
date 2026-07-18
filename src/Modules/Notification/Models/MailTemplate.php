<?php

namespace MultiTenantSaas\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Context\TenantContext;

/**
 * 邮件模板模型
 *
 * 支持三级覆盖：system（系统默认）→ project（项目定制）→ tenant（租户定制）。
 * 查询时按优先级返回：租户定制 > 项目定制 > 系统默认。
 */
class MailTemplate extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId, SoftDeletes;

    protected $primaryKey = 'template_id';

    protected $table = 'mail_templates';

    // 模板类型
    public const TYPE_BILLING = 'billing';

    public const TYPE_NOTIFICATION = 'notification';

    public const TYPE_WELCOME = 'welcome';

    public const TYPE_RESET = 'reset';

    public const TYPE_REGISTRATION = 'registration';

    public const TYPE_VERIFICATION = 'verification';

    public const TYPE_INVITATION = 'invitation';

    public const TYPE_APPLICATION_SUBMITTED = 'application_submitted';

    public const TYPE_APPLICATION_APPROVED = 'application_approved';

    public const TYPE_APPLICATION_REJECTED = 'application_rejected';

    public const TYPES = [
        self::TYPE_BILLING,
        self::TYPE_NOTIFICATION,
        self::TYPE_WELCOME,
        self::TYPE_RESET,
        self::TYPE_REGISTRATION,
        self::TYPE_VERIFICATION,
        self::TYPE_INVITATION,
        self::TYPE_APPLICATION_SUBMITTED,
        self::TYPE_APPLICATION_APPROVED,
        self::TYPE_APPLICATION_REJECTED,
    ];

    // 模板层级
    public const SCOPE_SYSTEM = 'system';

    public const SCOPE_PROJECT = 'project';

    public const SCOPE_TENANT = 'tenant';

    public const SCOPES = [
        self::SCOPE_SYSTEM,
        self::SCOPE_PROJECT,
        self::SCOPE_TENANT,
    ];

    public const STATUS_ACTIVATED = 'activated';

    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_ACTIVATED,
        self::STATUS_DISABLED,
    ];

    protected $fillable = [
        'tenant_id',
        'scope',
        'type',
        'name_key',
        'name',
        'subject',
        'html_body',
        'text_body',
        'variables',
        'locale',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
        ];
    }

    /**
     * 覆写 BelongsToTenant 的 boot：使用自定义全局作用域，
     * 查询时同时返回当前租户模板 + project + system 模板
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('mailTemplateTenant', function (Builder $builder) {
            $tenantId = TenantContext::getId();
            if ($tenantId) {
                $table = $builder->getModel()->getTable();
                $builder->where(function ($q) use ($table, $tenantId) {
                    $q->where("{$table}.tenant_id", $tenantId)
                        ->orWhere(function ($sub) use ($table) {
                            $sub->whereNull("{$table}.tenant_id")
                                ->whereIn("{$table}.scope", [self::SCOPE_SYSTEM, self::SCOPE_PROJECT]);
                        });
                });
            }
        });

        // 创建时自动填充 tenant_id 和 scope
        static::creating(function (Model $model) {
            if (empty($model->scope)) {
                $model->scope = empty($model->tenant_id) ? self::SCOPE_SYSTEM : self::SCOPE_TENANT;
            }
            // 仅对 tenant scope 自动填充 tenant_id（system/project 级模板应保持 null）
            if (empty($model->tenant_id) && $model->scope === self::SCOPE_TENANT) {
                $model->tenant_id = TenantContext::getId();
            }
        });
    }

    /**
     * 所属租户（系统默认模板时为 null）
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    /**
     * 作用域：按类型筛选
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * 作用域：仅启用的模板
     */
    public function scopeActivated($query)
    {
        return $query->where('status', self::STATUS_ACTIVATED);
    }

    /**
     * 作用域：指定租户专属模板 + 系统默认模板（tenant_id IS NULL）
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where(function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId)
                ->orWhereNull('tenant_id');
        });
    }
}
