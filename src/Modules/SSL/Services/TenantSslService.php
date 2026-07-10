<?php

namespace MultiTenantSaas\Modules\SSL\Services;

use Carbon\Carbon;
use MultiTenantSaas\Models\Tenant;
use RuntimeException;

/**
 * TenantSslService
 *
 * 管理企业自定义域名的 SSL 证书：
 *  - 写入证书/私钥文件到安全目录（非 webroot，软链接挂载）
 *  - 重新生成 nginx SSL map 文件
 *  - nginx reload 由系统服务监听目录变更后自动触发（无需 PHP 主动调用）
 */
class TenantSslService
{
    private string $certsPath;

    private string $nginxMapFile;

    public function __construct()
    {
        $this->certsPath = config('ssl.certs_path');
        $this->nginxMapFile = config('ssl.nginx_map_file');
    }

    /**
     * 为租户上传/更新 SSL 证书
     *
     * @throws RuntimeException 磁盘写入失败时抛出
     */
    public function storeCertificate(Tenant $tenant, string $certificate, string $privateKey): void
    {
        $domain = $tenant->custom_domain;

        if (! $domain) {
            throw new RuntimeException(trans('ssl.no_domain'));
        }

        // 解析证书过期时间
        $certInfo = openssl_x509_parse($certificate);
        $expiresAt = isset($certInfo['validTo_time_t'])
            ? Carbon::createFromTimestamp($certInfo['validTo_time_t'])
            : null;

        // 确保目录存在
        $dir = $this->certsPath;
        if (! is_dir($dir) && ! mkdir($dir, 0750, true)) {
            throw new RuntimeException("无法创建证书目录: {$dir}");
        }

        // 规范化 PEM 内容（确保有换行结尾）
        $certContent = rtrim($certificate) . "\n";
        $keyContent = rtrim($privateKey) . "\n";

        $certFile = "{$dir}/{$domain}.crt";
        $keyFile = "{$dir}/{$domain}.key";

        // 写入证书（可被 nginx 读取，不对外公开）
        if (file_put_contents($certFile, $certContent) === false) {
            throw new RuntimeException("证书文件写入失败: {$certFile}");
        }
        chmod($certFile, 0644);

        // 写入私钥（仅所有者可读，600）
        if (file_put_contents($keyFile, $keyContent) === false) {
            throw new RuntimeException("私钥文件写入失败: {$keyFile}");
        }
        chmod($keyFile, 0600);

        // 更新租户元数据
        $tenant->ssl_uploaded_at = now();
        $tenant->ssl_cert_expires_at = $expiresAt;
        $tenant->save();

        // 重新生成 nginx map（系统 inotify 监听到变更后自动 reload nginx）
        $this->regenerateNginxMap();
    }

    /**
     * 删除租户的 SSL 证书
     */
    public function removeCertificate(Tenant $tenant): void
    {
        $domain = $tenant->custom_domain;

        if ($domain) {
            @unlink("{$this->certsPath}/{$domain}.crt");
            @unlink("{$this->certsPath}/{$domain}.key");
        }

        $tenant->ssl_uploaded_at = null;
        $tenant->ssl_cert_expires_at = null;
        $tenant->save();

        $this->regenerateNginxMap();
    }

    /**
     * 获取租户 SSL 证书状态信息
     */
    public function getCertInfo(Tenant $tenant): array
    {
        $domain = $tenant->custom_domain;

        $hasCert = $domain
            && file_exists("{$this->certsPath}/{$domain}.crt")
            && file_exists("{$this->certsPath}/{$domain}.key");

        return [
            'has_certificate' => $hasCert,
            'uploaded_at' => $tenant->ssl_uploaded_at?->toISOString(),
            'expires_at' => $tenant->ssl_cert_expires_at?->toISOString(),
            'is_expired' => $tenant->ssl_cert_expires_at
                ? $tenant->ssl_cert_expires_at->isPast()
                : false,
            'expires_soon' => $tenant->ssl_cert_expires_at
                ? $tenant->ssl_cert_expires_at->diffInDays(now()) <= 30 && ! $tenant->ssl_cert_expires_at->isPast()
                : false,
        ];
    }

    /**
     * 重新生成 nginx SSL map 文件
     *
     * 遍历所有有证书的租户，生成 nginx map 配置：
     *   map $ssl_server_name $ssl_cert_file { ... }
     *   map $ssl_server_name $ssl_key_file  { ... }
     */
    public function regenerateNginxMap(): void
    {
        // 找出所有有 custom_domain + 证书存在的租户
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
            '# 自动生成 — 勿手动编辑（由 TenantSslService 生成）',
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

        $mapDir = dirname($this->nginxMapFile);
        if (! is_dir($mapDir)) {
            mkdir($mapDir, 0755, true);
        }

        file_put_contents($this->nginxMapFile, $mapContent);
    }
}
