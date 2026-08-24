<?php

namespace MultiTenantSaas\Modules\SSL\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Exceptions\StorageException;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * TenantSslService
 *
 * 管理企业自定义域名的 SSL 证书：
 *  - 写入证书/私钥文件到安全目录（非 webroot，软链接挂载）
 *  - 重新生成 nginx SSL map 文件
 *  - nginx reload 由系统服务监听目录变更后自动触发（无需 PHP 主动调用）
 *  - ACME 自动签发：acme.sh HTTP-01 webroot 模式，签发/部署/续期全自动
 */
class TenantSslService
{
    const GROUP_SSL = 'ssl';

    /** 证书来源：手动上传 / ACME 自动签发 */
    const METHOD_UPLOAD = 'upload';

    const METHOD_ACME = 'acme';

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
        $domain = $tenant->domain;

        if (! $domain) {
            throw new DomainException(trans('ssl.no_domain'));
        }

        // 解析证书过期时间
        $certInfo = openssl_x509_parse($certificate);
        $expiresAt = isset($certInfo['validTo_time_t'])
            ? Carbon::createFromTimestamp($certInfo['validTo_time_t'])
            : null;

        // 确保目录存在
        $dir = $this->certsPath;
        if (! is_dir($dir) && ! mkdir($dir, 0750, true)) {
            throw new StorageException("无法创建证书目录: {$dir}");
        }

        // 规范化 PEM 内容（确保有换行结尾）
        $certContent = rtrim($certificate) . "\n";
        $keyContent = rtrim($privateKey) . "\n";

        $certFile = "{$dir}/{$domain}.crt";
        $keyFile = "{$dir}/{$domain}.key";

        // 写入证书（可被 nginx 读取，不对外公开）
        if (file_put_contents($certFile, $certContent) === false) {
            throw new StorageException("证书文件写入失败: {$certFile}");
        }
        chmod($certFile, 0644);

        // 写入私钥（仅所有者可读，600）
        if (file_put_contents($keyFile, $keyContent) === false) {
            throw new StorageException("私钥文件写入失败: {$keyFile}");
        }
        chmod($keyFile, 0600);

        // 更新租户元数据
        $tenant->ssl_uploaded_at = now();
        $tenant->ssl_cert_expires_at = $expiresAt;
        $tenant->save();

        TenantSetting::set((int) $tenant->tenant_id, self::GROUP_SSL, 'method', self::METHOD_UPLOAD);

