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

    public function envelope(): \Illuminate\Mail\Mailables\Envelope
    {
        return new \Illuminate\Mail\Mailables\Envelope(
            subject: trans('auth.verify_email_subject'),
        );
    }

    public function content(): \Illuminate\Mail\Mailables\Content
    {
        $verifyUrl = config('app.frontend_url', config('app.url')) . '/verify-email?token=' . $this->token . '&email=' . urlencode($this->email);
        $buttonText = trans('auth.verify_email_button');
        $title = trans('auth.verify_email_title');
        $body = trans('auth.verify_email_body');
        $expiry = trans('auth.verify_email_expiry');
        $note = trans('auth.email_auto_send_note');

        return new \Illuminate\Mail\Mailables\Content(
            htmlString: <<<HTML
            <div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">
                <h2>{$title}</h2>
                <p>{$body}</p>
                <p><a href="{$verifyUrl}" style="display:inline-block;padding:10px 20px;background:#4f46e5;color:#fff;text-decoration:none;border-radius:5px;">{$buttonText}</a></p>
                <p style="color:#666;font-size:12px;">{$expiry}</p>
                <hr>
                <p style="color:#999;font-size:11px;">{$note}</p>
            </div>
            HTML,
        );
    }
}
