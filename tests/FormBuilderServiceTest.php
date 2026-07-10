<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Modules\Form\Models\Form;
use MultiTenantSaas\Modules\Form\Services\FormBuilderService;
use MultiTenantSaas\Tests\Schema\FormModule;

class FormBuilderServiceTest extends TestCase
{
    protected array $uses = [FormModule::class];

    protected FormBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FormBuilderService;

        Tenant::create([
            'tenant_id' => 3001,
            'name' => 'Form Tenant A',
            'slug' => 'form-tenant-a',
            'status' => 'active',
        ]);

        TenantContext::setTenantId(3001);
    }

    // ---------- 表单管理 ----------

    public function test_create_form(): void
    {
        $form = $this->service->createForm([
            'title' => '用户反馈表',
            'description' => '收集用户反馈',
            'status' => 'draft',
            'fields' => [
                [
                    'field_key' => 'name',
                    'field_type' => 'text',
                    'label' => '姓名',
                    'is_required' => true,
                    'sort_order' => 0,
                ],
                [
                    'field_key' => 'email',
                    'field_type' => 'email',
                    'label' => '邮箱',
                    'is_required' => true,
                    'sort_order' => 1,
                ],
                [
                    'field_key' => 'rating',
                    'field_type' => 'rating',
                    'label' => '评分',
                    'is_required' => false,
                    'sort_order' => 2,
                ],
            ],
        ], 3001);

        $this->assertNotNull($form->form_id);
        $this->assertEquals('用户反馈表', $form->title);
        $this->assertEquals(3, $form->fields->count());
    }

    public function test_update_form(): void
    {
        $form = $this->createForm();

        $updated = $this->service->updateForm($form, [
            'title' => '新标题',
        ]);

        $this->assertEquals('新标题', $updated->title);
    }

    public function test_update_form_fields(): void
    {
        $form = $this->createForm();

        $updated = $this->service->updateForm($form, [
            'fields' => [
                [
                    'field_key' => 'question',
                    'field_type' => 'textarea',
                    'label' => '问题',
                    'is_required' => true,
                ],
            ],
        ]);

        $this->assertEquals(1, $updated->fields->count());
        $this->assertEquals('question', $updated->fields->first()->field_key);
    }

    public function test_get_forms(): void
    {
        $this->createForm(['title' => '表单1']);
        $this->createForm(['title' => '表单2']);

        $forms = $this->service->getForms(3001);

        $this->assertCount(2, $forms);
    }

    public function test_get_forms_filter_by_status(): void
    {
        $this->createForm(['title' => '草稿', 'status' => 'draft']);
        $this->createForm(['title' => '已发布', 'status' => 'published']);

        $forms = $this->service->getForms(3001, ['status' => 'published']);

        $this->assertCount(1, $forms);
        $this->assertEquals('已发布', $forms->first()->title);
    }

    // ---------- 表单提交 ----------

    public function test_submit_form(): void
    {
        $form = $this->createForm(['status' => 'published']);

        $submission = $this->service->submitForm($form->form_id, [
            'name' => '张三',
            'email' => 'zhangsan@example.com',
            'rating' => 5,
        ], 1001, 3001);

        $this->assertNotNull($submission->submission_id);
        $this->assertEquals('张三', $submission->data['name']);
        $this->assertEquals('zhangsan@example.com', $submission->data['email']);
    }

    public function test_submit_form_not_published_throws(): void
    {
        $form = $this->createForm(['status' => 'draft']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(trans('form.form_not_published'));

        $this->service->submitForm($form->form_id, ['name' => 'test'], 1001);
    }

    public function test_submit_form_expired_throws(): void
    {
        $form = $this->createForm([
            'status' => 'published',
            'start_at' => now()->subDays(7),
            'end_at' => now()->subDay(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(trans('form.form_ended'));

        $this->service->submitForm($form->form_id, ['name' => 'test'], 1001);
    }

    public function test_submit_form_not_started_throws(): void
    {
        $form = $this->createForm([
            'status' => 'published',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(7),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(trans('form.form_not_started'));

        $this->service->submitForm($form->form_id, ['name' => 'test'], 1001);
    }

    public function test_submit_form_required_field_throws(): void
    {
        $form = $this->createForm(['status' => 'published']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(trans('form.field_required', ['field' => '姓名']));

        $this->service->submitForm($form->form_id, [
            'email' => 'test@example.com',
        ], 1001);
    }

    public function test_submit_form_limit_exceeded_throws(): void
    {
        $form = $this->createForm([
            'status' => 'published',
            'submit_limit' => 1,
        ]);

        // 第一次提交成功
        $this->service->submitForm($form->form_id, [
            'name' => '用户1',
            'email' => 'user1@example.com',
        ], 1001);

        // 第二次提交失败
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(trans('form.form_submit_limit'));

        $this->service->submitForm($form->form_id, [
            'name' => '用户2',
            'email' => 'user2@example.com',
        ], 1002);
    }

    // ---------- 查询与统计 ----------

    public function test_get_submissions(): void
    {
        $form = $this->createForm(['status' => 'published']);

        $this->service->submitForm($form->form_id, [
            'name' => '用户1',
            'email' => 'user1@example.com',
        ], 1001);

        $this->service->submitForm($form->form_id, [
            'name' => '用户2',
            'email' => 'user2@example.com',
        ], 1002);

        $submissions = $this->service->getSubmissions($form->form_id);

        $this->assertCount(2, $submissions);
    }

    public function test_get_statistics(): void
    {
        $form = $this->createForm(['status' => 'published']);

        $this->service->submitForm($form->form_id, [
            'name' => '用户1',
            'email' => 'user1@example.com',
        ], 1001);

        $stats = $this->service->getStatistics($form->form_id);

        $this->assertEquals(1, $stats['total_submissions']);
        $this->assertEquals(1, $stats['today_submissions']);
    }

    public function test_export_data(): void
    {
        $form = $this->createForm(['status' => 'published']);

        $this->service->submitForm($form->form_id, [
            'name' => '张三',
            'email' => 'zhangsan@example.com',
            'rating' => 5,
        ], 1001);

        $data = $this->service->exportData($form->form_id);

        $this->assertEquals('用户反馈表', $data['form_title']);
        $this->assertEquals(1, $data['total']);
        $this->assertEquals('张三', $data['rows'][0]['name']);
    }

    // ---------- 新增字段类型 ----------

    public function test_submit_signature_field(): void
    {
        $form = $this->createForm([
            'status' => 'published',
            'fields' => [
                [
                    'field_key' => 'signature',
                    'field_type' => 'signature',
                    'label' => '签名',
                    'is_required' => true,
                ],
            ],
        ]);

        $submission = $this->service->submitForm($form->form_id, [
            'signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        ], 1001);

        $this->assertNotNull($submission->submission_id);
    }

    public function test_submit_location_field(): void
    {
        $form = $this->createForm([
            'status' => 'published',
            'fields' => [
                [
                    'field_key' => 'location',
                    'field_type' => 'location',
                    'label' => '位置',
                    'is_required' => true,
                ],
            ],
        ]);

        $submission = $this->service->submitForm($form->form_id, [
            'location' => ['lat' => 39.9042, 'lng' => 116.4074, 'address' => '北京市'],
        ], 1001);

        $this->assertEquals(39.9042, $submission->data['location']['lat']);
    }

    public function test_submit_location_invalid_lat_throws(): void
    {
        $form = $this->createForm([
            'status' => 'published',
            'fields' => [
                [
                    'field_key' => 'location',
                    'field_type' => 'location',
                    'label' => '位置',
                    'is_required' => true,
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('纬度范围不正确');

        $this->service->submitForm($form->form_id, [
            'location' => ['lat' => 999, 'lng' => 116.4074],
        ], 1001);
    }

    public function test_submit_cascader_field(): void
    {
        $form = $this->createForm([
            'status' => 'published',
            'fields' => [
                [
                    'field_key' => 'region',
                    'field_type' => 'cascader',
                    'label' => '地区',
                    'is_required' => true,
                    'options' => [
                        ['value' => 'guangdong', 'label' => '广东', 'children' => [
                            ['value' => 'shenzhen', 'label' => '深圳'],
                            ['value' => 'guangzhou', 'label' => '广州'],
                        ]],
                        ['value' => 'zhejiang', 'label' => '浙江', 'children' => [
                            ['value' => 'hangzhou', 'label' => '杭州'],
                        ]],
                    ],
                ],
            ],
        ]);

        $submission = $this->service->submitForm($form->form_id, [
            'region' => ['guangdong', 'shenzhen'],
        ], 1001);

        $this->assertEquals(['guangdong', 'shenzhen'], $submission->data['region']);
    }

    public function test_submit_cascader_invalid_option_throws(): void
    {
        $form = $this->createForm([
            'status' => 'published',
            'fields' => [
                [
                    'field_key' => 'region',
                    'field_type' => 'cascader',
                    'label' => '地区',
                    'is_required' => true,
                    'options' => [
                        ['value' => 'guangdong', 'label' => '广东'],
                    ],
                ],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('第 1 级选择值无效');

        $this->service->submitForm($form->form_id, [
            'region' => ['invalid'],
        ], 1001);
    }

    // ---------- 租户隔离 ----------

    public function test_forms_isolated_by_tenant(): void
    {
        Tenant::create([
            'tenant_id' => 3002,
            'name' => 'Form Tenant B',
            'slug' => 'form-tenant-b',
            'status' => 'active',
        ]);

        $this->createForm(['title' => '租户A表单'], 3001);

        TenantContext::setTenantId(3002);
        $this->createForm(['title' => '租户B表单'], 3002);

        $tenantAForms = Form::withoutGlobalScopes()->where('tenant_id', 3001)->count();
        $tenantBForms = Form::withoutGlobalScopes()->where('tenant_id', 3002)->count();

        $this->assertEquals(1, $tenantAForms);
        $this->assertEquals(1, $tenantBForms);
    }

    // ---------- 辅助方法 ----------

    private function createForm(array $overrides = [], ?int $tenantId = null): Form
    {
        return $this->service->createForm(array_merge([
            'title' => '用户反馈表',
            'description' => '收集用户反馈',
            'status' => 'draft',
            'fields' => [
                [
                    'field_key' => 'name',
                    'field_type' => 'text',
                    'label' => '姓名',
                    'is_required' => true,
                    'sort_order' => 0,
                ],
                [
                    'field_key' => 'email',
                    'field_type' => 'email',
                    'label' => '邮箱',
                    'is_required' => true,
                    'sort_order' => 1,
                ],
                [
                    'field_key' => 'rating',
                    'field_type' => 'rating',
                    'label' => '评分',
                    'is_required' => false,
                    'sort_order' => 2,
                ],
            ],
        ], $overrides), $tenantId ?? 3001);
    }
}
