<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;

/**
 * 数据隔离安全检查命令
 *
 * 检查所有 Eloquent 模型是否正确应用了 TenantScope
 * 检查是否有代码绕过租户隔离
 */
class CheckTenantIsolation extends Command
{
    protected $signature = 'tenancy:check-isolation
                            {--path=app : 检查的目录路径}
                            {--fix : 自动修复发现的问题}
                            {--detail : 显示详细信息}';

    protected $description = '检查数据隔离安全性，确保所有模型都正确应用了 TenantScope';

    /**
     * 需要检查的目录
     */
    protected array $scanPaths = [
        'app/Models',
        'src/Models',
    ];

    /**
     * 已知的安全全局作用域
     */
    protected array $safeScopes = [
        \MultiTenantSaas\Scopes\TenantScope::class,
    ];

    /**
     * 不需要租户隔离的模型
     */
    protected array $excludedModels = [
        'Tenant.php',      // 租户模型本身
        'User.php',        // 用户模型（可能属于多个租户）
        'SystemSetting.php', // 系统配置
    ];

    /**
     * 已知的安全绕过方法
     */
    protected array $safeBypassMethods = [
        'withoutTenantScope',
        'withTenant',
        'forAllTenants',
        'withoutGlobalScope',
        'withoutGlobalScopes',
    ];

    /**
     * 发现的问题
     */
    protected array $issues = [];

    /**
     * 检查的模型数量
     */
    protected int $modelsChecked = 0;

    /**
     * 检查的文件数量
     */
    protected int $filesChecked = 0;

    public function handle(): int
    {
        $this->info('🔍 开始检查数据隔离安全性...');
        $this->newLine();

        // 1. 检查模型是否应用了 TenantScope
        $this->checkModelsForTenantScope();

        // 2. 检查是否有不安全的查询
        $this->checkUnsafeQueries();

        // 3. 检查是否有不安全的原生 SQL
        $this->checkRawQueries();

        // 4. 检查中间件配置
        $this->checkMiddlewareConfiguration();

        // 5. 输出报告
        $this->outputReport();

        return empty($this->issues) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 检查模型是否应用了 TenantScope
     */
    protected function checkModelsForTenantScope(): void
    {
        $this->info('📋 检查模型是否应用了 TenantScope...');

        foreach ($this->scanPaths as $path) {
            $fullPath = base_path($path);
            
            if (!File::isDirectory($fullPath)) {
                continue;
            }

            $files = File::glob($fullPath . '/*.php');
            
            foreach ($files as $file) {
                $this->filesChecked++;
                $this->checkModelFile($file);
            }
        }
    }

    /**
     * 检查单个模型文件
     */
    protected function checkModelFile(string $filePath): void
    {
        $content = File::get($filePath);
        $relativePath = str_replace(base_path() . '/', '', $filePath);
        $fileName = basename($filePath);

        // 排除不需要检查的模型
        if (in_array($fileName, $this->excludedModels)) {
            if ($this->option('detail')) {
                $this->line("  ⏭️ {$relativePath} - 已排除（不需要租户隔离）");
            }
            return;
        }

        // 检查是否是 Eloquent 模型
        if (!str_contains($content, 'extends Model') && 
            !str_contains($content, 'extends Authenticatable') &&
            !str_contains($content, 'use BelongsToTenant')) {
            return;
        }

        $this->modelsChecked++;

        // 检查是否使用了 BelongsToTenant trait
        if (str_contains($content, 'use BelongsToTenant')) {
            if ($this->option('detail')) {
                $this->line("  ✅ {$relativePath} - 使用 BelongsToTenant trait");
            }
            return;
        }

        // 检查是否手动添加了 TenantScope
        if (str_contains($content, 'TenantScope') || 
            str_contains($content, 'addGlobalScope')) {
            if ($this->option('detail')) {
                $this->line("  ✅ {$relativePath} - 手动添加了全局作用域");
            }
            return;
        }

        // 检查是否有 tenant_id 字段（可能是租户相关模型）
        if (str_contains($content, 'tenant_id') || 
            str_contains($content, 'BelongsToTenant')) {
            $this->addIssue(
                'warning',
                $relativePath,
                '模型可能需要添加 BelongsToTenant trait 以启用数据隔离'
            );
        }
    }

    /**
     * 检查不安全的查询
     */
    protected function checkUnsafeQueries(): void
    {
        $this->info('🔍 检查不安全的查询...');

        $patterns = [
            // withoutGlobalScope 使用
            '/->withoutGlobalScope\s*\(/' => '使用 withoutGlobalScope 可能绕过租户隔离',
            '/->withoutGlobalScopes\s*\(/' => '使用 withoutGlobalScopes 可能绕过租户隔离',
            
            // DB facade 使用
            '/DB::(select|insert|update|delete|table)\s*\(/' => '使用 DB facade 可能绕过 Eloquent 作用域',
            '/DB::raw\s*\(/' => '使用 DB::raw 可能绕过租户隔离',
            
            // 原生查询
            '/->toSql\s*\(/' => '使用 toSql 可能泄露跨租户数据',
            '/->getQuery\s*\(/' => '使用 getQuery 可能绕过作用域',
        ];

        foreach ($this->scanPaths as $path) {
            $fullPath = base_path($path);
            
            if (!File::isDirectory($fullPath)) {
                continue;
            }

            $files = File::allFiles($fullPath);
            
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = File::get($file->getPathname());
                $relativePath = str_replace(base_path() . '/', '', $file->getPathname());

                foreach ($patterns as $pattern => $message) {
                    if (preg_match($pattern, $content)) {
                        $this->addIssue('warning', $relativePath, $message);
                    }
                }
            }
        }
    }

