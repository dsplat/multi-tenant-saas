<?php

namespace MultiTenantSaas\Tests\Course;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Course\Models\Course;
use MultiTenantSaas\Modules\Course\Services\Tools\CourseListHandler;
use MultiTenantSaas\Modules\Course\Services\Tools\CreateCourseHandler;
use MultiTenantSaas\Modules\Course\Services\Tools\UpdateCourseHandler;
use MultiTenantSaas\Modules\Product\Models\ProductSku;
use MultiTenantSaas\Tests\Schema\CourseModule;
use MultiTenantSaas\Tests\Schema\ProductModule;
use MultiTenantSaas\Tests\TestCase;

/**
 * 课程管理 AI 工具测试：注册完整性 + 创建/查询/更新/发布下线 直调全链路
 */
class CourseToolsTest extends TestCase
{
    protected array $uses = [
        ProductModule::class,
        CourseModule::class,
    ];

    protected const TENANT_ID = 3401;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::setTenantId((string) self::TENANT_ID);
    }

    public function test_course_tools_are_registered(): void
    {
        $registry = $this->app->make(ToolRegistryContract::class);

        foreach (['course_list', 'create_course', 'update_course'] as $slug) {
            $this->assertNotNull($registry->get($slug), "工具 {$slug} 未注册");
        }
    }

    public function test_create_course_creates_and_mirrors_sku(): void
    {
        $course = (new CreateCourseHandler($this->app->make(\MultiTenantSaas\Modules\Course\Services\CourseService::class)))(
            ['title' => 'AI 工具创建课程', 'price' => 199, 'sale_mode' => 'cash'],
            self::TENANT_ID
        );

        $this->assertInstanceOf(Course::class, $course);
        $this->assertEquals('AI 工具创建课程', $course->title);
        $this->assertEquals(Course::STATUS_DRAFT, $course->status);

        // 镜像 SKU 落库（课程商品经统一 SKU 池可下单）
        $sku = ProductSku::where('tenant_id', self::TENANT_ID)
            ->where('ref_type', ProductSku::REF_COURSE)
            ->where('ref_id', $course->course_id)
            ->first();
        $this->assertNotNull($sku);
        $this->assertEquals(199, (float) $sku->price);
    }

    public function test_course_list_lists_and_detail(): void
    {
        $service = $this->app->make(\MultiTenantSaas\Modules\Course\Services\CourseService::class);
        $created = (new CreateCourseHandler($service))(['title' => '列表测试课程'], self::TENANT_ID);

        $handler = new CourseListHandler($service);

        // 列表
        $list = $handler(['per_page' => 10], self::TENANT_ID);
        $this->assertArrayHasKey('data', $list);
        $this->assertGreaterThanOrEqual(1, $list['total']);
        $this->assertEquals($created->title, $list['data'][0]->title);

        // 详情
        $detail = $handler(['course_id' => $created->course_id], self::TENANT_ID);
        $this->assertInstanceOf(Course::class, $detail);
        $this->assertEquals($created->course_id, $detail->course_id);
    }

    public function test_update_course_updates_fields(): void
    {
        $service = $this->app->make(\MultiTenantSaas\Modules\Course\Services\CourseService::class);
        $created = (new CreateCourseHandler($service))(['title' => '待更新课程', 'price' => 100], self::TENANT_ID);

        $updated = (new UpdateCourseHandler($service))(
            ['course_id' => $created->course_id, 'title' => '已更新标题', 'price' => 88],
            self::TENANT_ID
        );

        $this->assertEquals('已更新标题', $updated->title);
        $this->assertEquals(88, (float) $updated->price);
    }

    public function test_update_course_publish_and_offline(): void
    {
        $service = $this->app->make(\MultiTenantSaas\Modules\Course\Services\CourseService::class);
        $created = (new CreateCourseHandler($service))(['title' => '发布下线课程'], self::TENANT_ID);

        $handler = new UpdateCourseHandler($service);

        $published = $handler(['course_id' => $created->course_id, 'action' => 'publish'], self::TENANT_ID);
        $this->assertEquals(Course::STATUS_PUBLISHED, $published->status);

        $offlined = $handler(['course_id' => $created->course_id, 'action' => 'offline'], self::TENANT_ID);
        $this->assertEquals(Course::STATUS_OFFLINE, $offlined->status);
    }

    public function test_update_course_rejects_unknown_action(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = $this->app->make(\MultiTenantSaas\Modules\Course\Services\CourseService::class);
        $created = (new CreateCourseHandler($service))(['title' => '非法动作课程'], self::TENANT_ID);

        (new UpdateCourseHandler($service))(['course_id' => $created->course_id, 'action' => 'delete'], self::TENANT_ID);
    }
}
