<?php

namespace MultiTenantSaas\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * 租户申请模型
 *
 * 记录 Operator 提交的租户申请，包含组织信息、申请状态、审批备注等。
 */
class TenantApplication extends Model
{
    use HasGlobalId;

    protected $primaryKey = 'application_id';

    protected $table = 'tenant_applications';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'operator_id',
        'code',
        'org_name',
        'org_industry',
        'org_size',
        'contact_info',
        'status',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'contact_info' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * 申请 Operator。
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'operator_id', 'operator_id');
    }

    /**
     * 审批人。
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'reviewed_by', 'operator_id');
    }

    /**
     * 生成申请编号。
     */
    public static function generateCode(): string
    {
        return 'APP-' . date('Ymd') . '-' . strtoupper(Str::random(6));
    }

    /**
     * 作用域：按状态筛选。
     */
    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 作用域：待审核（submitted + under_review）。
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW]);
    }
}
