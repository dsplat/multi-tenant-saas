<?php

namespace MultiTenantSaas\Modules\Domain\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use MultiTenantSaas\Models\Tenant;

class UpdateTenantDomain extends Command
{
    protected $signature = 'tenant:update-domain
                            {old_domain : 旧自定义域名}
                            {new_domain : 新自定义域名}
                            {--regenerate-map : 同时重新生成 nginx 域名白名单}
                            {--map-output= : nginx map 文件输出路径}
                            {--reload-nginx : 重新加载 nginx}';

    protected $description = '更新租户自定义域名，并可选同步重新生成 nginx 白名单';

    public function handle(): int
    {
        $old = strtolower(trim($this->argument('old_domain')));
        $new = strtolower(trim($this->argument('new_domain')));

        $tenant = Tenant::where('custom_domain', $old)->first();

        if (!$tenant) {
            $this->error(trans('domain.tenant_not_found_by_domain', ['domain' => $old]));
            $this->line(trans('domain.existing_custom_domains'));
            Tenant::whereNotNull('custom_domain')->get(['name', 'custom_domain'])->each(
                fn ($t) => $this->line("  [{$t->name}] {$t->custom_domain}")
            );

            return self::FAILURE;
        }

        $this->info(trans('domain.tenant_info', ['name' => $tenant->name, 'id' => $tenant->tenant_id]));
        $this->line('  ' . trans('domain.old_domain') . ": {$old}");
        $this->line('  ' . trans('domain.new_domain') . ": {$new}");

        if (!$this->confirm(trans('domain.confirm_update'), true)) {
            $this->warn(trans('common.cancelled'));
            return self::SUCCESS;
        }

        $tenant->update(['custom_domain' => $new]);
        $this->info(trans('domain.db_updated'));

        if ($this->option('regenerate-map')) {
            $output = $this->option('map-output');
            $this->line(trans('domain.regenerating_nginx_map'));

            $params = [];
            if ($output) {
                $params['--output'] = $output;
            }
            if ($this->option('reload-nginx')) {
                $params['--reload'] = true;
            }

            $exitCode = Artisan::call('domains:generate-nginx-map', $params);

            if ($exitCode === 0) {
                $this->info(trans('domain.nginx_map_regenerated'));
            } else {
                $this->warn(trans('domain.nginx_map_failed'));
            }
        } else {
            $this->warn(trans('domain.manual_reload_hint'));
            $this->line('  php artisan domains:generate-nginx-map --reload');
        }

        return self::SUCCESS;
    }
}
