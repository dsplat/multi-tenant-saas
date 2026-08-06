<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 课程章节（text | video | file）
 */
class CourseChapter extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    protected $table = 'course_chapters';

    protected $primaryKey = 'chapter_id';

    protected $fillable = [
        'tenant_id', 'course_id', 'sort_order', 'title', 'type', 'content', 'file_url',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
