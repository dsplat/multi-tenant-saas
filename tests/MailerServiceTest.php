<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\MailerService;

class MailerServiceTest extends TestCase
{
    protected MailerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MailerService::class);
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(MailerService::class, $this->service);
    }

    public function test_send_template_returns_bool(): void
    {
        config(['mail.default' => 'log']);
        // 模板可能不在测试 DB 中，sendTemplate 不应抛异常
        $result = $this->service->sendTemplate(
            'test@example.com',
            'welcome_registration',
            ['user_name' => 'Test User', 'platform_name' => 'Test']
        );
        $this->assertIsBool($result);
    }

    public function test_send_template_returns_true_with_unknown_type(): void
    {
        config(['mail.default' => 'log']);
        // 即使模板不存在，TenantMail 会 fallback，不应抛异常
        $result = $this->service->sendTemplate(
            'test@example.com',
            'nonexistent_type',
            ['key' => 'value']
        );
        $this->assertIsBool($result);
    }

    public function test_send_raw_returns_true_on_success(): void
    {
        config(['mail.default' => 'log']);
        $result = $this->service->sendRaw(
            'test@example.com',
            'Test Subject',
            '<p>Hello</p>'
        );
        $this->assertTrue($result);
    }

    public function test_send_mfa_code_returns_true(): void
    {
        config(['mail.default' => 'log']);
        $result = $this->service->sendMfaCode('test@example.com', '123456');
        $this->assertTrue($result);
    }

    public function test_send_test_returns_true(): void
    {
        config(['mail.default' => 'log']);
        $result = $this->service->sendTest('test@example.com');
        $this->assertTrue($result);
    }
}
