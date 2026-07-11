<?php

namespace MultiTenantSaas\Modules\User\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MultiTenantSaas\Services\MailTemplateService;

class TenantSuspendedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantName,
        public ?string $reason = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $templateService = app(MailTemplateService::class);
        $rendered = $templateService->render('notification', [
            'user_name' => $notifiable->name,
            'tenant_name' => $this->tenantName,
            'reason' => $this->reason ?? '',
        ]);

        if ($rendered) {
            $mail = (new MailMessage)
                ->subject($rendered['subject'])
                ->line($rendered['text'] ?? strip_tags($rendered['html']));

            return $mail;
        }

        $mail = (new MailMessage)
            ->subject("租户已暂停 - {$this->tenantName}")
            ->line("您所在的租户「{$this->tenantName}」已被暂停。")
            ->line('如需恢复使用，请联系平台管理员。');

        if ($this->reason) {
            $mail->line("暂停原因：{$this->reason}");
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => '租户已暂停',
            'message' => "您所在的租户「{$this->tenantName}」已被暂停",
            'type' => 'warning',
            'extra' => ['tenant_name' => $this->tenantName, 'reason' => $this->reason],
        ];
    }
}