    /**
     * 检查原生 SQL 查询
     */
    protected function checkRawQueries(): void
    {
        $this->info('🔍 检查原生 SQL 查询...');

        $patterns = [
            '/DB::raw\s*\(/' => '使用 DB::raw 可能绕过租户隔离',
            '/->whereRaw\s*\(/' => '使用 whereRaw 需要确保包含 tenant_id 条件',
            '/->havingRaw\s*\(/' => '使用 havingRaw 需要确保包含 tenant_id 条件',
            '/->orderByRaw\s*\(/' => '使用 orderByRaw 需要确保安全',
            '/->selectRaw\s*\(/' => '使用 selectRaw 需要确保安全',
            '/->groupByRaw\s*\(/' => '使用 groupByRaw 需要确保安全',
        ];

        $appPath = base_path('app');
        
        if (File::isDirectory($appPath)) {
            $files = File::allFiles($appPath);
            
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = File::get($file->getPathname());
                $relativePath = str_replace(base_path() . '/', '', $file->getPathname());

                foreach ($patterns as $pattern => $message) {
                    if (preg_match($pattern, $content)) {
                        $this->addIssue('info', $relativePath, $message);
                    }
                }
            }
        }
    }

    /**
     * 检查中间件配置
     */
    protected function checkMiddlewareConfiguration(): void
    {
        $this->info('🔍 检查中间件配置...');

        $bootstrapFile = base_path('bootstrap/app.php');
        
        if (!File::exists($bootstrapFile)) {
            $this->addIssue('error', 'bootstrap/app.php', '文件不存在');
            return;
        }

        $content = File::get($bootstrapFile);

        // 检查是否注册了 IdentifyDomain 中间件
        if (!str_contains($content, 'IdentifyDomain')) {
            $this->addIssue('error', 'bootstrap/app.php', '未注册 IdentifyDomain 中间件');
        }

        // 检查是否注册了 IdentifyTenant 中间件
        if (!str_contains($content, 'IdentifyTenant')) {
            $this->addIssue('error', 'bootstrap/app.php', '未注册 IdentifyTenant 中间件');
        }
    }

    /**
     * 添加问题
     */
    protected function addIssue(string $level, string $file, string $message): void
    {
        $this->issues[] = [
            'level' => $level,
            'file' => $file,
            'message' => $message,
        ];

        $icon = match ($level) {
            'error' => '❌',
            'warning' => '⚠️',
            'info' => 'ℹ️',
            default => '•',
        };

        $this->line("  {$icon} [{$level}] {$file}: {$message}");
    }

    /**
     * 输出报告
     */
    protected function outputReport(): void
    {
        $this->newLine();
        $this->info('📊 检查报告');
        $this->line('────────────────────────────────────────');
        $this->line("检查的文件数量: {$this->filesChecked}");
        $this->line("检查的模型数量: {$this->modelsChecked}");
        $this->line("发现的问题数量: " . count($this->issues));
        $this->line('────────────────────────────────────────');

        if (empty($this->issues)) {
            $this->newLine();
            $this->info('✅ 恭喜！未发现数据隔离安全问题。');
            return;
        }

        // 按级别分组统计
        $errors = array_filter($this->issues, fn($i) => $i['level'] === 'error');
        $warnings = array_filter($this->issues, fn($i) => $i['level'] === 'warning');
        $infos = array_filter($this->issues, fn($i) => $i['level'] === 'info');

        $this->newLine();
        
        if (!empty($errors)) {
            $this->error("❌ 发现 " . count($errors) . " 个严重问题");
        }
        
        if (!empty($warnings)) {
            $this->warn("⚠️ 发现 " . count($warnings) . " 个警告");
        }
        
        if (!empty($infos)) {
            $this->line("ℹ️ 发现 " . count($infos) . " 个提示");
        }

        // 输出建议
        $this->newLine();
        $this->info('💡 建议:');
        $this->line('1. 所有租户相关的模型应使用 BelongsToTenant trait');
        $this->line('2. 避免使用 withoutGlobalScope 绕过租户隔离');
        $this->line('3. 使用 DB facade 时确保包含 tenant_id 条件');
        $this->line('4. 定期运行此命令检查数据隔离安全性');
    }
}
