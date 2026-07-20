<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Mail\TenantMail;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Notification\Services\MailTemplateService;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * 邮件发送服务
 *
 * 统一所有邮件发送入口，通过 TenantMail 实现模板渲染 + 品牌注入。
 * 支持模板驱动（sendTemplate）和直接发送（sendRaw）。
 *
 * 租户级 SMTP：若租户在 tenant_settings(group='mail') 中配置了 smtp_host，
 * 则使用租户自己的 SMTP 发送；否则使用全局 SMTP。不做失败回退。
 */
class MailerService
{
    public function __construct(
        protected MailTemplateService $templateService,
    ) {}

    /**
     * 通过模板发送邮件。
     *
     * 使用 TenantMail 渲染模板，自动注入品牌和租户变量。
     * 若租户配置了 SMTP，使用租户 SMTP；否则使用全局 SMTP。
     *
     * @param  string  $to  收件人
     * @param  string  $type  模板类型 (welcome/reset/billing/notification/registration/verification/invitation/application_*)
     * @param  array  $data  模板变量
     * @param  int|null  $tenantId  租户 ID (null = 使用当前上下文)
     * @param  array  $attachments  附件配置
     * @param  string|null  $locale  语言标识 (null = 使用默认)
     * @return bool 是否发送成功
     */
    public function sendTemplate(
        string $to,
        string $type,
        array $data = [],
        ?int $tenantId = null,
        array $attachments = [],
        ?string $locale = null,
    ): bool {
        $tenantId = $tenantId ?? TenantContext::getId();
        $mailable = new TenantMail($type, $data, $tenantId ? (int) $tenantId : null, $attachments, $locale);

        return $this->send($to, $mailable, $tenantId ? (int) $tenantId : null);
    }

    /**
     * 直接发送 HTML 邮件（不使用模板）。
     *
     * 用于 MFA 验证码、测试邮件等简单场景。
     */
    public function sendRaw(string $to, string $subject, string $html, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? TenantContext::getId();

        $mailable = new class($subject, $html) extends Mailable
        {
            public function __construct(
                private string $emailSubject,
                private string $emailHtml,
            ) {}

            public function envelope(): Envelope
            {
                return new Envelope(
                    subject: $this->emailSubject,
                );
            }

            public function content(): Content
            {
                return new Content(
                    htmlString: $this->emailHtml,
                );
            }
        };

        return $this->send($to, $mailable, $tenantId ? (int) $tenantId : null);
    }

    /**
     * 发送 MFA 验证码邮件。
     */
    public function sendMfaCode(string $to, string $code): bool
    {
        $html = trans('auth.mfa_email_body', ['code' => $code]);
        $subject = trans('auth.mfa_email_subject');

        return $this->sendRaw($to, $subject, $html);
    }

    /**
     * 发送测试邮件（验证邮件配置）。
     */
    public function sendTest(string $to, ?int $tenantId = null): bool
    {
        $html = '<p>这是一封测试邮件。如果您收到此邮件，说明邮件配置正确。</p>';

        return $this->sendRaw($to, '测试邮件', $html, $tenantId);
    }

    /**
     * 统一发送入口：根据租户 SMTP 配置选择发送通道
     *
     * 租户配置了 smtp_host → 使用租户 SMTP（失败即失败，不回退）
     * 租户未配置 → 使用全局 SMTP
     */
    protected function send(string $to, Mailable $mailable, ?int $tenantId): bool
    {
        try {
            $mailer = $this->resolveTenantMailer($tenantId);

            if ($mailer) {
                $mailer->to($to)->send($mailable);
            } else {
                Mail::to($to)->send($mailable);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[MailerService] Failed to send email', [
                'to' => $to,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 解析租户级 Mailer
     *
     * 租户在 tenant_settings(group='mail') 中配置了 smtp_host 则构建独立 Mailer，
     * 未配置则返回 null（使用全局 Mail）。
     */
    protected function resolveTenantMailer(?int $tenantId): ?Mailer
    {
        if (! $tenantId) {
            return null;
        }

        $host = TenantSetting::get($tenantId, 'mail', 'smtp_host', '');
        if (empty($host)) {
            return null;
        }

        $port = (int) TenantSetting::get($tenantId, 'mail', 'smtp_port', 465);
        $encryption = TenantSetting::get($tenantId, 'mail', 'smtp_encryption', 'ssl');
        $username = TenantSetting::get($tenantId, 'mail', 'smtp_username', '');
        $password = TenantSetting::get($tenantId, 'mail', 'smtp_password', '');
        $fromAddress = TenantSetting::get($tenantId, 'mail', 'from_address', '');
        $fromName = TenantSetting::get($tenantId, 'mail', 'from_name', config('app.name', 'Platform'));

        $transport = new EsmtpTransport($host, $port, $encryption === 'ssl');
        $transport->setUsername($username);
        $transport->setPassword($password);

        $mailer = new Mailer('tenant', app('view'), $transport, app('events'));

        if ($fromAddress) {
            $mailer->alwaysFrom($fromAddress, $fromName);
        }

        return $mailer;
    }
}
