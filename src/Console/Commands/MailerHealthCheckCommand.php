<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\MailerService;

/**
 * 检查邮件服务健康状态。
 */
class MailerHealthCheckCommand extends Command
{
    protected $signature = 'mailer:health-check
        {--dry-run : 只检查配置，不发送测试邮件}';

    protected $description = '检查邮件服务健康状态';

    public function handle(MailerService $mailer): int
    {
        $fromAddress = config('tenancy.mail_templates.default_from_address');

        if (empty($fromAddress)) {
            $this->warn('MAIL_FROM_ADDRESS 未配置');

            return self::SUCCESS;
        }

        $this->info("邮件配置: from={$fromAddress}, driver=" . config('mail.default', 'smtp'));

        if ($this->option('dry-run')) {
            $this->info('[DRY] 跳过发送测试邮件');

            return self::SUCCESS;
        }

        $result = $mailer->sendTest($fromAddress);

        if ($result) {
            $this->info('测试邮件发送成功');

            return self::SUCCESS;
        }

        $this->error('测试邮件发送失败');

        return self::FAILURE;
    }
}
