<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Exam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 题库（题目的组织单元，组卷按题库+题型抽题）
 */
class ExamQuestionBank extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    protected $table = 'exam_question_banks';

    protected $primaryKey = 'bank_id';

    protected $fillable = [
        'tenant_id', 'name', 'description',
    ];
}
