<?php

namespace MultiTenantSaas\Modules\Domain\Services;

use Illuminate\Support\Facades\File;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * NginxConfigService
 *
 * 生成「租户域名接入层」的全部 nginx 产物，统一落在系统发布目录下，
 * 系统 nginx 仅需在 http{} 层 include 一次顶层文件 dsplat-tenants.conf：
 *
 *   {nginx_deploy_path}/
 *   ├── dsplat-tenants.conf      顶层 include（http 层）
 *   ├── maps/
 *   │   ├── tenant-auth.map      map $host $domain_allowed（白名单，default 0）
 *   │   └── ssl.map              map $ssl_server_name $ssl_cert_file/$ssl_key_file
 *   ├── stubs/
 *   │   └── tenant-server.conf   基桩：唯一 443 default_server，catch-all 租户域名
 *   └── tenants-enabled/         每域名一个软链接 → 基桩（源头真相 / 可巡检）
 *
 * 安全模型：基桩 default_server 捕获所有「无显式 vhost」的域名，由 $domain_allowed
 * map 决定放行或 return 444。恶意/未配置域名在 nginx 层即被拦截，不进入 PHP。
 *
 * 白名单精确性：二级域名仅放行「已配置且 slug_status=active」的 {slug}.<base>，
 * 不做通配放行（避免恶意子域名解析过来被服务）。
 */
class NginxConfigService
{
    private string $certsPath;

    private string $sslMapFile;

    private string $domainMapFile;

    public function __construct()
    {
        $this->certsPath = config('domain.ssl_certs_path', '/etc/nginx/ssl');
        $this->sslMapFile = config('domain.ssl_nginx_map_file', '/etc/nginx/conf.d/ssl-map.conf');
        $this->domainMapFile = config('domain.nginx_map_file', '/etc/nginx/conf.d/allowed-domains.map');
    }

    /**
     * 解析 nginx 产物发布根目录。
     *
     * 优先级：显式参数 > config('domain.nginx_deploy_path') > base_path('deploy/nginx')
     */
    public function resolveDeployPath(?string $basePath = null): string
    {
        if ($basePath) {
            return rtrim($basePath, '/');
        }

        $configured = config('domain.nginx_deploy_path');
        if ($configured) {
            return rtrim($configured, '/');
        }

        return rtrim(base_path('deploy/nginx'), '/');
    }

