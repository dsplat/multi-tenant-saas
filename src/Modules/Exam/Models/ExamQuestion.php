<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 考试题目（一期客观题三型：单选/多选/判断）
 *
 * answer 结构约定：
 * - single：int（正确选项下标）
 * - multi：int[]（正确选项下标集）
 * - judge：bool（true=正确）
 */
class ExamQuestion extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    public const TYPE_SINGLE = 'single';

    public const TYPE_MULTI = 'multi';

    public const TYPE_JUDGE = 'judge';

    public const TYPES = [self::TYPE_SINGLE, self::TYPE_MULTI, self::TYPE_JUDGE];

    public const DIFFICULTIES = ['easy', 'normal', 'hard'];

    protected $table = 'exam_questions';

    protected $primaryKey = 'question_id';

    protected $fillable = [
        'tenant_id', 'bank_id', 'type', 'content', 'options', 'answer',
        'analysis', 'score', 'difficulty',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'answer' => 'array',
            'score' => 'decimal:2',
        ];
    }
}
