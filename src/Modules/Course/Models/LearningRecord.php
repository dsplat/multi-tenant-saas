<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 学习记录（每用户每课程一行，进度累计）
 */
class LearningRecord extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    protected $table = 'learning_records';

    protected $primaryKey = 'record_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'course_id', 'chapter_id',
        'progress', 'completed_chapters', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress'           => 'integer',
            'completed_chapters' => 'array',
            'completed_at'       => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
