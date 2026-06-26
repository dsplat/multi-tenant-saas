<?php

namespace MultiTenantSaas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $token,
        public string $email,
    ) {}

    public function envelope(): \Illuminate\Mail\Envelope
    {
        return new \Illuminate\Mail\Envelope(
            subject: trans('auth.verify_email_subject'),
        );
    }

    public function content(): \Illuminate\Mail\Content
    {
        $verifyUrl = config('app.frontend_url', config('app.url')) . '/verify-email?token=' . $this->token . '&email=' . urlencode($this->email);

        return new \Illuminate\Mail\Content(
            htmlString: <<<HTML
            <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">
                <h2>验证您的邮箱地址</h2>
                <p>感谢您注册！请点击下方链接验证您的邮箱地址：</p>
                <p><a href="{$verifyUrl}" style="display:inline-block;padding:10px 20px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">验证邮箱</a></p>
                <p style="color:#666;font-size:12px;">此链接将在 24 小时后过期。如果您没有注册账号，请忽略此邮件。</p>
                <hr>
                <p style="color:#999;font-size:11px;">本邮件由系统自动发送，请勿回复。</p>
            </div>
            HTML,
        );
    }
}
