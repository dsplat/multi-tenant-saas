<?php

namespace MultiTenantSaas\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;

/**
 * 异步发送邮箱验证邮件
 *
 * 将邮件发送从请求周期中解耦，避免 SMTP 延迟影响 API 响应。
 * 派生项目可替换此 Job 或在 Job 前后添加 Hook。
 */
class SendEmailVerificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $userId
    ) {}

    public function handle(MailerService $mailer): void
    {
        $user = User::find($this->userId);
        if (! $user || $user->email_verified_at) {
            return;
        }

        $token = Str::random(64);

        DB::table('email_verification_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => hash('sha256', $token),
                'created_at' => now(),
            ]
        );

        $verificationUrl = url('/public/verify-email?token=' . $token . '&email=' . urlencode($user->email));

        $mailer->sendTemplate($user->email, 'verification', [
            'name' => $user->name ?? $user->email,
            'verification_url' => $verificationUrl,
            'platform_name' => config('app.name'),
            'expiry_hours' => 24,
            'current_year' => date('Y'),
        ], tenantId: (int) ($user->tenant_id ?? 0) ?: null);
    }
}
