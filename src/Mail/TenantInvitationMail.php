<?php

namespace MultiTenantSaas\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use MultiTenantSaas\Services\MailTemplateService;

class TenantInvitationMail extends Mailable
{
    public function __construct(
        protected string $email,
        protected string $tenantName,
        protected string $inviterName,
        protected string $inviteUrl,
        protected string $role = 'end_user',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->inviterName} 邀请你加入 {$this->tenantName}",
        );
    }

    public function content(): Content
    {
        $templateService = app(MailTemplateService::class);
        $rendered = $templateService->render('notification', [
            'user_name' => $this->email,
            'tenant_name' => $this->tenantName,
            'inviter_name' => $this->inviterName,
            'invite_url' => $this->inviteUrl,
            'role' => $this->role,
        ]);

        if ($rendered) {
            return new Content(htmlString: $rendered['html']);
        }

        $html = <<<'HTML'
        <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">
            <h2>加入 {tenantName}</h2>
            <p>{inviterName} 邀请你加入 <strong>{tenantName}</strong>。</p>
            <p>角色: {role}</p>
            <p style="margin: 24px 0;">
                <a href="{inviteUrl}"
                   style="background: #4f46e5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;">
                    接受邀请
                </a>
            </p>
            <p style="color: #666; font-size: 12px;">如果按钮无法点击，请复制链接: {inviteUrl}</p>
        </div>
        HTML;

        $html = str_replace(
            ['{tenantName}', '{inviterName}', '{role}', '{inviteUrl}'],
            [$this->tenantName, $this->inviterName, $this->role, $this->inviteUrl],
            $html
        );

        return new Content(htmlString: $html);
    }
}
