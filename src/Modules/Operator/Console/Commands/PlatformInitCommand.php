<?php

namespace MultiTenantSaas\Modules\Operator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use MultiTenantSaas\Modules\Operator\Database\Seeders\PlatformInitSeeder;

/**
 * 平台初始化命令
 *
 * 首次部署时运行，完成以下步骤：
 * 1. 运行数据库迁移
 * 2. 创建平台默认租户、系统角色、权限节点、超级管理员
 * 3. 部署 server.php（PHP 内置服务器 SPA 路由修复）
 *
 * 已初始化时可使用 --force 重新执行 seeder 部分。
 */
class PlatformInitCommand extends Command
{
    protected $signature = 'platform:init
        {--password= : 超级管理员密码}
        {--email= : 超级管理员邮箱}
        {--force : 强制重新执行 seeder}
        {--skip-migrate : 跳过迁移}
        {--skip-server : 跳过 server.php 部署}
        {--with-test-data : 同时创建测试数据（TestSeeder）}';

    protected $description = '初始化平台：迁移 + 租户/角色/权限/管理员 + server.php';

    public function handle(): int
    {
        $platformTenantId = config('id.platform_tenant_id', 9007199254740991);

        // 检查是否已初始化
        $initialized = DB::table('tenants')
            ->where('tenant_id', $platformTenantId)
            ->where('is_platform_default', true)
            ->exists();

        if ($initialized && ! $this->option('force')) {
            $this->warn('平台已初始化。使用 --force 重新执行 seeder。');
            $adminEmail = env('SUPER_ADMIN_EMAIL', 'admin@platform.local');
            $this->info("超级管理员邮箱: {$adminEmail}");

            $this->deployServerPhp();

            return self::SUCCESS;
        }

        $this->info('=== 平台初始化 ===');
        $this->newLine();

        // Step 1: 迁移
        if (! $this->option('skip-migrate')) {
            $this->info('[1/3] 运行数据库迁移...');
            Artisan::call('migrate', ['--force' => true]);
            $this->info('  迁移完成');
        }

        // Step 2: Seeder
        $this->info('[2/3] 执行平台初始化 seeder...');

        if ($email = $this->option('email')) {
            putenv("SUPER_ADMIN_EMAIL={$email}");
        }
        if ($password = $this->option('password')) {
            putenv("SUPER_ADMIN_PASSWORD={$password}");
        }

        Artisan::call('db:seed', [
            '--class' => PlatformInitSeeder::class,
            '--force' => true,
        ]);

        $email = $this->option('email') ?? env('SUPER_ADMIN_EMAIL', 'admin@platform.local');
        $this->info('  超级管理员: ' . $email);

        // Step 3: server.php
        $this->deployServerPhp();

        // Step 4: 测试数据（可选）
        if ($this->option('with-test-data')) {
            $this->info('[4/4] 创建测试数据...');
            $testSeederClass = 'Database\Seeders\TestSeeder';
            if (class_exists($testSeederClass)) {
                Artisan::call('db:seed', ['--class' => $testSeederClass, '--force' => true]);
                $this->info('  测试数据创建完成');
                $this->info('  测试账号密码统一为: Test@123456');
                $this->info('  详见: docs/test-accounts.md');
            } else {
                $this->warn('  TestSeeder 不存在（下游项目需创建）');
            }
        }

        $this->newLine();
        $this->info('=== 初始化完成 ===');
        $this->info("超级管理员邮箱: {$email}");
        $this->warn('请立即修改超级管理员默认密码。');

        return self::SUCCESS;
    }

    /**
     * 部署 server.php 到项目根目录
     *
     * 解决 PHP 内置服务器 (php artisan serve) 的 SPA 路由问题：
     * public/admin/index.html 存在时，PHP 会把 SCRIPT_NAME 设为
     * /admin/index.html，导致 Laravel 的 Request::path() 返回
     * "dashboard" 而非 "admin/dashboard"，路由匹配失败。
     *
     * server.php 覆盖 SCRIPT_NAME 为 /index.php（仅非 API 请求）。
     */
    private function deployServerPhp(): void
    {
        if ($this->option('skip-server')) {
            return;
        }

        $target = base_path('server.php');
        $source = __DIR__ . '/../../../../server.php';

        // 优先用框架自带的 server.php
        if (File::exists($source)) {
            if (File::exists($target)) {
                $currentHash = md5(File::get($target));
                $sourceHash = md5(File::get($source));

                if ($currentHash === $sourceHash) {
                    $this->info('[3/3] server.php 已是最新');

                    return;
                }

                $this->warn('[3/3] server.php 已存在，正在更新...');
            } else {
                $this->info('[3/3] 部署 server.php...');
            }

            File::copy($source, $target);
            $this->info('  server.php 已部署到项目根目录');
        } elseif (! File::exists($target)) {
            // 框架文件不存在，生成一个
            $this->info('[3/3] 生成 server.php...');
            File::put($target, $this->generateServerPhp());
            $this->info('  server.php 已生成');
        } else {
            $this->info('[3/3] server.php 已存在，跳过');
        }
    }

    /**
     * 生成 server.php 内容
     */
    private function generateServerPhp(): string
    {
        return <<<'PHP'
<?php

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// Only serve actual static files — not directories (which cause
// PHP's built-in server to set SCRIPT_FILENAME to their index.html,
// breaking Laravel's Request::path() for SPA catch-all routes).
if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

// Fix: PHP's built-in server resolves /admin/dashboard to
// SCRIPT_NAME=/admin/index.html when public/admin/index.html exists.
// Override to use /index.php so Laravel's Request::path() is correct.
if (! str_starts_with($uri, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $publicPath.'/index.php';
}

$formattedDateTime = date('D M j H:i:s Y');
$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];
file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
PHP;
    }
}
