<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 试卷（组卷规则 JSON 化：固定题序 或 按题库+题型随机抽题）
 *
 * compose_rule 结构：
 * - 固定卷：{mode: 'fixed', question_ids: [id, ...]}
 * - 随机卷：{mode: 'random', rules: [{bank_id, type, count}, ...]}
 */
class Exam extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CLOSED = 'closed';

    public const COMPOSE_FIXED = 'fixed';

    public const COMPOSE_RANDOM = 'random';

    protected $table = 'exams';

    protected $primaryKey = 'exam_id';

    protected $fillable = [
        'tenant_id', 'title', 'compose_rule', 'total_score', 'pass_score',
        'time_limit_minutes', 'retry_limit', 'status',
    ];

    protected function casts(): array
    {
        return [
            'compose_rule' => 'array',
            'total_score' => 'decimal:2',
            'pass_score' => 'decimal:2',
        ];
    }
}
