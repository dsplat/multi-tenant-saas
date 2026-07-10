<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 模块脚手架命令 — 一键创建 + 发布
 *
 * 本地创建:
 *   php artisan module:create lottery --priority=300 --toggleable --description="抽奖系统"
 *
 * 全自动 (本地 + GitHub + Packagist):
 *   php artisan module:create lottery --priority=300 --toggleable --description="抽奖系统" --publish
 *
 * --publish 会自动:
 *   1. 创建 GitHub 仓库 (dsplat/multi-tenant-saas-module-{name})
 *   2. 注册到 Packagist
 *   3. 添加 Packagist webhook
 *   4. 添加到 .github/workflows/split.yml matrix
 *   5. 添加到根 composer.json require
 */
class ModuleCreateCommand extends Command
{
    protected $signature = 'module:create
        {name : 模块名称 (如 lottery, sms, coupon)}
        {--priority=300 : 加载优先级 (越小越先加载)}
        {--toggleable : 支持租户级启停}
        {--no-default : 默认禁用}
        {--description= : 模块描述}
        {--dependencies=* : 依赖的模块名 (逗号分隔)}
        {--publish : 创建后自动发布到 GitHub + Packagist}
        {--packagist-user= : Packagist 用户名 (默认从配置读取)}
        {--packagist-token= : Packagist API token (默认从配置读取)}
        {--github-org= : GitHub 组织名 (默认 dsplat)}';

    protected $description = '创建新模块骨架, --publish 自动发布到 GitHub + Packagist';

    public function handle(): int
    {
        $name = $this->argument('name');
        $studly = Str::studly($name);
        $moduleDir = base_path("src/Modules/{$studly}");

        if (is_dir($moduleDir)) {
            $this->error("模块目录已存在: src/Modules/{$studly}");

            return self::FAILURE;
        }

        // Step 1: 创建本地文件
        $this->info('Creating module structure...');
        $this->createDirectoryStructure($moduleDir);
        $this->createComposerJson($moduleDir, $name, $studly);
        $this->createGitAttributes($moduleDir);
        $this->createServiceProvider($moduleDir, $studly);
        $this->createRouteFiles($moduleDir);
        $this->createConfigFile($moduleDir, $name);
        $this->createStubFiles($moduleDir, $studly);
        $this->info("  ✓ 模块骨架: src/Modules/{$studly}/");

        // Step 2: 添加到根 composer.json
        $this->addToRootComposer($name);
        $this->info('  ✓ 根 composer.json require 已更新');

        // Step 3: 添加到 workflow matrix
        $this->addToWorkflowMatrix($studly);
        $this->info('  ✓ GitHub Actions workflow matrix 已更新');

        if (! $this->option('publish')) {
            $this->printManualSteps($name, $studly);

            return self::SUCCESS;
        }

        // === 自动发布流程 ===
        $githubOrg = $this->option('github-org') ?: 'dsplat';
        $packagistUser = $this->option('packagist-user') ?: config('tenancy.packagist.user', '');
        $packagistToken = $this->option('packagist-token') ?: config('tenancy.packagist.token', '');
        $repoName = "multi-tenant-saas-module-{$name}";

        // Step 4: 创建 GitHub 仓库
        $this->newLine();
        $this->info('Publishing module...');
        $this->createGithubRepo($githubOrg, $repoName, $packagistUser, $packagistToken);

        // Step 5: 推送 composer.json 到仓库 (Packagist 需要)
        $this->pushComposerJson($githubOrg, $repoName, $studly);

        // Step 6: 注册 Packagist
        $this->registerPackagist($packagistUser, $packagistToken, $githubOrg, $repoName);

        // Step 7: 添加 Packagist webhook
        $this->addPackagistWebhook($githubOrg, $repoName, $packagistUser, $packagistToken);

        $this->newLine();
        $this->info("Module [{$name}] created and published!");
        $this->line('  Push 到 main 后 GitHub Actions 会自动分包并触发 Packagist 更新');

        return self::SUCCESS;
    }

