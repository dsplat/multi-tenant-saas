<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 课程章节（text | video | audio | file）
 *
 * unlock_rule 统一解锁规则 schema（与打卡任务/训练营共用）：
 * {"mode": "time|sequence|prerequisite", "config": {...}}
 * NULL = 无限制（随课程权益直接可学）。
 */
class CourseChapter extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    // 章节类型（audio 对标小鹅通音频课）
    public const TYPE_TEXT = 'text';

    public const TYPE_VIDEO = 'video';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_FILE = 'file';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_VIDEO, self::TYPE_AUDIO, self::TYPE_FILE];

    // 解锁模式（与 ActivityTask 学员侧/打卡任务共用规则语义）
    public const UNLOCK_TIME = 'time';

    public const UNLOCK_SEQUENCE = 'sequence';

    public const UNLOCK_PREREQUISITE = 'prerequisite';

    protected $table = 'course_chapters';

    protected $primaryKey = 'chapter_id';

    protected $fillable = [
        'tenant_id', 'course_id', 'sort_order', 'title', 'type', 'content', 'file_url', 'unlock_rule',
    ];

    protected function casts(): array
    {
        return [
            'sort_order'  => 'integer',
            'unlock_rule' => 'array',
        ];
    }

    /**
     * 章节是否已解锁（基于学员已完成章节集合）
     *
     * @param array<int> $completedChapterIds 学员已完成章节 ID 列表
     * @param int|null   $prevChapterId       上一章节 ID（sequence 模式用）
     */
    public function isUnlocked(array $completedChapterIds, ?int $prevChapterId = null): bool
    {
        $rule = $this->unlock_rule;
        $mode = is_array($rule) ? ($rule['mode'] ?? null) : null;

        return match ($mode) {
            self::UNLOCK_TIME => isset($rule['config']['unlock_at']) && now()->greaterThanOrEqualTo($rule['config']['unlock_at']),
            self::UNLOCK_SEQUENCE => $prevChapterId === null || in_array($prevChapterId, $completedChapterIds, true),
            self::UNLOCK_PREREQUISITE => collect($rule['config']['chapter_ids'] ?? [])
                ->every(fn ($id) => in_array((int) $id, $completedChapterIds, true)),
            default => true,
        };
    }
}
