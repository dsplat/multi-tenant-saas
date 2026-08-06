<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Services;

use MultiTenantSaas\Modules\Course\Models\Course;
use MultiTenantSaas\Modules\Course\Models\CourseChapter;
use MultiTenantSaas\Modules\Product\Models\ProductSku;
use MultiTenantSaas\Modules\Product\Services\SkuService;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 课程管理服务（Console）
 *
 * 课程 CRUD / 章节管理 / 上下架；价格载体在课程本体（免费课程 price=0），
 * 同时镜像为 SKU（ref_type=course）供统一交易引用。
 */
class CourseService
{
    public function __construct(
        protected SkuService $skuService,
    ) {}

    // ========== 课程 CRUD ==========

    public function create(int $tenantId, array $data): Course
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = Course::create([
            'tenant_id'                => $tenantId,
            'title'                    => $data['title'],
            'cover'                    => $data['cover'] ?? null,
            'description'              => $data['description'] ?? null,
            'price'                    => $data['price'] ?? 0,
            'points_price'             => $data['points_price'] ?? 0,
            'sale_mode'                => $data['sale_mode'] ?? 'cash',
            'completion_reward_points' => $data['completion_reward_points'] ?? 0,
            'status'                   => Course::STATUS_DRAFT,
            'metadata'                 => $data['metadata'] ?? null,
        ]);

        $this->mirrorCourseSku($tenantId, $course);

        return $course;
    }

    public function update(int $tenantId, int $courseId, array $data): Course
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = $this->find($tenantId, $courseId);

        $fillable = [
            'title', 'cover', 'description', 'price', 'points_price',
            'sale_mode', 'completion_reward_points', 'metadata',
        ];
        $course->update(array_intersect_key($data, array_flip($fillable)));

        $this->mirrorCourseSku($tenantId, $course->fresh());

        return $course->fresh();
    }

    public function publish(int $tenantId, int $courseId): Course
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = $this->find($tenantId, $courseId);
        $course->update(['status' => Course::STATUS_PUBLISHED]);

        $this->mirrorCourseSku($tenantId, $course->fresh());

        return $course->fresh();
    }

    public function offline(int $tenantId, int $courseId): Course
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = $this->find($tenantId, $courseId);
        $course->update(['status' => Course::STATUS_OFFLINE]);

        $this->skuService->mirrorRetire($tenantId, ProductSku::REF_COURSE, $courseId);

        return $course->fresh();
    }

    public function delete(int $tenantId, int $courseId): void
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = $this->find($tenantId, $courseId);
        $course->delete();

        $this->skuService->mirrorRetire($tenantId, ProductSku::REF_COURSE, $courseId);
    }

    public function find(int $tenantId, int $courseId): Course
    {
        TenantContext::setTenantId((string) $tenantId);

        return Course::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
    }

    public function getList(int $tenantId, array $filters = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Course::where('tenant_id', $tenantId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->where('title', 'like', '%' . $filters['search'] . '%');
        }

        $paginator = $query->orderByDesc('created_at')
            ->paginate(
                $filters['per_page'] ?? 20,
                ['*'],
                'page',
                max(1, (int) ($filters['page'] ?? 1))
            );

        return [
            'data'      => $paginator->items(),
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    // ========== 章节管理 ==========

    public function getChapters(int $tenantId, int $courseId): array
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->find($tenantId, $courseId);

        return CourseChapter::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function addChapter(int $tenantId, int $courseId, array $data): CourseChapter
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->find($tenantId, $courseId);

        return CourseChapter::create([
            'tenant_id'  => $tenantId,
            'course_id'  => $courseId,
            'sort_order' => $data['sort_order'] ?? 0,
            'title'      => $data['title'],
            'type'       => $data['type'] ?? 'text',
            'content'    => $data['content'] ?? null,
            'file_url'   => $data['file_url'] ?? null,
        ]);
    }

    public function updateChapter(int $tenantId, int $courseId, int $chapterId, array $data): CourseChapter
    {
        TenantContext::setTenantId((string) $tenantId);

        $chapter = CourseChapter::where('chapter_id', $chapterId)
            ->where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $fillable = ['sort_order', 'title', 'type', 'content', 'file_url'];
        $chapter->update(array_intersect_key($data, array_flip($fillable)));

        return $chapter->fresh();
    }

    public function deleteChapter(int $tenantId, int $courseId, int $chapterId): void
    {
        TenantContext::setTenantId((string) $tenantId);

        $chapter = CourseChapter::where('chapter_id', $chapterId)
            ->where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $chapter->delete();
    }

    // ========== SKU 镜像 ==========

    protected function mirrorCourseSku(int $tenantId, Course $course): void
    {
        $this->skuService->mirrorUpsert(
            $tenantId,
            ProductSku::REF_COURSE,
            $course->course_id,
            [
                'name'         => $course->title,
                'price'        => $course->price,
                'points_price' => $course->points_price,
                'spec_attrs'   => ['course_id' => $course->course_id],
                'status'       => $course->isPublished() ? 'active' : 'inactive',
            ]
        );
    }
}
