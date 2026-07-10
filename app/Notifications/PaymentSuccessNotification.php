<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
