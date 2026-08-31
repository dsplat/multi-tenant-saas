<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Course\Services;

use MultiTenantSaas\Modules\Course\Contracts\CourseCompletionRewardContract;
use MultiTenantSaas\Modules\Course\Events\CourseCompleted;
use MultiTenantSaas\Modules\Course\Models\Course;
use MultiTenantSaas\Modules\Course\Models\CourseChapter;
use MultiTenantSaas\Modules\Course\Models\CourseEntitlement;
use MultiTenantSaas\Modules\Course\Models\LearningRecord;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Services\OrderService;
use MultiTenantSaas\Context\TenantContext;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 课程 C 端服务（User 认证分组）
 *
 * 已发布课程列表/详情；购买走统一订单（order_type=course）；
 * 学习进度上报；学完奖励通过 CourseCompletionRewardContract 钩子发放（默认不发放）。
 */
class CourseLearningService
{
    public function __construct(
        protected OrderService $orderService,
        protected CourseCompletionRewardContract $completionReward,
    ) {}

    // ========== 浏览 ==========

    public function publishedList(int $tenantId, array $filters = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = Course::where('tenant_id', $tenantId)
            ->where('status', Course::STATUS_PUBLISHED);

        if (! empty($filters['search'])) {
            $query->where('title', 'like', '%' . $filters['search'] . '%');
        }

        $paginator = $query->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 20);

        return [
            'data'      => $paginator->items(),
            'total'     => $paginator->total(),
            'page'      => $paginator->currentPage(),
            'per_page'  => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * 课程详情（含章节；未购买时隐藏内容体，仅返回标题）
     */
    public function detail(int $tenantId, int $courseId, ?int $userId = null): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = Course::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->where('status', Course::STATUS_PUBLISHED)
            ->firstOrFail();

        $hasAccess = $course->isFree() || ($userId && $this->hasAccess($tenantId, $userId, $courseId));

        // 学员已完成章节集合（用于 unlock_rule 解锁判定）
        $completedChapterIds = [];
        if ($hasAccess && $userId) {
            $completedChapterIds = LearningRecord::where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->value('completed_chapters') ?? [];
        }

        $prevChapterId = null;
        $chapters = CourseChapter::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($chapter) use ($hasAccess, $completedChapterIds, &$prevChapterId) {
                $chapter->is_unlocked = $chapter->isUnlocked($completedChapterIds, $prevChapterId);
                $prevChapterId = (int) $chapter->chapter_id;

                // 无权限或章节未解锁：隐藏内容体，仅返回标题/解锁状态
                if (! $hasAccess || ! $chapter->is_unlocked) {
                    unset($chapter->content, $chapter->file_url);
                }

                return $chapter;
            })
            ->all();

