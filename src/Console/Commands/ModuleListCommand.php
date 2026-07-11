<?php

namespace MultiTenantSaas\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Services\ModuleManager;

class ModuleListCommand extends Command
{
    protected $signature = 'module:list
        {--json : 以 JSON 格式输出}
        {--available : 列出 Packagist 上所有可用模块}';

    protected $description = '列出所有已安装模块及状态, --available 查询 Packagist 可用模块';

    public function handle(ModuleManager $manager): int
    {
        if ($this->option('available')) {
            return $this->listAvailable();
        }

        return $this->listInstalled($manager);
    }

    protected function listInstalled(ModuleManager $manager): int
    {
        $modules = $manager->listAll();

        if ($this->option('json')) {
            $this->line(json_encode($modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (empty($modules)) {
            $this->info('未发现任何已安装模块');

            return self::SUCCESS;
        }

        $headers = ['名称', '版本', '优先级', '描述', '状态', '租户切换', '依赖', '互斥'];
        $rows = [];

        foreach ($modules as $module) {
            $rows[] = [
                $module['name'],
                $module['version'],
                $module['priority'] ?? 100,
                mb_strimwidth($module['description'], 0, 40, '...'),
                $module['status'],
                $module['tenant_toggleable'] ? '是' : '否',
                implode(', ', $module['dependencies']) ?: '-',
                implode(', ', $module['conflicts']) ?: '-',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    protected function listAvailable(): int
    {
        $this->info('查询 Packagist 可用模块...');

        $packages = $this->queryPackagist();

        if (empty($packages)) {
            $this->warn('未找到可用模块或查询失败');

            return self::FAILURE;
        }

        $headers = ['包名', '描述', '最新版本'];
        $rows = [];

        foreach ($packages as $pkg) {
            $rows[] = [
                $pkg['name'],
                mb_strimwidth($pkg['description'] ?? '', 0, 50, '...'),
                $pkg['version'] ?? '-',
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();
        $this->line('安装: composer require <包名>');

        return self::SUCCESS;
    }

    /**
     * 查询 Packagist 上 dsplat/multi-tenant-saas-module-* 包。
     *
     * @return array<int, array{name: string, description: string, version: string}>
     */
    protected function queryPackagist(): array
    {
        $url = 'https://packagist.org/search.json?q=dsplat/multi-tenant-saas-module&type=library&per_page=50';

        $context = stream_context_create([
            'http' => ['timeout' => 10, 'ignore_errors' => true],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [];
        }

        $data = json_decode($response, true);

        if (! is_array($data) || ! isset($data['results'])) {
            return [];
        }

        $packages = [];

        foreach ($data['results'] as $pkg) {
            $name = $pkg['name'] ?? '';

            if (! str_starts_with($name, 'dsplat/multi-tenant-saas-module-')) {
                continue;
            }

            $packages[] = [
                'name' => $name,
                'description' => $pkg['description'] ?? '',
                'version' => $pkg['version'] ?? '-',
            ];
        }

        // 按名称排序
        usort($packages, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $packages;
    }
}
