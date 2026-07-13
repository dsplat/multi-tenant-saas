<?php

namespace MultiTenantSaas\Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MultiTenantSaas\Modules\Notification\Services\MailTemplateService;

class CreditLowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $remainingCredits,
        public int $threshold = 100
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
            'remaining_credits' => $this->remainingCredits,
            'threshold' => $this->threshold,
        ]);

        if ($rendered) {
            return (new MailMessage)
                ->subject($rendered['subject'])
                ->line($rendered['text'] ?? strip_tags($rendered['html']))
                ->action('立即充值', url('/console/billing'));
        }

        return (new MailMessage)
            ->subject('积分余额不足提醒')
            ->line("您的积分余额为 {$this->remainingCredits}，低于预警阈值 {$this->threshold}。")
            ->action('立即充值', url('/console/billing'))
            ->line('为避免服务中断，请及时充值。');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => '积分余额不足',
            'message' => "当前剩余积分 {$this->remainingCredits}，低于预警阈值 {$this->threshold}",
            'type' => 'warning',
            'action_url' => url('/console/billing'),
            'extra' => ['remaining' => $this->remainingCredits, 'threshold' => $this->threshold],
        ];
    }
}