        return [
            'course'     => $course,
            'chapters'   => $chapters,
            'has_access' => (bool) $hasAccess,
        ];
    }

    // ========== 购买（统一订单） ==========

    /**
     * 购买课程 → 创建 order_type=course 订单
     *
     * 免费课程：0 元订单即时确认并授予权益。
     */
    public function purchase(int $tenantId, int $userId, int $courseId, array $options = []): Order
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = Course::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->where('status', Course::STATUS_PUBLISHED)
            ->firstOrFail();

        if ($this->hasAccess($tenantId, $userId, $courseId)) {
            throw new UnprocessableEntityHttpException('Course already purchased');
        }

        $payMethod = $options['pay_method'] ?? ($course->isFree() ? Order::PAY_CASH : $course->sale_mode);

        $order = $this->orderService->createForEntity($tenantId, $userId, $course, [
            'order_type'    => Order::TYPE_COURSE,
            'pay_method'    => $payMethod,
            'points_to_use' => $options['points_to_use'] ?? 0,
            'items'         => [[
                'item_name'         => $course->title,
                'unit_price'        => (float) $course->price,
                'points_unit_price' => (int) $course->points_price,
                'quantity'          => 1,
            ]],
            'metadata'      => ['course_id' => $courseId],
        ]);

        // 免费课程：即时确认（0 元直接 paid + 授予权益）
        if ($course->isFree()) {
            $this->orderService->initiatePayment($tenantId, $order->order_no);
        }

        return $order->fresh()->load('items');
    }

    /**
     * 授予课程权益（幂等；由 OrderService 支付确认履约时调用）
     *
     * source 默认按 order_id 推断（有单=order，无单=free）；
     * 迁移导入/补偿/订阅场景显式传入。
     */
    public function grantEntitlement(
        int $tenantId,
        int $userId,
        int $courseId,
        ?int $orderId = null,
        ?string $source = null,
        ?\DateTimeInterface $validUntil = null
    ): void {
        TenantContext::setTenantId((string) $tenantId);

        CourseEntitlement::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'course_id' => $courseId,
            ],
            [
                'order_id'    => $orderId,
                'source'      => $source ?? ($orderId !== null ? CourseEntitlement::SOURCE_ORDER : CourseEntitlement::SOURCE_FREE),
                'valid_until' => $validUntil,
            ]
        );
    }

    public function hasAccess(int $tenantId, int $userId, int $courseId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        return CourseEntitlement::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
            })
            ->exists();
    }

    /**
     * 付费问答（qa 形态）：学员是否有提问资格（对标小鹅通付费问答）
     *
     * 问答内容本身走项目层 submissions（subject_type='course'），
     * 付费门槛=课程订单权益：免费课程开放提问，付费课程需有效权益。
     */
    public function canAskInQa(int $tenantId, int $userId, int $courseId): bool
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = Course::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (! $course->isQa()) {
            throw new UnprocessableEntityHttpException('Course is not in qa format');
        }

        return $course->isFree() || $this->hasAccess($tenantId, $userId, $courseId);
    }

    // ========== 学习记录 ==========

    /**
     * 上报章节完成 → 累计进度；全部完成时发放奖励积分（仅一次）
     */
    public function reportProgress(int $tenantId, int $userId, int $courseId, int $chapterId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $course = Course::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (! $course->isFree() && ! $this->hasAccess($tenantId, $userId, $courseId)) {
            throw new UnprocessableEntityHttpException('No access to this course');
        }

        // 校验章节属于该课程
        $chapter = CourseChapter::where('chapter_id', $chapterId)
            ->where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // 校验章节已解锁（unlock_rule：time/sequence/prerequisite，未配置=无限制）
        $completedIds = LearningRecord::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->value('completed_chapters') ?? [];
        $prevChapterId = CourseChapter::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->where('sort_order', '<', $chapter->sort_order)
            ->orderByDesc('sort_order')
            ->value('chapter_id');
        if (! $chapter->isUnlocked($completedIds, $prevChapterId !== null ? (int) $prevChapterId : null)) {
            throw new UnprocessableEntityHttpException('Chapter is locked');
        }

        $totalChapters = CourseChapter::where('course_id', $courseId)
            ->where('tenant_id', $tenantId)
            ->count();

        $record = LearningRecord::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'course_id' => $courseId,
            ],
            ['progress' => 0, 'completed_chapters' => []]
        );

        $completed = array_values(array_unique(array_merge(
            $record->completed_chapters ?? [],
            [$chapterId]
        )));

        $progress = $totalChapters > 0 ? (int) floor(count($completed) / $totalChapters * 100) : 100;
        $isComplete = $totalChapters > 0 && count($completed) >= $totalChapters;

        $wasCompleted = $record->isCompleted();

        $record->update([
            'chapter_id'         => $chapterId,
            'progress'           => $progress,
            'completed_chapters' => $completed,
            'completed_at'       => $isComplete && ! $wasCompleted ? now() : $record->completed_at,
        ]);

        // 学完奖励（仅首次完成发放，具体实现由项目层钩子提供）
        $rewardGranted = 0;
        if ($isComplete && ! $wasCompleted && (int) $course->completion_reward_points > 0) {
            $rewardGranted = $this->completionReward->reward($tenantId, $userId, $course);
        }

        // 首次完成 → 派发课程完成事件（行为埋点数据源）
        if ($isComplete && ! $wasCompleted) {
            event(new CourseCompleted($tenantId, $userId, (int) $course->course_id, $course->title));
        }

        return [
            'record'         => $record->fresh(),
            'completed_now'  => $isComplete && ! $wasCompleted,
            'reward_granted' => $rewardGranted,
        ];
    }

    /**
     * 我的课程（权益 + 学习进度）
     */
    public function myCourses(int $tenantId, int $userId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $entitlements = CourseEntitlement::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->get();

        $records = LearningRecord::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->get()
            ->keyBy('course_id');

        $result = [];
        foreach ($entitlements as $ent) {
            $course = Course::find($ent->course_id);
            if (! $course) {
                continue;
            }

            $record = $records->get($ent->course_id);
            $result[] = [
                'course'   => $course,
                'progress' => $record?->progress ?? 0,
                'completed_at' => $record?->completed_at,
            ];
        }

        return $result;
    }
}
