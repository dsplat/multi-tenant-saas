<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Sms\SmsBatchTask;
use MultiTenantSaas\Models\Sms\SmsTemplate;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Services\SmsService;
use MultiTenantSaas\Tests\Schema\SmsModule;

class SmsControllerTest extends TestCase
{
    protected array $uses = [SmsModule::class];

    private int $tenantId = 1001;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'SMS Test Tenant',
            'slug' => 'sms-test-tenant',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'user_id' => 2001,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
        ]);

        // 创建 tenant_user 关联
        \MultiTenantSaas\Models\TenantUser::create([
            'tenant_user_id' => 3001,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->user->user_id,
            'role' => 'tenant_admin',
            'is_active' => true,
        ]);

        TenantContext::setTenantId($this->tenantId);
    }

    // ========== 模板管理 API ==========

    public function test_index_templates(): void
    {
        SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '测试模板',
            'content' => '测试内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/sms/templates");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data');
    }

    public function test_index_templates_filter_by_channel(): void
    {
        SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '营销模板',
            'content' => '营销内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);
        SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '验证码模板',
            'content' => '验证码内容',
            'channel' => SmsTemplate::CHANNEL_VERIFICATION,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/sms/templates?channel=marketing");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_store_template(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/templates", [
                'name' => '新模板',
                'content' => '新内容',
                'channel' => SmsTemplate::CHANNEL_MARKETING,
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', '新模板');
    }

    public function test_store_template_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/templates", [
                'name' => '',
                'content' => '',
            ]);

        $response->assertStatus(422);
    }

    public function test_show_template(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '查看模板',
            'content' => '查看内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/sms/templates/{$template->sms_template_id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', '查看模板');
    }

    public function test_update_template(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '原名称',
            'content' => '原内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/tenants/{$this->tenantId}/sms/templates/{$template->sms_template_id}", [
                'name' => '新名称',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.name', '新名称');
    }

    public function test_destroy_template(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '删除模板',
            'content' => '删除内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/tenants/{$this->tenantId}/sms/templates/{$template->sms_template_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_submit_for_approval(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '审核模板',
            'content' => '审核内容',
            'status' => SmsTemplate::STATUS_REJECTED,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/templates/{$template->sms_template_id}/submit-approval");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', SmsTemplate::STATUS_PENDING_APPROVAL);
    }

    public function test_render_content(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '渲染模板',
            'content' => '您好{name}，验证码是{code}。',
            'channel' => SmsTemplate::CHANNEL_VERIFICATION,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/templates/{$template->sms_template_id}/render", [
                'variables' => ['name' => '张三', 'code' => '123456'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.content', '您好张三，验证码是123456。');
    }

    // ========== 批量发送 API ==========

    public function test_batch_send(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '批量模板',
            'content' => '批量内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/batch-send", [
                'template_id' => $template->sms_template_id,
                'phones' => ['13800000001', '13800000002'],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.total_count', 2);
    }

    public function test_batch_send_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/batch-send", [
                'template_id' => 999,
                'phones' => [],
            ]);

        $response->assertStatus(422);
    }

    public function test_scheduled_send(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '定时模板',
            'content' => '定时内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/scheduled-send", [
                'template_id' => $template->sms_template_id,
                'phones' => ['13800000001'],
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.type', SmsBatchTask::TYPE_SCHEDULED);
    }

    public function test_show_batch_task(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '查询模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/sms/batch-tasks/{$task->batch_task_id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_cancel_batch_task(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '取消模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/batch-tasks/{$task->batch_task_id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', SmsBatchTask::STATUS_CANCELLED);
    }

    // ========== 到达率统计 API ==========

    public function test_delivery_stats(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => $this->tenantId,
            'name' => '统计模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);

        SmsService::recordDeliveryResult($task->batch_task_id, [
            'tenant_id' => $this->tenantId,
            'sent_count' => 100,
            'delivered_count' => 95,
            'delivery_rate' => 95.00,
            'recorded_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/sms/batch-tasks/{$task->batch_task_id}/delivery-stats");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_overall_stats(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/sms/overall-stats");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========== 退订管理 API ==========

    public function test_index_unsubscribes(): void
    {
        SmsService::unsubscribe('13800000001', $this->tenantId);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$this->tenantId}/sms/unsubscribes");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_store_unsubscribe(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/unsubscribes", [
                'phone' => '13800000001',
                'reason' => '不感兴趣',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.phone', '13800000001');
    }

    public function test_store_unsubscribe_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/unsubscribes", [
                'phone' => 'invalid',
            ]);

        $response->assertStatus(422);
    }

    public function test_check_unsubscribed(): void
    {
        SmsService::unsubscribe('13800000001', $this->tenantId);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/unsubscribes/check", [
                'phone' => '13800000001',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_unsubscribed', true);
    }

    public function test_check_not_unsubscribed(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/tenants/{$this->tenantId}/sms/unsubscribes/check", [
                'phone' => '13800000002',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_unsubscribed', false);
    }

    // ========== 租户隔离测试 ==========

    public function test_cannot_access_other_tenant_templates(): void
    {
        $otherTenantId = 1002;
        Tenant::create([
            'tenant_id' => $otherTenantId,
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => 'active',
        ]);

        $template = SmsService::createTemplate([
            'tenant_id' => $otherTenantId,
            'name' => '其他租户模板',
            'content' => '内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/tenants/{$otherTenantId}/sms/templates/{$template->sms_template_id}");

        $response->assertStatus(403);
    }
}
