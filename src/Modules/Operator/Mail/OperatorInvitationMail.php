<?php

namespace MultiTenantSaas\Modules\Operator\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OperatorInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $operatorName,
        public string $tenantName,
        public string $inviteUrl,
        public string $role,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('operator.invite_subject', ['tenant' => $this->tenantName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'operator::emails.invitation',
            with: [
                'operatorName' => $this->operatorName,
                'tenantName' => $this->tenantName,
                'inviteUrl' => $this->inviteUrl,
                'role' => $this->role,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