        // 重新生成 nginx map（系统 inotify 监听到变更后自动 reload nginx）
        $this->regenerateNginxMap();
    }

    /**
     * 删除租户的 SSL 证书
     */
    public function removeCertificate(Tenant $tenant): void
    {
        $domain = $tenant->domain;

        if ($domain) {
            @unlink("{$this->certsPath}/{$domain}.crt");
            @unlink("{$this->certsPath}/{$domain}.key");
        }

        $tenant->ssl_uploaded_at = null;
        $tenant->ssl_cert_expires_at = null;
        $tenant->save();

        TenantSetting::remove((int) $tenant->tenant_id, self::GROUP_SSL, 'method');
        TenantSetting::remove((int) $tenant->tenant_id, self::GROUP_SSL, 'last_issue_error');

        $this->regenerateNginxMap();
    }

    /**
     * 获取租户 SSL 证书状态信息
     */
    public function getCertInfo(Tenant $tenant): array
    {
        $domain = $tenant->domain;

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
            // Carbon 3：now()->diffInDays($future) 为正，反向相减避免带符号陷阱
            'expires_soon' => $tenant->ssl_cert_expires_at
                ? (int) now()->diffInDays($tenant->ssl_cert_expires_at) <= 30 && ! $tenant->ssl_cert_expires_at->isPast()
                : false,
            // 自动签发（ACME）：开关状态/证书来源/最近一次签发错误/环境可用性
            'auto_issue' => $this->isAutoIssue((int) $tenant->tenant_id),
            'method' => TenantSetting::get((int) $tenant->tenant_id, self::GROUP_SSL, 'method'),
            'last_issue_error' => TenantSetting::get((int) $tenant->tenant_id, self::GROUP_SSL, 'last_issue_error'),
            'acme_available' => $this->acmeAvailable(),
        ];
    }

    // ─── ACME 自动签发 ─────────────────────────────────

    /**
     * 自动签发开关（租户域名设置页切换，调度器据此签发）
     */
    public function isAutoIssue(int $tenantId): bool
    {
        return (bool) TenantSetting::get($tenantId, self::GROUP_SSL, 'auto_issue', false);
    }

    public function setAutoIssue(int $tenantId, bool $enabled): void
    {
        TenantSetting::set($tenantId, self::GROUP_SSL, 'auto_issue', $enabled);

        // 关闭开关时清除历史错误，避免误导展示
        if (! $enabled) {
            TenantSetting::remove($tenantId, self::GROUP_SSL, 'last_issue_error');
        }
    }

    /**
     * ACME 环境是否可用（配置启用 + acme.sh 已安装）
     */
    public function acmeAvailable(): bool
    {
        return (bool) config('ssl.acme.enabled', true)
            && is_executable((string) config('ssl.acme.binary'));
    }

    /**
     * 为租户域名签发并部署证书（acme.sh HTTP-01 webroot）
     *
     * 前置：域名已 approved 且 DNS 指向平台（否则 LE 挑战请求无法到达）。
     * 成功后写证书到 certsPath、更新租户元数据、重生成 nginx ssl map。
     * 续期由 acme.sh 自身 cron 接管，无需平台再参与。
     *
     * @return array{success: bool, message: string}
     */
    public function issueCertificate(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->tenant_id;
        $domain = $tenant->domain;

        if (empty($domain)) {
            return ['success' => false, 'message' => trans('ssl.no_domain')];
        }

        if (! $this->acmeAvailable()) {
            return ['success' => false, 'message' => 'ACME 签发环境不可用（acme.sh 未安装或已禁用）'];
        }

        $webroot = (string) config('ssl.acme.webroot') ?: base_path('public');

        // 1. 签发（挑战文件由 acme.sh 写入 webroot/.well-known/acme-challenge/）
        $issue = $this->runAcme([
            '--issue', '-d', $domain,
            '-w', $webroot,
            '--server', (string) config('ssl.acme.server', 'letsencrypt'),
        ]);

        if (! $issue['ok']) {
            $this->recordIssueError($tenantId, $issue['output']);

            return ['success' => false, 'message' => "证书签发失败（{$domain}）：{$issue['output']}"];
        }

        // 2. 部署到证书目录（目录变更由 systemd path unit 触发 nginx reload）
        $dir = $this->certsPath;
        if (! is_dir($dir) && ! mkdir($dir, 0750, true)) {
            return ['success' => false, 'message' => "无法创建证书目录: {$dir}"];
        }

        $install = $this->runAcme([
            '--install-cert', '-d', $domain,
            '--key-file', "{$dir}/{$domain}.key",
            '--fullchain-file', "{$dir}/{$domain}.crt",
        ]);

        if (! $install['ok']) {
            $this->recordIssueError($tenantId, $install['output']);

            return ['success' => false, 'message' => "证书部署失败（{$domain}）：{$install['output']}"];
        }

        @chmod("{$dir}/{$domain}.key", 0600);
        @chmod("{$dir}/{$domain}.crt", 0644);

        // 3. 更新租户元数据 + nginx ssl map
        $expiresAt = $this->parseCertExpiry("{$dir}/{$domain}.crt");
        $tenant->ssl_uploaded_at = now();
        $tenant->ssl_cert_expires_at = $expiresAt;
        $tenant->save();

        TenantSetting::set($tenantId, self::GROUP_SSL, 'method', self::METHOD_ACME);
        TenantSetting::remove($tenantId, self::GROUP_SSL, 'last_issue_error');

        $this->regenerateNginxMap();

        Log::info('TenantSslService: ACME certificate issued', [
            'tenant_id' => $tenantId,
            'domain' => $domain,
            'expires_at' => $expiresAt?->toDateTimeString(),
        ]);

        return ['success' => true, 'message' => '证书签发并部署成功'];
    }

    /**
     * 执行 acme.sh（抽出便于测试 mock）
     *
     * @return array{ok: bool, output: string}
     */
    protected function runAcme(array $args): array
    {
        try {
            $process = new Process(
                array_merge([(string) config('ssl.acme.binary')], $args),
                null,
                // acme.sh 需直连 ACME 服务器：清除继承的出站代理（代理可能拦截/改写请求）
                ['http_proxy' => '', 'https_proxy' => '', 'HTTP_PROXY' => '', 'HTTPS_PROXY' => '', 'all_proxy' => '', 'ALL_PROXY' => ''],
                null,
                (int) config('ssl.acme.timeout', 180)
            );
            $process->run();

            $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

            return [
                'ok' => $process->isSuccessful(),
                // 只保留末尾关键行，避免长日志入库/上屏
                'output' => mb_substr($output, -500),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => $e->getMessage()];
        }
    }

    protected function recordIssueError(int $tenantId, string $output): void
    {
        TenantSetting::set($tenantId, self::GROUP_SSL, 'last_issue_error', mb_substr($output, -300));

        Log::warning('TenantSslService: ACME issue failed', ['tenant_id' => $tenantId, 'output' => mb_substr($output, -300)]);
    }

    protected function parseCertExpiry(string $certFile): ?Carbon
    {
        $content = @file_get_contents($certFile);

        if ($content === false) {
            return null;
        }

        $info = @openssl_x509_parse($content);

        return isset($info['validTo_time_t']) ? Carbon::createFromTimestamp($info['validTo_time_t']) : null;
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
        // 找出所有有 domain + 证书存在的租户
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
