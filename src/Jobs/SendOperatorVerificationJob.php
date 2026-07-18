<?php

namespace MultiTenantSaas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * 异步发送 Operator 邮箱验证邮件
 *
 * 将邮件发送从请求周期中解耦，避免 SMTP 延迟影响 API 响应。
 */
class SendOperatorVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $operatorId
    ) {}

    public function handle(MailerService $mailer): void
    {
        $operator = Operator::find($this->operatorId);
        if (! $operator || $operator->email_verified_at) {
            return;
        }

        $token = Str::random(64);

        DB::table('email_verification_tokens')->updateOrInsert(
            ['email' => $operator->email],
            [
                'token' => hash('sha256', $token),
                'created_at' => now(),
            ]
        );

        $verificationUrl = url('/public/verify-email?token=' . $token . '&email=' . urlencode($operator->email));

        $mailer->sendTemplate($operator->email, 'registration', [
            'name' => $operator->name,
            'verification_url' => $verificationUrl,
            'platform_name' => config('app.name'),
            'expiry_hours' => 24,
            'current_year' => date('Y'),
        ]);
    }
}
