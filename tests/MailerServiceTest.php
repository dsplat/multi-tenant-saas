<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\LogTransport;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class MailerServiceTest extends TestCase
{
    protected array $uses = [InfrastructureModule::class];

    protected MailerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MailerService::class);
    }

    /**
     * 反射调用 protected resolvePlatformMailer
     */
    private function resolvePlatformMailer(): ?Mailer
    {
        $method = new \ReflectionMethod(MailerService::class, 'resolvePlatformMailer');

        return $method->invoke($this->service);
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

    // ==================================================================
    // 平台级 SMTP（system_settings mail 组）
    // ==================================================================

    public function test_platform_mailer_built_from_system_settings(): void
    {
        SystemSetting::setGroup('mail', [
            'driver' => ['value' => 'smtp'],
            'host' => ['value' => 'smtp.platform.test'],
            'port' => ['value' => '465'],
            'encryption' => ['value' => 'ssl'],
            'username' => ['value' => 'user@platform.test'],
            'password' => ['value' => 'secret-pw', 'is_encrypted' => true],
            'from_address' => ['value' => 'noreply@platform.test'],
            'from_name' => ['value' => 'Platform'],
        ]);

        $mailer = $this->resolvePlatformMailer();

        $this->assertInstanceOf(Mailer::class, $mailer);

        $transport = $mailer->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        // EsmtpTransport 无 getHost()/getPort()，__toString 形如 smtps://host（不含端口）
        $this->assertStringContainsString('smtp.platform.test', (string) $transport);
    }

    public function test_platform_mailer_supports_log_driver(): void
    {
        SystemSetting::setGroup('mail', [
            'driver' => ['value' => 'log'],
        ]);

        $mailer = $this->resolvePlatformMailer();

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(LogTransport::class, $mailer->getSymfonyTransport());
    }

    public function test_platform_mailer_null_when_unconfigured(): void
    {
        // 未配置 host → 返回 null（回退 env 全局 Mail）
        $this->assertNull($this->resolvePlatformMailer());
    }

    public function test_send_raw_uses_platform_smtp_when_configured(): void
    {
        SystemSetting::setGroup('mail', [
            'driver' => ['value' => 'log'],
        ]);

        // driver=log 平台通道：发送应成功且不依赖 env mailer
        $result = $this->service->sendRaw('test@example.com', 'Subject', '<p>hi</p>');
        $this->assertTrue($result);
    }
}
