<?php

namespace MultiTenantSaas\Tests\Course;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Course\Contracts\CourseCompletionRewardContract;
use MultiTenantSaas\Modules\Course\Models\Course;
use MultiTenantSaas\Modules\Course\Models\CourseEntitlement;
use MultiTenantSaas\Modules\Course\Services\CourseService;
use MultiTenantSaas\Modules\Course\Services\CourseLearningService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Order\Models\Order;
use MultiTenantSaas\Modules\Order\Services\OrderService;
use MultiTenantSaas\Modules\Pay\Contracts\VirtualPayChannelContract;
use MultiTenantSaas\Modules\Pay\Services\VirtualPayChannelRegistry;
use MultiTenantSaas\Modules\Product\Models\ProductSku;
use MultiTenantSaas\Tests\Schema\CourseModule;
use MultiTenantSaas\Tests\Schema\OrderModule;
use MultiTenantSaas\Tests\Schema\PayModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Course 模块测试：课程 CRUD、镜像 SKU、课程履约（权益授予幂等）
 */
class CourseModuleTest extends TestCase
{
    protected array $uses = [
        ProductModule::class,
        PayModule::class,
        OrderModule::class,
        CourseModule::class,
    ];

    protected const TENANT_ID = 3301;

    protected CourseService $courseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseService = $this->app->make(CourseService::class);

        Tenant::create([
            'tenant_id' => self::TENANT_ID,
            'name' => 'Course Tenant',
            'slug' => 'course-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId((string) self::TENANT_ID);
    }

    public function test_create_publish_course_and_mirror_sku(): void
    {
        $course = $this->courseService->create(self::TENANT_ID, [
            'title' => '测试课程',
            'price' => 199,
            'points_price' => 1990,
        ]);

        $this->assertNotNull($course->course_id);
        $this->assertSame(Course::STATUS_DRAFT, $course->status);

        // 镜像 SKU 同步创建
        $mirror = ProductSku::where('ref_type', ProductSku::REF_COURSE)
            ->where('ref_id', $course->course_id)
            ->first();
        $this->assertNotNull($mirror);
        $this->assertTrue($mirror->isMirror());

        $published = $this->courseService->publish(self::TENANT_ID, $course->course_id);
        $this->assertSame(Course::STATUS_PUBLISHED, $published->status);

        // 镜像 SKU 状态跟随
        $this->assertTrue($mirror->fresh()->isActive());

        $offline = $this->courseService->offline(self::TENANT_ID, $course->course_id);
        $this->assertNotSame(Course::STATUS_PUBLISHED, $offline->status);
    }

    public function test_add_chapter_under_course(): void
    {
        $course = $this->courseService->create(self::TENANT_ID, ['title' => '章节课程']);

        $chapter = $this->courseService->addChapter(self::TENANT_ID, $course->course_id, [
            'title' => '第一章',
            'sort' => 1,
        ]);

        $this->assertNotNull($chapter->chapter_id);

        $chapters = $this->courseService->getChapters(self::TENANT_ID, $course->course_id);
        $this->assertCount(1, $chapters);
    }

    public function test_course_fulfillment_grants_entitlement_via_order(): void
    {
        // 注册虚拟渠道供积分支付
        $channel = new CourseFakeChannel(2000);
        $this->app->make(VirtualPayChannelRegistry::class)->register($channel);

        $course = $this->courseService->create(self::TENANT_ID, [
            'title' => '履约课程',
            'points_price' => 800,
        ]);

        $orderService = $this->app->make(OrderService::class);
        $order = $orderService->createOrder(self::TENANT_ID, 9, [
            'order_type' => Order::TYPE_COURSE,
            'pay_method' => Order::PAY_POINTS,
            'entity_type' => 'course',
            'entity_id' => (string) $course->course_id,
            'items' => [[
                'item_name' => $course->title,
                'points_unit_price' => 800,
                'quantity' => 1,
            ]],
        ]);

        $orderService->initiatePayment(self::TENANT_ID, $order->order_no);

        // CourseFulfillmentHandler（Provider boot 注册）已授予权益
        $this->assertSame(1, CourseEntitlement::where('course_id', $course->course_id)->count());
        $entitlement = CourseEntitlement::where('course_id', $course->course_id)->first();
        $this->assertSame(9, (int) $entitlement->user_id);

        // 幂等：重复 confirmPayment 不重复授予
        $orderService->confirmPayment($order->order_no);
        $this->assertSame(1, CourseEntitlement::where('course_id', $course->course_id)->count());
    }

    public function test_purchase_fills_order_level_entity(): void
    {
        // 免费课：purchase 走 createForEntity，订单级 entity_type/entity_id 必须填充
        $course = $this->courseService->create(self::TENANT_ID, [
            'title' => '实体绑定验证课',
            'price' => 0,
            'sale_mode' => 'cash',
        ]);
        $this->courseService->publish(self::TENANT_ID, $course->course_id);

        $learning = $this->app->make(CourseLearningService::class);
        $order = $learning->purchase(self::TENANT_ID, 9, $course->course_id);

        $this->assertSame('course', $order->entity_type);
        $this->assertSame((string) $course->course_id, $order->entity_id);
    }

    public function test_learning_completion_invokes_reward_hook(): void
    {
        $course = $this->courseService->create(self::TENANT_ID, [
            'title' => '奖励课程',
            'completion_reward_points' => 50,
        ]);
        $this->courseService->publish(self::TENANT_ID, $course->course_id);

        $chapter = $this->courseService->addChapter(self::TENANT_ID, $course->course_id, [
            'title' => '唯一章节',
            'sort' => 1,
        ]);

        $learning = $this->app->make(CourseLearningService::class);

        // 默认实现：不发放奖励
        $result = $learning->reportProgress(self::TENANT_ID, 7, (int) $course->course_id, (int) $chapter->chapter_id);
        $this->assertTrue($result['completed_now']);
        $this->assertSame(0, $result['reward_granted']);

        // 项目层钩子覆盖绑定后发放奖励
        $this->app->singleton(CourseCompletionRewardContract::class, CourseFakeReward::class);
        $course2 = $this->courseService->create(self::TENANT_ID, [
            'title' => '钩子课程',
            'completion_reward_points' => 50,
        ]);
        $this->courseService->publish(self::TENANT_ID, $course2->course_id);
        $chapter2 = $this->courseService->addChapter(self::TENANT_ID, $course2->course_id, [
            'title' => '唯一章节',
            'sort' => 1,
        ]);

        $result2 = $this->app->make(CourseLearningService::class)
            ->reportProgress(self::TENANT_ID, 7, (int) $course2->course_id, (int) $chapter2->chapter_id);
        $this->assertSame(50, $result2['reward_granted']);
    }
}

/** 测试用奖励钩子 */
class CourseFakeReward implements CourseCompletionRewardContract
{
    public function reward(int $tenantId, int $userId, Course $course): int
    {
        return (int) $course->completion_reward_points;
    }
}

/** 测试用虚拟渠道 */
class CourseFakeChannel implements VirtualPayChannelContract
{
    public function __construct(public int $balance) {}

    public function name(): string
    {
        return 'points';
    }

    public function getBalance(int $tenantId, int $userId): int
    {
        return $this->balance;
    }

    public function consume(int $tenantId, int $userId, int $amount, string $orderNo): void
    {
        if ($this->balance < $amount) {
            throw new UnprocessableEntityHttpException('Insufficient virtual balance');
        }
        $this->balance -= $amount;
    }

    public function refund(int $tenantId, int $userId, int $amount, string $orderNo): void
    {
        $this->balance += $amount;
    }
}
