<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantName,
        public string $planName,
        public ?string $expiresAt = null,
        public int $daysLeft = 7
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("订阅即将过期 - {$this->tenantName}")
            ->line("您所在的租户「{$this->tenantName}」的{$this->planName}套餐订阅将在 {$this->daysLeft} 天后过期。")
            ->action('续费订阅', url('/console/subscription'))
            ->line('过期后服务将降级为免费版，请及时续费。');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => '订阅即将过期',
            'message' => "「{$this->tenantName}」的{$this->planName}套餐将在 {$this->daysLeft} 天后过期",
            'type' => 'warning',
            'action_url' => url('/console/subscription'),
            'extra' => [
                'tenant_name' => $this->tenantName,
                'plan' => $this->planName,
                'expires_at' => $this->expiresAt,
                'days_left' => $this->daysLeft,
            ],
        ];
    }
}
