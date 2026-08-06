<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Http\Controllers;

use MultiTenantSaas\Modules\Course\Services\CourseService;
use MultiTenantSaas\Modules\Course\Services\CourseLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;

/**
 * 课程模块
 *
 * Console（Operator）：课程 CRUD / 章节管理 / 上下架
 * H5（User）：已发布课程浏览/购买/学习进度上报/我的课程
 */
class CourseController
{
    public function __construct(
        protected CourseService $courseService,
        protected CourseLearningService $learningService,
    ) {}

    // ========== Console：课程管理 ==========

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->courseService->getList($tenantId, $request->only(['status', 'search', 'page', 'per_page'])),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'                    => 'required|string|max:255',
            'cover'                    => 'nullable|string|max:500',
            'description'              => 'nullable|string',
            'price'                    => 'nullable|numeric|min:0',
            'points_price'             => 'nullable|integer|min:0',
            'sale_mode'                => 'nullable|string|in:cash,points,mixed',
            'completion_reward_points' => 'nullable|integer|min:0',
            'metadata'                 => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $course = $this->courseService->create($tenantId, $validated);

        return response()->json(['success' => true, 'data' => $course], 201);
    }

    public function show(int $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $course = $this->courseService->find($tenantId, $id);

        return response()->json(['success' => true, 'data' => $course]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'title'                    => 'nullable|string|max:255',
            'cover'                    => 'nullable|string|max:500',
            'description'              => 'nullable|string',
            'price'                    => 'nullable|numeric|min:0',
            'points_price'             => 'nullable|integer|min:0',
            'sale_mode'                => 'nullable|string|in:cash,points,mixed',
            'completion_reward_points' => 'nullable|integer|min:0',
            'metadata'                 => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $course = $this->courseService->update($tenantId, $id, $validated);

        return response()->json(['success' => true, 'data' => $course]);
    }

    public function destroy(int $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->courseService->delete($tenantId, $id);

        return response()->json(['success' => true, 'message' => 'Course deleted']);
    }

    public function publish(int $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $course = $this->courseService->publish($tenantId, $id);

        return response()->json(['success' => true, 'data' => $course]);
    }

    public function offline(int $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $course = $this->courseService->offline($tenantId, $id);

        return response()->json(['success' => true, 'data' => $course]);
    }

    // ========== Console：章节管理 ==========

    public function chapters(int $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->courseService->getChapters($tenantId, $id),
        ]);
    }

    public function storeChapter(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'sort_order' => 'nullable|integer|min:0',
            'title'      => 'required|string|max:255',
            'type'       => 'nullable|string|in:text,video,file',
            'content'    => 'nullable|string',
            'file_url'   => 'nullable|string|max:500',
        ]);

        $tenantId = (int) TenantContext::getId();
        $chapter = $this->courseService->addChapter($tenantId, $id, $validated);

        return response()->json(['success' => true, 'data' => $chapter], 201);
    }

    public function updateChapter(Request $request, int $id, int $chapterId): JsonResponse
    {
        $validated = $request->validate([
            'sort_order' => 'nullable|integer|min:0',
            'title'      => 'nullable|string|max:255',
            'type'       => 'nullable|string|in:text,video,file',
            'content'    => 'nullable|string',
            'file_url'   => 'nullable|string|max:500',
        ]);

        $tenantId = (int) TenantContext::getId();
        $chapter = $this->courseService->updateChapter($tenantId, $id, $chapterId, $validated);

        return response()->json(['success' => true, 'data' => $chapter]);
    }

    public function destroyChapter(int $id, int $chapterId): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $this->courseService->deleteChapter($tenantId, $id, $chapterId);

        return response()->json(['success' => true, 'message' => 'Chapter deleted']);
    }

    // ========== C 端：浏览/购买/学习 ==========

    /**
     * 已发布课程列表（终端用户）
     */
    public function published(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        return response()->json([
            'success' => true,
            'data'    => $this->learningService->publishedList($tenantId, $request->only(['search', 'per_page'])),
        ]);
    }

    /**
     * 课程详情（未购买隐藏章节内容体）
     */
    public function detail(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $userId = $request->user()?->user_id;

        return response()->json([
            'success' => true,
            'data'    => $this->learningService->detail($tenantId, $id, $userId ? (int) $userId : null),
        ]);
    }

    /**
     * 购买课程（统一订单 order_type=course；免费课程即时完成）
     */
    public function purchase(Request $request, int $id): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $userId = $request->user()?->user_id;

        if (! $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $order = $this->learningService->purchase($tenantId, (int) $userId, $id, $request->only(['pay_method', 'points_to_use']));

        return response()->json(['success' => true, 'data' => $order], 201);
    }

    /**
     * 上报章节学习进度（完成全部章节时发放奖励积分，仅一次）
     */
    public function reportProgress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id'  => 'required|integer',
            'chapter_id' => 'required|integer',
        ]);

        $tenantId = (int) TenantContext::getId();
        $userId = $request->user()?->user_id;

        if (! $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $result = $this->learningService->reportProgress(
            $tenantId,
            (int) $userId,
            (int) $validated['course_id'],
            (int) $validated['chapter_id']
        );

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * 我的课程（权益 + 学习进度）
     */
    public function myCourses(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $userId = $request->user()?->user_id;

        if (! $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->learningService->myCourses($tenantId, (int) $userId),
        ]);
    }
}
