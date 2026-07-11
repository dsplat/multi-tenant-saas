<?php

namespace MultiTenantSaas\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use MultiTenantSaas\Mail\TenantMail;

/**
 * 邮件发送服务
 *
 * 统一所有邮件发送入口，通过 TenantMail 实现模板渲染 + 品牌注入。
 * 支持模板驱动（sendTemplate）和直接发送（sendRaw）。
 *
 * 不依赖特定 mail driver，发送失败时记录日志不抛异常。
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
     *
     * @param  string  $to  收件人
     * @param  string  $type  模板类型 (welcome/reset/billing/notification)
     * @param  array  $data  模板变量
     * @param  int|null  $tenantId  租户 ID (null = 使用当前上下文)
     * @param  array  $attachments  附件配置
     * @return bool 是否发送成功
     */
    public function sendTemplate(
        string $to,
        string $type,
        array $data = [],
        ?int $tenantId = null,
        array $attachments = [],
    ): bool {
        try {
            $mailable = new TenantMail($type, $data, $tenantId, $attachments);
            Mail::to($to)->send($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error('[MailerService] Failed to send template email', [
                'to' => $to,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 直接发送 HTML 邮件（不使用模板）。
     *
     * 用于 MFA 验证码、测试邮件等简单场景。
     */
    public function sendRaw(string $to, string $subject, string $html): bool
    {
        try {
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

            Mail::to($to)->send($mailable);

            return true;
        } catch (\Throwable $e) {
            Log::error('[MailerService] Failed to send raw email', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
    public function sendTest(string $to): bool
    {
        $html = '<p>这是一封测试邮件。如果您收到此邮件，说明邮件配置正确。</p>';

        return $this->sendRaw($to, '测试邮件', $html);
    }
}
