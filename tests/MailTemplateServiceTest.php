<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Mail\Mailables\Attachment;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Mail\TenantMail;
use MultiTenantSaas\Models\MailTemplate;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\MailTemplateService;

/**
 * MailTemplateService 测试
 *
 * 覆盖：CRUD、变量替换、租户覆盖与系统默认回退、状态切换、
 * 预置模板播种、TenantMail 渲染与附件、租户作用域隔离。
 */
class MailTemplateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Mail Tenant A',
            'slug' => 'mail-tenant-a',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        Tenant::create([
            'tenant_id' => 1002,
            'name' => 'Mail Tenant B',
            'slug' => 'mail-tenant-b',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        // 清除租户上下文，确保系统默认模板 tenant_id 保持为 NULL
        TenantContext::clear();

        // 播种 6 个系统默认模板
        app(MailTemplateService::class)->seedDefaultTemplates();

        // 默认租户上下文设为 1001
        TenantContext::setTenantId('1001');
    }

    // ---------- CRUD ----------

    public function test_create_template(): void
    {
        $service = app(MailTemplateService::class);

        $template = $service->create([
            'type' => 'welcome',
            'name_key' => 'custom_welcome',
            'name' => '自定义欢迎邮件',
            'subject' => 'Hello {{user_name}}',
            'html_body' => '<p>Hi {{user_name}}</p>',
            'text_body' => 'Hi {{user_name}}',
            'variables' => ['user_name'],
            'status' => 'activated',
        ]);

        $this->assertNotEmpty($template->template_id);
        $this->assertSame('welcome', $template->type);
        $this->assertSame('custom_welcome', $template->name_key);
        $this->assertSame('自定义欢迎邮件', $template->name);
        $this->assertSame('Hello {{user_name}}', $template->subject);
        $this->assertSame(['user_name'], $template->variables);
        $this->assertSame('activated', $template->status);
        // 当前租户上下文为 1001，创建时自动填充 tenant_id
        $this->assertEquals(1001, $template->tenant_id);

        $this->assertDatabaseHas('mail_templates', [
            'template_id' => $template->template_id,
            'name' => '自定义欢迎邮件',
        ]);
    }

    public function test_get_template(): void
    {
        $service = app(MailTemplateService::class);

        $created = $service->create([
            'type' => 'notification',
            'name' => '获取测试',
            'subject' => 'Subject',
            'html_body' => '<p>body</p>',
            'variables' => [],
            'status' => 'activated',
        ]);

        $fetched = $service->get($created->template_id);

        $this->assertSame($created->template_id, $fetched->template_id);
        $this->assertSame('获取测试', $fetched->name);
        $this->assertSame('Subject', $fetched->subject);
    }

    public function test_update_template(): void
    {
        $service = app(MailTemplateService::class);

        $created = $service->create([
            'type' => 'notification',
            'name' => '原始',
            'subject' => '原主题',
            'html_body' => '<p>原</p>',
            'variables' => [],
            'status' => 'activated',
        ]);

        $updated = $service->update($created->template_id, [
            'name' => '更新后',
            'subject' => '新主题',
        ]);

        $this->assertSame('更新后', $updated->name);
        $this->assertSame('新主题', $updated->subject);
        $this->assertSame($created->template_id, $updated->template_id);
    }

    public function test_delete_template(): void
    {
        $service = app(MailTemplateService::class);

        $created = $service->create([
            'type' => 'notification',
            'name' => '待删除',
            'subject' => 'x',
            'html_body' => '<p>x</p>',
            'variables' => [],
            'status' => 'activated',
        ]);

        $this->assertTrue($service->delete($created->template_id));

        // 软删除：默认查询不再可见
        $this->assertNull(MailTemplate::find($created->template_id));
        // 但记录仍存在于回收站
        $this->assertNotNull(MailTemplate::withTrashed()->find($created->template_id));

        // 软删除后 get() 应抛出异常
        $this->expectException(\RuntimeException::class);
        $service->get($created->template_id);
    }

    // ---------- 变量替换 ----------

    public function test_variable_replacement(): void
    {
        $service = app(MailTemplateService::class);

        $content = 'Hello {{name}}, your code is {{code}}. Missing: {{missing}}';

        $result = $service->replaceVariables($content, ['name' => 'Alice', 'code' => '12345']);

        // 已提供变量被替换
        $this->assertSame('Hello Alice, your code is 12345. Missing: {{missing}}', $result);

        // HTML 转义：特殊字符被转义
        $html = '<p>{{name}}</p>';
        $escaped = $service->replaceVariables($html, ['name' => '<b>Alice</b>'], true);
        $this->assertSame('<p>&lt;b&gt;Alice&lt;/b&gt;</p>', $escaped);

        // 不转义：原样输出
        $raw = $service->replaceVariables($html, ['name' => '<b>Alice</b>'], false);
        $this->assertSame('<p><b>Alice</b></p>', $raw);
    }

    // ---------- 租户覆盖与回退 ----------

    public function test_find_template_tenant_override(): void
    {
        $service = app(MailTemplateService::class);

        // 系统默认 welcome 模板已由 seedDefaultTemplates 创建
        // 创建租户 1001 自定义 welcome 模板（同类型，不同内容）
        $tenantTemplate = $service->create([
            'type' => 'welcome',
            'name_key' => 'welcome_tenant_1001',
            'name' => '租户自定义欢迎',
            'subject' => 'Welcome from Tenant 1001',
            'html_body' => '<p>tenant custom</p>',
            'text_body' => 'tenant custom',
            'variables' => [],
            'status' => 'activated',
        ]);

        $found = $service->findTemplate('welcome', 1001);

        $this->assertNotNull($found);
        $this->assertSame($tenantTemplate->template_id, $found->template_id);
        $this->assertSame('Welcome from Tenant 1001', $found->subject);
        $this->assertEquals(1001, $found->tenant_id);
    }

    public function test_find_template_fallback_to_default(): void
    {
        $service = app(MailTemplateService::class);

        // 'reset' 类型仅有系统默认模板（password_reset），无租户自定义
        $found = $service->findTemplate('reset', 1001);

        $this->assertNotNull($found);
        $this->assertNull($found->tenant_id);
        $this->assertSame('reset', $found->type);
        $this->assertSame('password_reset', $found->name_key);
    }

    // ---------- 类型过滤 ----------

    public function test_template_type_filter(): void
    {
        $service = app(MailTemplateService::class);

        $service->create([
            'type' => 'billing',
            'name' => '租户账单模板',
            'subject' => 'Billing',
            'html_body' => '<p>billing</p>',
            'variables' => [],
            'status' => 'activated',
        ]);
        $service->create([
            'type' => 'notification',
            'name' => '租户通知模板',
            'subject' => 'Notify',
            'html_body' => '<p>notify</p>',
            'variables' => [],
            'status' => 'activated',
        ]);

        // 当前上下文 1001：返回租户 1001 模板 + 系统默认模板
        $billing = MailTemplate::ofType('billing')->get();

        $this->assertTrue($billing->every(fn ($t) => $t->type === 'billing'));
        // 系统默认 billing 有 2 个 (payment_success, invoice_generated) + 租户 1 个
        $this->assertCount(3, $billing);
        $this->assertTrue($billing->contains(fn ($t) => $t->name === '租户账单模板'));
    }

    // ---------- 状态切换 ----------

    public function test_toggle_status(): void
    {
        $service = app(MailTemplateService::class);

        $created = $service->create([
            'type' => 'notification',
            'name' => '切换状态',
            'subject' => 'x',
            'html_body' => '<p>x</p>',
            'variables' => [],
            'status' => 'activated',
        ]);

        $this->assertSame('activated', $created->status);

        $disabled = $service->toggleStatus($created->template_id, MailTemplate::STATUS_DISABLED);
        $this->assertSame('disabled', $disabled->status);

        $activated = $service->toggleStatus($created->template_id, MailTemplate::STATUS_ACTIVATED);
        $this->assertSame('activated', $activated->status);

        // 非法状态抛异常
        $this->expectException(\RuntimeException::class);
        $service->toggleStatus($created->template_id, 'invalid_status');
    }

    // ---------- 预置模板播种 ----------

    public function test_seed_default_templates(): void
    {
        // 清空所有模板，测试从零开始播种
        DB::table('mail_templates')->delete();
        $this->assertSame(0, MailTemplate::withoutGlobalScope('mailTemplateTenant')->count());

        TenantContext::clear();
        $service = app(MailTemplateService::class);
        $service->seedDefaultTemplates();

        $all = MailTemplate::withoutGlobalScope('mailTemplateTenant')
            ->whereNull('tenant_id')
            ->get();

        $this->assertCount(6, $all);

        // 幂等：再调用一次仍为 6
        $service->seedDefaultTemplates();
        $this->assertCount(
            6,
            MailTemplate::withoutGlobalScope('mailTemplateTenant')->whereNull('tenant_id')->get()
        );

        // 验证 name_keys
        $expected = [
            'welcome_registration',
            'password_reset',
            'payment_success',
            'invoice_generated',
            'subscription_expiring',
            'tenant_suspended',
        ];
        $this->assertEqualsCanonicalizing($expected, $all->pluck('name_key')->all());

        // 全部为系统默认（tenant_id NULL）且已激活
        $this->assertTrue($all->every(fn ($t) => $t->tenant_id === null && $t->status === 'activated'));
    }

    // ---------- TenantMail 渲染 ----------

    public function test_tenant_mail_renders_template(): void
    {
        $mail = new TenantMail(
            'welcome',
            ['user_name' => 'Alice', 'login_url' => 'https://example.com/login'],
            1001,
        );

        // 主题包含用户名（变量已替换）
        $this->assertStringContainsString('Alice', $mail->envelope()->subject);

        // HTML 正文渲染
        $html = $mail->render();
        $this->assertStringContainsString('Alice', $html);
        $this->assertStringContainsString('https://example.com/login', $html);
        // 默认变量已注入（租户名称）
        $this->assertStringContainsString('Mail Tenant A', $html);
        // 所有占位符已被替换
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_tenant_mail_with_attachments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mail_attach_');
        file_put_contents($path, 'attachment content');

        try {
            $mail = new TenantMail(
                'welcome',
                ['user_name' => 'Bob'],
                1001,
                [
                    $path, // 字符串路径：原样透传，由父类 hydrate
                    ['data' => 'inline data', 'name' => 'inline.txt', 'mime' => 'text/plain'],
                    ['path' => $path, 'name' => 'renamed.txt'],
                ],
            );

            $attachments = $mail->attachments();

            $this->assertCount(3, $attachments);
            // 字符串路径透传（父类在投递时归一化）
            $this->assertSame($path, $attachments[0]);
            // 数组配置转换为 Attachment 实例
            $this->assertInstanceOf(Attachment::class, $attachments[1]);
            $this->assertInstanceOf(Attachment::class, $attachments[2]);
        } finally {
            @unlink($path);
        }
    }

    // ---------- 租户作用域隔离 ----------

    public function test_tenant_scope_isolation(): void
    {
        $service = app(MailTemplateService::class);

        // 为租户 1002 创建专属模板
        TenantContext::clear();
        TenantContext::setTenantId('1002');
        $tenantBTemplate = $service->create([
            'type' => 'notification',
            'name' => '租户B专属',
            'subject' => 'B only',
            'html_body' => '<p>B</p>',
            'variables' => [],
            'status' => 'activated',
        ]);

        // 切换回租户 1001
        TenantContext::setTenantId('1001');

        // 租户 1001 视角：看不到租户 1002 的模板，但能看到系统默认模板
        $visible = MailTemplate::all();

        $this->assertFalse($visible->contains('template_id', $tenantBTemplate->template_id));
        $this->assertTrue(
            $visible->every(fn ($t) => $t->tenant_id === null || (string) $t->tenant_id === '1001')
        );

        // 系统默认模板可见（6 个）
        $systemDefaults = $visible->filter(fn ($t) => $t->tenant_id === null);
        $this->assertCount(6, $systemDefaults);
    }
}
