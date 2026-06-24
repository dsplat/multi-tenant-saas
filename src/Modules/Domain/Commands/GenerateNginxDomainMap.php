<?php

namespace MultiTenantSaas\Modules\Domain\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Domain\Services\NginxConfigService;

class GenerateNginxDomainMap extends Command
{
    protected $signature = 'domains:generate-nginx-map
                          {--output= : 输出文件路径（默认：使用配置文件中的路径）}
                          {--reload : 生成后自动reload Nginx}';

    protected $description = '从数据库生成Nginx域名白名单map配置文件';

    public function handle(NginxConfigService $service): int
    {
        $this->info(trans('domain.generating_nginx_config'));

        $outputPath = $this->option('output');

        $service->generateDomainWhitelistMap($outputPath);

        $finalPath = $outputPath ?? config('domain.nginx_map_file', '/etc/nginx/conf.d/allowed-domains.map');
        $this->info(trans('domain.config_generated', ['path' => $finalPath]));

        if ($this->option('reload')) {
            $this->newLine();
            $this->info(trans('domain.reloading_nginx'));

            $testResult = shell_exec('nginx -t 2>&1');
            $this->line($testResult);

            if (str_contains($testResult, 'syntax is ok') && str_contains($testResult, 'test is successful')) {
                if (PHP_OS_FAMILY === 'Darwin') {
                    $reloadResult = shell_exec('brew services reload nginx 2>&1');
                } else {
                    $reloadResult = shell_exec('sudo nginx -s reload 2>&1');
                }
                $this->info(trans('domain.nginx_reloaded', ['result' => $reloadResult]));
            } else {
                $this->error(trans('domain.nginx_test_failed'));
                return self::FAILURE;
            }
        } else {
            $this->newLine();
            $this->comment(trans('domain.manual_reload_hint'));
            if (PHP_OS_FAMILY === 'Darwin') {
                $this->line('  nginx -t && brew services reload nginx');
            } else {
                $this->line('  nginx -t && sudo nginx -s reload');
            }
        }

        return self::SUCCESS;
    }
}
