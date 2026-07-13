<?php

namespace MultiTenantSaas\Modules\Domain\Services;

use Illuminate\Support\Facades\File;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

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

    public function generateDomainWhitelistMap(?string $outputPath = null): void
    {
        $outputPath = $outputPath ?? $this->domainMapFile;

        $tenants = Tenant::query()
            ->whereNotNull('custom_domain')
            ->where('status', 'active')
            ->orderBy('custom_domain')
            ->get(['tenant_id', 'name', 'custom_domain']);

        $domainLines = [];
        foreach ($tenants as $tenant) {
            if ($tenant->custom_domain) {
                $domainLines[] = sprintf(
                    '    %-30s 1;  # %s (tenant_id: %s)',
                    $tenant->custom_domain,
                    $tenant->name,
                    $tenant->tenant_id
                );
            }
        }

        $generatedAt = now()->format('Y-m-d H:i:s');
        $domainsBlock = count($domainLines) > 0
            ? implode("\n", $domainLines)
            : '    # (暂无企业自定义域名)';

        $mapContent = implode("\n", [
            '# ===================================================',
            '# 允许的域名白名单',
            '#',
            '# 此文件由脚本自动生成，请勿手动编辑',
            '#',
            "# 更新时间: {$generatedAt}",
            '# ===================================================',
            '',
            'map $host $domain_allowed {',
            '    default 0;  # 默认拒绝所有未明确允许的域名',
            '',
            '    # ===== 平台域名（始终允许） =====',
            sprintf('    %-30s 1;', config('domain.platform_domains.admin', 'admin.example.com')),
            sprintf('    %-30s 1;', config('domain.platform_domains.app', 'app.example.com')),
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

    public function generateSslMap(): void
    {
        $entries = Tenant::query()
            ->whereNotNull('custom_domain')
            ->whereNotNull('ssl_uploaded_at')
            ->get(['custom_domain'])
            ->filter(fn ($t) => file_exists("{$this->certsPath}/{$t->custom_domain}.crt"))
            ->map(fn ($t) => $t->custom_domain)
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

        $mapDir = dirname($this->sslMapFile);
        if (! is_dir($mapDir)) {
            mkdir($mapDir, 0755, true);
        }

        file_put_contents($this->sslMapFile, $mapContent);
    }
}
