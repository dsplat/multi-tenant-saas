<?php

namespace MultiTenantSaas\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $token,
        public string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('auth.reset_password_subject'),
        );
    }

    public function content(): Content
    {
        $resetUrl = config('app.frontend_url', config('app.url')) . '/reset-password?token=' . $this->token . '&email=' . urlencode($this->email);
        $buttonText = trans('auth.reset_password_button');
        $title = trans('auth.reset_password_title');
        $body = trans('auth.reset_password_body');
        $expiry = trans('auth.reset_password_expiry');
        $note = trans('auth.email_auto_send_note');

        return new Content(
            htmlString: <<<HTML
            <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">
                <h2>{$title}</h2>
                <p>{$body}</p>
                <p><a href="{$resetUrl}" style="display:inline-block;padding:10px 20px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">{$buttonText}</a></p>
                <p style="color:#666;font-size:12px;">{$expiry}</p>
                <hr>
                <p style="color:#999;font-size:11px;">{$note}</p>
            </div>
            HTML,
        );
    }
}
