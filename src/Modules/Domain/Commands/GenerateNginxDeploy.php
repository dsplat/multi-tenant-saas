<?php

namespace MultiTenantSaas\Modules\Domain\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\Domain\Services\NginxConfigService;

/**
 * domains:generate-nginx
 *
 * 一键生成「租户域名接入层」的全部 nginx 产物到系统发布目录：
 * 白名单 map / SNI 证书 map / 基桩 server / 域名软链接 / 顶层 include。
 *
 * 系统 nginx 仅需在 http{} 层 include 一次 {deploy_path}/dsplat-tenants.conf。
 */
class GenerateNginxDeploy extends Command
{
    protected $signature = 'domains:generate-nginx
                          {--path= : 发布目录（默认：config domain.nginx_deploy_path 或 base_path/deploy/nginx）}
                          {--reload : 生成后自动 nginx -t 并 reload}';

    protected $description = '生成租户域名接入层的全部 nginx 产物（白名单map/SNI证书map/基桩/软链接/顶层include）';

    public function handle(NginxConfigService $service): int
    {
        $this->info(trans('domain.generating_nginx_config'));

        $result = $service->generateDeployBundle($this->option('path'));

        $this->newLine();
        $this->line("  发布目录:   <comment>{$result['deploy_path']}</comment>");
        $this->line("  白名单map:  {$result['auth_map']}");
        $this->line("  SNI证书map: {$result['ssl_map']}");
        $this->line("  SEO/GEO map:{$result['seo_map']}");
        $this->line("  AI爬虫map: {$result['bot_map']}");
        $this->line("  基桩server: {$result['stub']}");
        $this->line("  顶层include:{$result['top_include']}");
        $this->newLine();

        $domains = $result['domains'];
        if (count($domains) > 0) {
            $this->info('已授权域名（软链接）：' . count($domains) . ' 个');
            foreach ($domains as $domain) {
                $this->line("    - {$domain}");
            }
        } else {
            $this->comment('  (暂无已授权租户域名)');
        }

        $this->newLine();
        $this->comment('系统 nginx 需在 http{} 层 include 一次：');
        $this->line("  include {$result['deploy_path']}/dsplat-tenants.conf;");

        if ($this->option('reload')) {
            return $this->reloadNginx();
        }

        $this->newLine();
        $this->comment(trans('domain.manual_reload_hint'));
        $binary = config('domain.nginx_binary', 'nginx');
        $this->line("  {$binary} -t && {$binary} -s reload");

        return self::SUCCESS;
    }

    /**
     * nginx -t 校验通过后平滑 reload。
     */
    protected function reloadNginx(): int
    {
        $binary = config('domain.nginx_binary', 'nginx');

        $this->newLine();
        $this->info(trans('domain.reloading_nginx'));

        $testResult = (string) shell_exec("{$binary} -t 2>&1");
        $this->line($testResult);

        if (! (str_contains($testResult, 'syntax is ok') && str_contains($testResult, 'test is successful'))) {
            $this->error(trans('domain.nginx_test_failed'));

            return self::FAILURE;
        }

        $reloadResult = (string) shell_exec("{$binary} -s reload 2>&1");
        $this->info(trans('domain.nginx_reloaded', ['result' => $reloadResult ?: 'ok']));

        return self::SUCCESS;
    }
}
