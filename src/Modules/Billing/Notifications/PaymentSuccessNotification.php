<?php

namespace MultiTenantSaas\Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MultiTenantSaas\Services\MailTemplateService;

class PaymentSuccessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $orderNo,
        public int $amount,
        public string $paymentMethod
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $templateService = app(MailTemplateService::class);
        $rendered = $templateService->render('billing', [
            'user_name' => $notifiable->name,
            'order_no' => $this->orderNo,
            'amount' => number_format($this->amount / 100, 2),
            'payment_method' => $this->paymentMethod,
        ]);

        if ($rendered) {
            return (new MailMessage)
                ->subject($rendered['subject'])
                ->line($rendered['text'] ?? strip_tags($rendered['html']))
                ->action('查看订单', url('/console/billing/orders'));
        }

        return (new MailMessage)
            ->subject('支付成功通知')
            ->line("您的订单 {$this->orderNo} 支付成功。")
            ->line('支付金额：¥' . number_format($this->amount / 100, 2))
            ->line("支付方式：{$this->paymentMethod}")
            ->action('查看订单', url('/console/billing/orders'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => '支付成功',
            'message' => "订单 {$this->orderNo} 支付成功，金额 ¥" . number_format($this->amount / 100, 2),
            'type' => 'success',
            'action_url' => url('/console/billing/orders'),
            'extra' => [
                'order_no' => $this->orderNo,
                'amount' => $this->amount,
                'payment_method' => $this->paymentMethod,
            ],
        ];
    }
}
