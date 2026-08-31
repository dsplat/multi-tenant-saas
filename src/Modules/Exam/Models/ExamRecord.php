<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 答卷记录（一次考试尝试）
 *
 * questions_snapshot 在开考时固化题目+标准答案，
 * 题库后续变更不污染历史答卷的判分。
 */
class ExamRecord extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    protected $table = 'exam_records';

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'tenant_id', 'exam_id', 'user_id', 'attempt', 'questions_snapshot',
        'answers', 'objective_score', 'total_score', 'passed', 'status',
        'started_at', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'questions_snapshot' => 'array',
            'answers' => 'array',
            'objective_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'passed' => 'boolean',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }
}
