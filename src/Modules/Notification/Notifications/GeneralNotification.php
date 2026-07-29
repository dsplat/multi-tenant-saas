<?php

namespace MultiTenantSaas\Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $message,
        public string $type = 'info',
        public ?string $actionUrl = null,
        public array $extra = []
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->email && config('app.notifications_email_enabled', true)) {
            $channels[] = 'mail';
        }

        // 随身助理通道：仅 Operator（有 operator_id）且功能开启时尝试，
        // 无默认绑定或推送失败由驱动内部静默降级，database 通道兜底
        if (config('ai.ibot.enabled', false) && isset($notifiable->operator_id)) {
            $channels[] = 'ibot';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->line($this->message);

        if ($this->actionUrl) {
            $mail->action('查看详情', $this->actionUrl);
        }

        return $mail;
    }

    public function toIbot(object $notifiable): string
    {
        $lines = array_filter([
            $this->title,
            $this->message,
            $this->actionUrl,
        ]);

        return implode("\n", $lines);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'action_url' => $this->actionUrl,
            'extra' => $this->extra,
        ];
    }
}