    /**
     * 生成域名白名单 map（map $host $domain_allowed）。
     *
     * default 0：未明确允许的域名（含恶意解析）一律拒绝。
     */
    public function generateDomainWhitelistMap(?string $outputPath = null): void
    {
        $outputPath = $outputPath ?? $this->domainMapFile;
        $generatedAt = now()->format('Y-m-d H:i:s');

        // 平台域名（始终允许；admin/app 可能指向同一域名，必须去重——
        // nginx map 键重复会触发 [emerg] conflicting parameter 致 nginx -t 失败）
        $platformDomains = array_values(array_unique(array_filter([
            config('domain.platform_domains.admin'),
            config('domain.platform_domains.app'),
            config('domain.platform_domains.console'),
        ])));
        $platformLines = $platformDomains
            ? implode("\n", array_map(fn ($d) => sprintf('    %-30s 1;', $d), $platformDomains))
            : '    # (未配置平台域名)';

        // 已放行域名集合（平台 + 二级域名）：自定义域名需排除，避免 map 键冲突
        $emitted = array_flip($platformDomains);
        foreach ($this->subdomainDomains() as $subdomain) {
            $emitted[$subdomain] = true;
        }

        // 企业自定义域名
        $tenants = Tenant::query()
            ->whereNotNull('domain')
            ->where('status', 'active')
            ->orderBy('domain')
            ->get(['tenant_id', 'name', 'domain']);

        $domainLines = [];
        foreach ($tenants as $tenant) {
            if ($tenant->domain && ! isset($emitted[$tenant->domain])) {
                $emitted[$tenant->domain] = true;
                $domainLines[] = sprintf(
                    '    %-30s 1;  # %s (tenant_id: %s)',
                    $tenant->domain,
                    $tenant->name,
                    $tenant->tenant_id
                );
            }
        }

        $domainsBlock = count($domainLines) > 0
            ? implode("\n", $domainLines)
            : '    # (暂无企业自定义域名)';

        $mapContent = implode("\n", [
            '# ===================================================',
            '# 允许的域名白名单（map $host $domain_allowed）',
            '#',
            '# 此文件由脚本自动生成，请勿手动编辑',
            '# default 0：未明确允许的域名（含恶意解析）一律拒绝',
            "# 更新时间: {$generatedAt}",
            '# ===================================================',
            '',
            'map $host $domain_allowed {',
            '    default 0;  # 默认拒绝所有未明确允许的域名',
            '',
            '    # ===== 平台域名（始终允许） =====',
            $platformLines,
            '',
            '    # ===== 租户二级域名（仅已配置 slug，精确放行） =====',
            $this->subdomainEntries(),
            '',
            '    # ===== 内部服务通信 =====',
            '    127.0.0.1               1;',
            '    localhost               1;',
            '',
            '    # ===== 企业自定义域名 =====',
            '    # AUTO_GENERATED_DOMAINS_START',
            "    # 生成时间: {$generatedAt}",
            '    # 域名数量: ' . count($domainLines),
            '',
            $domainsBlock,
            '    ',
            '    # AUTO_GENERATED_DOMAINS_END',
            '}',
            '',
        ]);

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $mapContent);
    }

    /**
     * 生成 SNI 证书 map（map $ssl_server_name $ssl_cert_file / $ssl_key_file）。
     *
     * default 指向通配证书（*.<wildcard_base>），自定义域名指向各自证书。
     */
    public function generateSslMap(?string $outputPath = null): void
    {
        $outputPath = $outputPath ?? $this->sslMapFile;

        $entries = Tenant::query()
            ->whereNotNull('domain')
            ->whereNotNull('ssl_uploaded_at')
            ->get(['domain'])
            ->filter(fn ($t) => file_exists("{$this->certsPath}/{$t->domain}.crt"))
            ->map(fn ($t) => $t->domain)
            ->values();

        $certLines = implode("\n", $entries->map(
            fn ($d) => "    {$d}  {$this->certsPath}/{$d}.crt;"
        )->all());
        $keyLines = implode("\n", $entries->map(
            fn ($d) => "    {$d}  {$this->certsPath}/{$d}.key;"
        )->all());

        $defaultCert = "{$this->certsPath}/default.crt";
        $defaultKey = "{$this->certsPath}/default.key";

        $mapContent = implode("\n", [
            '# 自动生成 — 勿手动编辑（由 NginxConfigService 生成）',
            '# 最后更新: ' . now()->toDateTimeString(),
            '',
            'map $ssl_server_name $ssl_cert_file {',
            "    default  {$defaultCert};",
            $certLines ?: '',
            '}',
            '',
            'map $ssl_server_name $ssl_key_file {',
            "    default  {$defaultKey};",
            $keyLines ?: '',
            '}',
            '',
        ]);

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $mapContent);
    }

    /**
     * 生成租户二级域名白名单条目（精确放行已配置 slug）。
     *
     * 例: lanyantu.dsplat.com  1;
     * 不做通配放行——恶意子域名（如 evilrandom.dsplat.com）不在列表 → default 0 → 拒绝。
     */
    protected function subdomainEntries(): string
    {
        if (! config('domain.wildcard_base')) {
            return '    # (未配置通配基础域名)';
        }

        $domains = $this->subdomainDomains();

        if ($domains === []) {
            return '    # (暂无启用的租户二级域名)';
        }

        return implode("\n", array_map(
            fn ($d) => sprintf('    %-30s 1;  # slug: %s', $d, explode('.', $d)[0]),
            $domains
        ));
    }

    /**
     * 已启用租户的二级域名列表（{slug}.<wildcard_base>）。
     *
     * @return string[]
     */
    protected function subdomainDomains(): array
    {
        $wildcardBase = config('domain.wildcard_base');

        if (! $wildcardBase) {
            return [];
        }

        return Tenant::query()
            ->where('status', 'active')
            ->where('slug_status', 'active')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('slug')
            ->pluck('slug')
            ->map(fn ($slug) => "{$slug}.{$wildcardBase}")
            ->values()
            ->all();
    }

    /**
     * 已授权域名清单（自定义域名 + 二级域名），用于生成软链接。
     *
     * @return string[]
     */
    public function authorizedDomains(): array
    {
        $wildcardBase = config('domain.wildcard_base');
        $domains = [];

        Tenant::whereNotNull('domain')
            ->where('status', 'active')
            ->pluck('domain')
            ->each(function ($d) use (&$domains) {
                $domains[$d] = true;
            });

        if ($wildcardBase) {
            Tenant::where('status', 'active')
                ->where('slug_status', 'active')
                ->whereNotNull('slug')
                ->where('slug', '!=', '')
                ->pluck('slug')
                ->each(function ($slug) use (&$domains, $wildcardBase) {
                    $domains["{$slug}.{$wildcardBase}"] = true;
                });
        }

        ksort($domains);

        return array_keys($domains);
    }

    /**
     * 为每个已授权域名生成软链接（tenants-enabled/{domain} → 基桩）。
     *
     * 软链接是「域名上下线」的源头真相与巡检入口；nginx 实际读取由其衍生的
     * map 做 O(1) 判断（server_name 为静态指令，无法直接以软链接区分虚拟主机）。
     *
     * @return string[] 已创建的域名
     */
    public function generateSymlinks(string $enabledDir, string $stubFile): array
    {
        File::ensureDirectoryExists($enabledDir);

        // 幂等：清理失效软链接（仅删软链接，不动普通文件）
        foreach (glob(rtrim($enabledDir, '/') . '/*') ?: [] as $existing) {
            if (is_link($existing)) {
                @unlink($existing);
            }
        }

        $target = '../stubs/' . basename($stubFile);
        $created = [];
        foreach ($this->authorizedDomains() as $domain) {
            $link = rtrim($enabledDir, '/') . '/' . $domain;
            if (@symlink($target, $link)) {
                $created[] = $domain;
            }
        }

        return $created;
    }

    /**
     * 渲染基桩 server 块（443 default_server）。
     */
    public function renderTenantServerStub(): string
    {
        $stubFile = dirname((new \ReflectionClass($this))->getFileName(), 2)
            . '/deploy/nginx/tenant-server.conf.stub';

        $template = File::get($stubFile);
        $root = rtrim(config('domain.nginx_public_path') ?? public_path(), '/');

        return strtr($template, [
            '{{ROOT}}' => $root,
            '{{FPM_PORT}}' => (string) config('domain.nginx_fastcgi_port', 9001),
            '{{AI_STREAMING_INCLUDE}}' => 'include snippets/ai-streaming.conf;',
            '{{LOG_DIR}}' => config('domain.nginx_log_dir', '/home/wwwlogs'),
        ]);
    }

    /**
     * 渲染顶层 include 文件（系统 nginx 仅需 include 本文件一次）。
     */
    public function renderTopLevelInclude(string $deployPath): string
    {
        $generatedAt = now()->format('Y-m-d H:i:s');

        return implode("\n", [
            '# ===================================================',
            '# 租户域名接入层 — 顶层 include（自动生成，勿手改）',
            '#',
            '# 系统 nginx 仅需在 http{} 层 include 本文件一次：',
            "#   include {$deployPath}/dsplat-tenants.conf;",
            "# 更新时间: {$generatedAt}",
            '# ===================================================',
            '',
            "include {$deployPath}/maps/*.map;",
            "include {$deployPath}/stubs/tenant-server.conf;",
            '',
        ]);
    }

    /**
     * 一键生成全部 nginx 产物到发布目录。
     *
     * @return array{deploy_path:string,auth_map:string,ssl_map:string,stub:string,top_include:string,domains:string[]}
     */
    public function generateDeployBundle(?string $basePath = null): array
    {
        $deployPath = $this->resolveDeployPath($basePath);

        File::ensureDirectoryExists("{$deployPath}/maps");
        File::ensureDirectoryExists("{$deployPath}/stubs");
        File::ensureDirectoryExists("{$deployPath}/tenants-enabled");

        $authMap = "{$deployPath}/maps/tenant-auth.map";
        $this->generateDomainWhitelistMap($authMap);

        $sslMap = "{$deployPath}/maps/ssl.map";
        $this->generateSslMap($sslMap);

        $stubFile = "{$deployPath}/stubs/tenant-server.conf";
        File::put($stubFile, $this->renderTenantServerStub());

        $links = $this->generateSymlinks("{$deployPath}/tenants-enabled", $stubFile);

        $topFile = "{$deployPath}/dsplat-tenants.conf";
        File::put($topFile, $this->renderTopLevelInclude($deployPath));

        return [
            'deploy_path' => $deployPath,
            'auth_map' => $authMap,
            'ssl_map' => $sslMap,
            'stub' => $stubFile,
            'top_include' => $topFile,
            'domains' => $links,
        ];
    }
}
