<?php

namespace MultiTenantSaas\Console\Commands;

use Database\Seeders\PlatformInitSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 平台初始化命令
 *
 * 首次部署时运行，创建平台默认租户、系统角色、权限节点、超级管理员。
 * 已初始化时可使用 --force 重新执行。
 */
class PlatformInitCommand extends Command
{
    protected $signature = 'platform:init
        {--password= : 超级管理员密码}
        {--email= : 超级管理员邮箱}
        {--force : 强制重新初始化}';

    protected $description = '初始化平台：创建默认租户、系统角色、权限、超级管理员';

    public function handle(): int
    {
        $platformTenantId = config('id.platform_tenant_id', 9007199254740991);

        // 检查是否已初始化
        $initialized = DB::table('tenants')
            ->where('tenant_id', $platformTenantId)
            ->where('is_platform_default', true)
            ->exists();

        if ($initialized && ! $this->option('force')) {
            $this->warn('平台已初始化。使用 --force 重新执行。');

            $adminEmail = env('SUPER_ADMIN_EMAIL', 'admin@platform.local');
            $this->info("超级管理员邮箱: {$adminEmail}");

            return self::SUCCESS;
        }

        $this->info('正在初始化平台...');

        // 设置环境变量供 seeder 使用
        if ($email = $this->option('email')) {
            putenv("SUPER_ADMIN_EMAIL={$email}");
        }
        if ($password = $this->option('password')) {
            putenv("SUPER_ADMIN_PASSWORD={$password}");
        }

        // 执行初始化 seeder
        $this->call('db:seed', [
            '--class' => PlatformInitSeeder::class,
            '--force' => true,
        ]);

        $this->newLine();
        $email = $this->option('email') ?? env('SUPER_ADMIN_EMAIL', 'admin@platform.local');
        $this->info('平台初始化完成！');
        $this->info("超级管理员邮箱: {$email}");
        $this->warn('请立即修改超级管理员默认密码。');

        return self::SUCCESS;
    }
}
