<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 练习记录（错题重练/题库练习，即时判分，不产生正式答卷）
 */
class ExamPracticeRecord extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    public const SOURCE_WRONG = 'wrong';

    public const SOURCE_BANK = 'bank';

    protected $table = 'exam_practice_records';

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'source', 'bank_id', 'exam_id',
        'question_ids', 'correct_count', 'total_count',
    ];

    protected function casts(): array
    {
        return [
            'question_ids' => 'array',
        ];
    }
}