    protected function createGithubRepo(string $org, string $repo, string $packagistUser, string $packagistToken): void
    {
        $this->line("  Creating GitHub repo {$org}/{$repo}...");

        $process = proc_open(
            "gh repo create {$org}/{$repo} --public --description=\"" . ($this->option('description') ?: $this->argument('name') . ' 模块') . '" 2>&1',
            [STDIN, ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0) {
            $this->info("  ✓ GitHub repo: https://github.com/{$org}/{$repo}");
            sleep(2); // 等待 GitHub 初始化仓库
        } else {
            $errorMsg = trim($output ?: $stderr);
            if (str_contains($errorMsg, 'already exists') || str_contains($errorMsg, 'name already exists')) {
                $this->warn("  ⊙ GitHub repo 已存在: {$org}/{$repo}");
            } else {
                $this->error("  ✗ 创建 GitHub repo 失败: {$errorMsg}");
            }
        }
    }

    protected function pushComposerJson(string $org, string $repo, string $studly): void
    {
        $this->line('  Pushing composer.json to repo...');

        $composerPath = base_path("src/Modules/{$studly}/composer.json");
        if (! file_exists($composerPath)) {
            $this->warn('  ⊙ composer.json not found, skipping');

            return;
        }

        $ghToken = $this->getGhToken();
        if (empty($ghToken)) {
            $this->warn('  ⊙ GitHub token not available, skipping');

            return;
        }

        $content = base64_encode(file_get_contents($composerPath));
        $payload = json_encode([
            'message' => 'init: add composer.json',
            'content' => $content,
            'branch' => 'main',
        ]);

        $response = $this->httpPost(
            "https://api.github.com/repos/{$org}/{$repo}/contents/composer.json",
            $payload,
            ["Authorization: token {$ghToken}", 'Accept: application/vnd.github+json'],
            'PUT'
        );

        if (str_contains($response, '"sha"') || str_contains($response, '"content"')) {
            $this->info('  ✓ composer.json pushed');
        } else {
            $this->warn('  ⊙ Failed to push composer.json: ' . substr($response, 0, 150));
        }
    }

    protected function registerPackagist(string $user, string $token, string $org, string $repo): void
    {
        if (empty($user) || empty($token)) {
            $this->warn('  ⊙ Packagist 凭据未配置, 跳过注册 (用 --packagist-user 和 --packagist-token 指定)');

            return;
        }

        $this->line('  Registering on Packagist...');

        $repoUrl = "https://github.com/{$org}/{$repo}";
        $response = $this->httpPost(
            "https://packagist.org/api/create-package?username={$user}&apiToken={$token}",
            json_encode(['repository' => $repoUrl])
        );

        if (str_contains($response, '"status":"success"')) {
            $this->info("  ✓ Packagist: https://packagist.org/packages/{$org}/{$repo}");
        } elseif (str_contains($response, 'already exists')) {
            $this->warn("  ⊙ Packagist 包已存在: {$org}/{$repo}");
        } else {
            $this->error("  ✗ Packagist 注册失败: {$response}");
        }
    }

    protected function addPackagistWebhook(string $org, string $repo, string $packagistUser, string $packagistToken): void
    {
        if (empty($packagistUser) || empty($packagistToken)) {
            $this->warn('  ⊙ Packagist 凭据未配置, 跳过 webhook');

            return;
        }

        $this->line('  Adding Packagist webhook...');

        $webhookUrl = "https://packagist.org/api/update-package?username={$packagistUser}&apiToken={$packagistToken}";

        $payload = json_encode([
            'name' => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => [
                'url' => $webhookUrl,
                'content_type' => 'json',
            ],
        ]);

        $ghToken = $this->getGhToken();
        $response = $this->httpPost(
            "https://api.github.com/repos/{$org}/{$repo}/hooks",
            $payload,
            ["Authorization: token {$ghToken}", 'Accept: application/vnd.github+json']
        );

        if (str_contains($response, '"id"')) {
            $this->info('  ✓ Packagist webhook 已添加');
        } else {
            $this->warn('  ⊙ Packagist webhook 添加失败或已存在');
        }
    }

    protected function addToRootComposer(string $name): void
    {
        $composerPath = base_path('composer.json');
        $composer = json_decode((string) File::get($composerPath), true);

        $packageName = "dsplat/multi-tenant-saas-module-{$name}";

        if (isset($composer['require-dev'][$packageName]) || isset($composer['require'][$packageName])) {
            return;
        }

        // 模块加到 require-dev (本地开发用, Packagist 分发时不包含)
        $composer['require-dev'][$packageName] = '*';

        File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    protected function addToWorkflowMatrix(string $studly): void
    {
        $workflowPath = base_path('.github/workflows/split.yml');
        $content = File::get($workflowPath);

        $repoName = 'multi-tenant-saas-module-' . Str::kebab($studly);
        $prefix = "src/Modules/{$studly}";

        // 检查是否已存在
        if (str_contains($content, $repoName)) {
            return;
        }

        // 在最后一个 matrix entry 后面添加新条目
        $newEntry = "          - prefix: '{$prefix}'\n            repo: {$repoName}";

        // 找到最后一个 "repo:" 行, 在其后插入
        $lastRepoPos = strrpos($content, 'repo:');
        if ($lastRepoPos === false) {
            return;
        }

        // 找到该行末尾
        $lineEnd = strpos($content, "\n", $lastRepoPos);
        if ($lineEnd === false) {
            return;
        }

        $content = substr($content, 0, $lineEnd + 1) . "\n" . $newEntry . substr($content, $lineEnd + 1);

        File::put($workflowPath, $content);
    }

    protected function getGhToken(): string
    {
        $output = '';
        exec('gh auth token 2>/dev/null', $output);

        return trim(implode('', $output));
    }

    protected function httpPost(string $url, string $body, array $headers = [], string $method = 'POST'): string
    {
        $defaultHeaders = ['Content-Type: application/json'];
        $headers = array_merge($defaultHeaders, $headers);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'multi-tenant-saas-module-create');
        $response = curl_exec($ch);
        curl_close($ch);

        return $response ?: '';
    }

    protected function printManualSteps(string $name, string $studly): void
    {
        $this->newLine();
        $this->info("Module [{$name}] created locally!");
        $this->newLine();
        $this->line('下一步 (开发):');
        $this->line("  1. 编辑 src/Modules/{$studly}/composer.json 的 extra.saas");
        $this->line('  2. 在 ServiceProvider 中注册服务绑定');
        $this->line('  3. 在 Routes/api.php 中定义路由');
        $this->line('  4. composer update && php artisan module:list');
        $this->newLine();
        $this->line('发布到 Packagist (用 --publish 自动完成):');
        $this->line("  1. gh repo create dsplat/multi-tenant-saas-module-{$name} --public");
        $this->line("  2. curl -X POST 'https://packagist.org/api/create-package?username=USER&apiToken=TOKEN' -d '...'");
        $this->line('  3. push 到 main 后 GitHub Actions 自动分包 + Packagist 更新');
    }

    protected function createDirectoryStructure(string $moduleDir): void
    {
        $dirs = [
            'Models',
            'Services',
            'Http/Controllers',
            'Http/Requests',
            'Http/Resources',
            'Config',
            'Database/migrations',
            'Routes',
            'Console/Commands',
            'Events',
            'Listeners',
            'Policies',
            'resources/views',
            'resources/lang',
        ];

        foreach ($dirs as $dir) {
            $path = $moduleDir . '/' . $dir;
            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    protected function createComposerJson(string $moduleDir, string $name, string $studly): void
    {
        $description = $this->option('description') ?: ($name . ' 模块');
        $priority = (int) $this->option('priority');
        $toggleable = $this->option('toggleable');
        $defaultEnabled = ! $this->option('no-default');
        $dependencies = $this->option('dependencies') ?: [];

        $deps = [];
        foreach ($dependencies as $dep) {
            foreach (explode(',', $dep) as $d) {
                $d = trim($d);
                if ($d !== '') {
                    $deps[] = $d;
                }
            }
        }
        $dependencies = array_values(array_unique($deps));

        $data = [
            'name' => "dsplat/multi-tenant-saas-module-{$name}",
            'version' => '1.0.0',
            'description' => $description,
            'type' => 'library',
            'license' => 'MIT',
            'require' => [
                'php' => '^8.3',
                'dsplat/multi-tenant-saas' => '^2.0',
            ],
            'autoload' => [
                'psr-4' => [
                    "MultiTenantSaas\\Modules\\{$studly}\\" => '',
                ],
            ],
            'extra' => [
                'saas' => [
                    'name' => $name,
                    'priority' => $priority,
                    'dependencies' => $dependencies,
                    'conflicts' => [],
                    'requires_core' => '>=2.0.0',
                    'provider' => "MultiTenantSaas\\Modules\\{$studly}\\{$studly}ServiceProvider",
                    'tenant_toggleable' => $toggleable,
                    'default_enabled' => $defaultEnabled,
                ],
            ],
        ];

        file_put_contents(
            $moduleDir . '/composer.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    protected function createGitAttributes(string $moduleDir): void
    {
        $stub = "/tests          export-ignore\n/.gitattributes export-ignore\n/.gitignore     export-ignore\nphpunit.xml     export-ignore\n";
        file_put_contents($moduleDir . '/.gitattributes', $stub);
    }

    protected function createServiceProvider(string $moduleDir, string $studly): void
    {
        $stub = <<<PHP
<?php

namespace MultiTenantSaas\\Modules\\{$studly};

use MultiTenantSaas\\Modules\\Contracts\\ModuleServiceProvider;

class {$studly}ServiceProvider extends ModuleServiceProvider
{
    protected string \$moduleName = '{$this->argument('name')}';

    protected function registerModuleBindings(): void
    {
        // \$this->app->singleton(YourService::class);
    }

    protected function bootModule(): void
    {
        //
    }

    protected function registerModuleCommands(): void
    {
        // if (\$this->app->runningInConsole()) {
        //     \$this->commands([YourCommand::class]);
        // }
    }
}

PHP;

        file_put_contents($moduleDir . "/{$studly}ServiceProvider.php", $stub);
    }

    protected function createRouteFiles(string $moduleDir): void
    {
        $name = $this->argument('name');

        $apiStub = <<<PHP
<?php

// use App\\Http\\Controllers\\Api\\YourController;

// ========== {$name} API 路由 ==========
// Route::prefix('/{$name}')->group(function () {
//     Route::get('/', [YourController::class, 'index']);
//     Route::post('/', [YourController::class, 'store']);
//     Route::get('/{id}', [YourController::class, 'show']);
//     Route::put('/{id}', [YourController::class, 'update']);
//     Route::delete('/{id}', [YourController::class, 'destroy']);
// });

PHP;

        $adminStub = <<<PHP
<?php

// ========== {$name} 系统管理路由 ==========
// Route::get('/config', [AdminController::class, 'config']);
// Route::put('/config', [AdminController::class, 'updateConfig']);

PHP;

        $tenantStub = <<<PHP
<?php

// ========== {$name} 租户管理路由 ==========
// Route::prefix('/tenants/{tenantId}/{$name}')->group(function () {
//     Route::get('/settings', [TenantController::class, 'settings']);
//     Route::put('/settings', [TenantController::class, 'updateSettings']);
// });

PHP;

        $publicStub = <<<PHP
<?php

// ========== {$name} 公开路由 (无需认证) ==========
// Route::post('/webhook/{$name}', [WebhookController::class, 'handle']);

PHP;

        file_put_contents($moduleDir . '/routes/api.php', $apiStub);
        file_put_contents($moduleDir . '/routes/admin.php', $adminStub);
        file_put_contents($moduleDir . '/routes/tenant.php', $tenantStub);
        file_put_contents($moduleDir . '/routes/public.php', $publicStub);
    }

    protected function createConfigFile(string $moduleDir, string $name): void
    {
        $stub = <<<PHP
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | {$name} 模块配置
    |--------------------------------------------------------------------------
    */

    'enabled' => true,
];

PHP;

        file_put_contents($moduleDir . '/Config/' . Str::studly($name) . '.php', $stub);
    }

    protected function createStubFiles(string $moduleDir, string $studly): void
    {
        $emptyDirs = [
            'Models',
            'Services',
            'Http/Controllers',
            'Http/Requests',
            'Http/Resources',
            'Database/migrations',
            'Console/Commands',
            'Events',
            'Listeners',
            'Policies',
            'resources/views',
            'resources/lang',
        ];

        foreach ($emptyDirs as $dir) {
            $path = $moduleDir . '/' . $dir . '/.gitkeep';
            if (! file_exists($path)) {
                file_put_contents($path, '');
            }
        }
    }
}
