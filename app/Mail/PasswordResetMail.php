<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $token,
        public string $email,
    ) {}

    public function envelope(): \Illuminate\Mail\Envelope
    {
        return new \Illuminate\Mail\Envelope(
            subject: '重置您的密码',
        );
    }

    public function content(): \Illuminate\Mail\Content
    {
        $resetUrl = config('app.frontend_url', config('app.url')) . '/reset-password?token=' . $this->token . '&email=' . urlencode($this->email);

        return new \Illuminate\Mail\Content(
            htmlString: <<<HTML
            <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">
                <h2>重置您的密码</h2>
                <p>我们收到了您的密码重置请求。请点击下方链接重置密码：</p>
                <p><a href="{$resetUrl}" style="display:inline-block;padding:10px 20px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">重置密码</a></p>
                <p style="color:#666;font-size:12px;">此链接将在 60 分钟后过期。如果您没有请求重置密码，请忽略此邮件。</p>
                <hr>
                <p style="color:#999;font-size:11px;">本邮件由系统自动发送，请勿回复。</p>
            </div>
            HTML,
        );
    }
}
