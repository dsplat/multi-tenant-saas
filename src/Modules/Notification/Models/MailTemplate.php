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
 * 支持租户专属模板与系统默认模板（tenant_id IS NULL）。
 * 查询时默认返回当前租户模板 + 系统默认模板，便于租户覆盖系统默认值。
 */
class MailTemplate extends Model
{
    use BelongsToTenant, HasFactory, HasGlobalId, SoftDeletes;

    protected $primaryKey = 'template_id';

    protected $table = 'mail_templates';

    public const TYPE_BILLING = 'billing';

    public const TYPE_NOTIFICATION = 'notification';

    public const TYPE_WELCOME = 'welcome';

    public const TYPE_RESET = 'reset';

    public const TYPES = [
        self::TYPE_BILLING,
        self::TYPE_NOTIFICATION,
        self::TYPE_WELCOME,
        self::TYPE_RESET,
    ];

    public const STATUS_ACTIVATED = 'activated';

    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_ACTIVATED,
        self::STATUS_DISABLED,
    ];

    protected $fillable = [
        'tenant_id',
        'type',
        'name_key',
        'name',
        'subject',
        'html_body',
        'text_body',
        'variables',
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
     * 查询时同时返回当前租户模板 + tenant_id IS NULL 的系统默认模板
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('mailTemplateTenant', function (Builder $builder) {
            $tenantId = TenantContext::getId();
            if ($tenantId) {
                $table = $builder->getModel()->getTable();
                $builder->where(function ($q) use ($table, $tenantId) {
                    $q->where("{$table}.tenant_id", $tenantId)
                        ->orWhereNull("{$table}.tenant_id");
                });
            }
        });

        // 创建时自动填充 tenant_id（无租户上下文时为 null，即系统默认模板）
        static::creating(function (Model $model) {
            if (empty($model->tenant_id)) {
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
