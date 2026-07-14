<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Sms\Models\SmsBatchTask;
use MultiTenantSaas\Modules\Sms\Models\SmsTemplate;
use MultiTenantSaas\Modules\Sms\Services\SmsService;
use MultiTenantSaas\Tests\Schema\SmsModule;

class SmsServiceTest extends TestCase
{
    protected array $uses = [SmsModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'SMS Tenant A',
            'slug' => 'sms-tenant-a',
            'status' => 'active',
        ]);
        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'SMS Tenant B',
            'slug' => 'sms-tenant-b',
            'status' => 'active',
        ]);

        TenantContext::setTenantId(1001);
    }

    // ---------- 模板管理 ----------

    public function test_create_template(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '验证码模板',
            'content' => '您的验证码是{code}，5分钟内有效。',
            'channel' => SmsTemplate::CHANNEL_VERIFICATION,
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $this->assertNotNull($template->sms_template_id);
        $this->assertEquals('验证码模板', $template->name);
        $this->assertEquals(SmsTemplate::CHANNEL_VERIFICATION, $template->channel);
        $this->assertEquals(SmsTemplate::STATUS_APPROVED, $template->status);
    }

    public function test_update_template(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '原名称',
            'content' => '原内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);

        $updated = SmsService::updateTemplate($template->sms_template_id, [
            'name' => '新名称',
            'content' => '新内容',
        ]);

        $this->assertEquals('新名称', $updated->name);
        $this->assertEquals('新内容', $updated->content);
    }

    public function test_submit_for_approval(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '待审核模板',
            'content' => '测试内容',
            'status' => SmsTemplate::STATUS_REJECTED,
        ]);

        $result = SmsService::submitForApproval($template->sms_template_id);

        $this->assertEquals(SmsTemplate::STATUS_PENDING_APPROVAL, $result->status);
    }

    public function test_render_content(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '渲染模板',
            'content' => '您好{name}，您的验证码是{code}。',
            'channel' => SmsTemplate::CHANNEL_VERIFICATION,
        ]);

        $rendered = SmsService::renderContent($template->sms_template_id, [
            'name' => '张三',
            'code' => '123456',
        ]);

        $this->assertEquals('您好张三，您的验证码是123456。', $rendered);
    }

    public function test_render_content_without_variables(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '固定内容',
            'content' => '这是一条固定短信。',
            'channel' => SmsTemplate::CHANNEL_NOTIFICATION,
        ]);

        $rendered = SmsService::renderContent($template->sms_template_id);

        $this->assertEquals('这是一条固定短信。', $rendered);
    }

    public function test_get_templates(): void
    {
        SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '营销模板',
            'content' => '营销内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);
        SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '验证码模板',
            'content' => '验证码内容',
            'channel' => SmsTemplate::CHANNEL_VERIFICATION,
        ]);

        $all = SmsService::getTemplates();
        $this->assertCount(2, $all);

        $marketing = SmsService::getTemplates(['channel' => SmsTemplate::CHANNEL_MARKETING]);
        $this->assertCount(1, $marketing);
        $this->assertEquals('营销模板', $marketing->first()->name);
    }

    public function test_get_templates_filters_by_status(): void
    {
        SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '已审核',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);
        SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '待审核',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_PENDING_APPROVAL,
        ]);

        $approved = SmsService::getTemplates(['status' => SmsTemplate::STATUS_APPROVED]);
        $this->assertCount(1, $approved);
        $this->assertEquals('已审核', $approved->first()->name);
    }

    // ---------- 批量发送 ----------

    public function test_batch_send(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '批量模板',
            'content' => '批量内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $phones = ['13800000001', '13800000002', '13800000003'];
        $task = SmsService::batchSend($template->sms_template_id, $phones);

        $this->assertNotNull($task->batch_task_id);
        $this->assertEquals(SmsBatchTask::TYPE_BATCH_SEND, $task->type);
        $this->assertEquals(3, $task->total_count);
        $this->assertEquals(SmsBatchTask::STATUS_PENDING, $task->status);
        $this->assertEquals($phones, $task->target_ids);
    }

    public function test_scheduled_send(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '定时模板',
            'content' => '定时内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $scheduledAt = '2026-07-08 10:00:00';
        $task = SmsService::scheduledSend($template->sms_template_id, ['13800000001'], $scheduledAt);

        $this->assertEquals(SmsBatchTask::TYPE_SCHEDULED, $task->type);
        $this->assertEquals($scheduledAt, $task->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_get_batch_task(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '查询模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);
        $found = SmsService::getBatchTask($task->batch_task_id);

        $this->assertEquals($task->batch_task_id, $found->batch_task_id);
    }

    public function test_cancel_batch_task(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '取消模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);
        $cancelled = SmsService::cancelBatchTask($task->batch_task_id);

        $this->assertEquals(SmsBatchTask::STATUS_CANCELLED, $cancelled->status);
    }

    public function test_cancel_non_pending_task_does_nothing(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '完成模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);
        $task->update(['status' => SmsBatchTask::STATUS_COMPLETED]);

        $result = SmsService::cancelBatchTask($task->batch_task_id);
        $this->assertEquals(SmsBatchTask::STATUS_COMPLETED, $result->status);
    }

    // ---------- 到达率统计 ----------

    public function test_record_delivery_result(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '统计模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);

        $stat = SmsService::recordDeliveryResult($task->batch_task_id, [
            'tenant_id' => 1001,
            'sent_count' => 100,
            'delivered_count' => 95,
            'failed_count' => 5,
            'clicked_count' => 10,
            'unsubscribed_count' => 2,
            'delivery_rate' => 95.00,
            'recorded_at' => now(),
        ]);

        $this->assertNotNull($stat->stat_id);
        $this->assertEquals(100, $stat->sent_count);
        $this->assertEquals(95, $stat->delivered_count);
        $this->assertEquals(95.00, (float) $stat->delivery_rate);
    }

    public function test_get_delivery_stats(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '统计查询模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);

        SmsService::recordDeliveryResult($task->batch_task_id, [
            'tenant_id' => 1001,
            'sent_count' => 100,
            'delivered_count' => 95,
            'delivery_rate' => 95.00,
            'recorded_at' => now(),
        ]);

        $stats = SmsService::getDeliveryStats($task->batch_task_id);

        $this->assertCount(1, $stats);
        $this->assertEquals(100, $stats->first()->sent_count);
    }

    public function test_get_overall_stats(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '整体统计模板',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);

        SmsService::recordDeliveryResult($task->batch_task_id, [
            'tenant_id' => 1001,
            'sent_count' => 100,
            'delivered_count' => 90,
            'failed_count' => 10,
            'delivery_rate' => 90.00,
            'recorded_at' => now(),
        ]);

        SmsService::recordDeliveryResult($task->batch_task_id, [
            'tenant_id' => 1001,
            'sent_count' => 200,
            'delivered_count' => 180,
            'failed_count' => 20,
            'delivery_rate' => 90.00,
            'recorded_at' => now(),
        ]);

        $overall = SmsService::getOverallStats(1001);

        $this->assertEquals(300, $overall['total_sent']);
        $this->assertEquals(270, $overall['total_delivered']);
        $this->assertEquals(30, $overall['total_failed']);
        $this->assertEquals(90.00, $overall['avg_delivery_rate']);
    }

    public function test_get_overall_stats_with_date_range(): void
    {
        $template = SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '日期范围统计',
            'content' => '内容',
            'status' => SmsTemplate::STATUS_APPROVED,
        ]);

        $task = SmsService::batchSend($template->sms_template_id, ['13800000001']);

        SmsService::recordDeliveryResult($task->batch_task_id, [
            'tenant_id' => 1001,
            'sent_count' => 100,
            'delivered_count' => 90,
            'delivery_rate' => 90.00,
            'recorded_at' => '2026-07-01 10:00:00',
        ]);

        SmsService::recordDeliveryResult($task->batch_task_id, [
            'tenant_id' => 1001,
            'sent_count' => 200,
            'delivered_count' => 180,
            'delivery_rate' => 90.00,
            'recorded_at' => '2026-07-07 10:00:00',
        ]);

        $overall = SmsService::getOverallStats(1001, '2026-07-05 00:00:00');

        $this->assertEquals(200, $overall['total_sent']);
    }

    // ---------- 退订管理 ----------

    public function test_unsubscribe(): void
    {
        $result = SmsService::unsubscribe('13800000001', 1001, null, '不感兴趣');

        $this->assertNotNull($result->unsubscribe_id);
        $this->assertEquals('13800000001', $result->phone);
        $this->assertEquals('不感兴趣', $result->reason);
    }

    public function test_is_unsubscribed_returns_true(): void
    {
        SmsService::unsubscribe('13800000001', 1001);

        $this->assertTrue(SmsService::isUnsubscribed('13800000001', 1001));
    }

    public function test_is_unsubscribed_returns_false(): void
    {
        $this->assertFalse(SmsService::isUnsubscribed('13800000001', 1001));
    }

    public function test_is_unsubscribed_different_tenant(): void
    {
        SmsService::unsubscribe('13800000001', 1001);

        // 租户 B 未退订
        $this->assertFalse(SmsService::isUnsubscribed('13800000001', 1002));
    }

    public function test_get_unsubscribes(): void
    {
        SmsService::unsubscribe('13800000001', 1001);
        SmsService::unsubscribe('13800000002', 1001);
        SmsService::unsubscribe('13800000003', 1002);

        $tenantA = SmsService::getUnsubscribes(1001);
        $this->assertCount(2, $tenantA);

        $all = SmsService::getUnsubscribes();
        $this->assertCount(3, $all);
    }

    // ---------- 租户隔离 ----------

    public function test_templates_isolated_by_tenant(): void
    {
        SmsService::createTemplate([
            'tenant_id' => 1001,
            'name' => '租户A模板',
            'content' => '内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);

        TenantContext::setTenantId(1002);

        SmsService::createTemplate([
            'tenant_id' => 1002,
            'name' => '租户B模板',
            'content' => '内容',
            'channel' => SmsTemplate::CHANNEL_MARKETING,
        ]);

        // 租户A只能看到自己的模板
        TenantContext::setTenantId(1001);
        $templates = SmsService::getTemplates();
        $this->assertCount(1, $templates);
        $this->assertEquals('租户A模板', $templates->first()->name);
    }

    // ---------- 原有发送功能保留 ----------

    public function test_send_via_log_driver(): void
    {
        $result = SmsService::send('13800000001', '123456', 'register');

        $this->assertEquals('123456', $result);
    }

    public function test_send_via_log_driver_returns_code(): void
    {
        $result = SmsService::sendUsingDriver('log', '13800000001', '654321', 'login');

        $this->assertEquals('654321', $result);
    }
}
