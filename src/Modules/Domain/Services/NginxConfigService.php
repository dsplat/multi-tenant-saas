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
        $platformDomains = $this->platformDomains();
        $platformLines = $platformDomains
            ? implode("\n", array_map(fn ($d) => sprintf('    %-30s 1;', $d), $platformDomains))
            : '    # (未配置平台域名)';

        // 已放行域名集合（平台 + 二级域名）：自定义域名需排除，避免 map 键冲突
        $emitted = array_flip($platformDomains);
        foreach (array_keys($this->subdomainDomains()) as $subdomain) {
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
     * 平台域名（去重）。
     *
     * @return string[]
     */
    protected function platformDomains(): array
    {
        return array_values(array_unique(array_filter([
            config('domain.platform_domains.admin'),
            config('domain.platform_domains.console'),
        ])));
    }

    /**
     * 生成 SEO/GEO 许可 map（map $host $seo_allowed）。
     *
     * default 0：平台无法控制租户内容，默认禁止 SEO（搜索引擎）/GEO（生成式引擎）收录。
     *   - 平台域名（平台自有内容）→ 1
     *   - 租户自定义域名（tenants.domain，租户自控内容）→ 1
     *   - 二级域名（{slug}.<base>，含 t-xxxxxx 自动码）→ 0（default，不列出）
     *
     * 基桩据此对 $seo_allowed=0 的域名下发限制性 robots.txt + X-Robots-Tag noindex，
     * 并按 User-Agent 拦截各厂 AI 爬虫（GEO 防护）。
     */
    public function generateSeoMap(?string $outputPath = null): void
    {
        $outputPath = $outputPath ?? config('domain.seo_map_file', '/etc/nginx/conf.d/seo.map');
        $generatedAt = now()->format('Y-m-d H:i:s');

        // 平台域名（平台自有内容，可收录）
        $platformDomains = $this->platformDomains();
        $platformLines = $platformDomains
            ? implode("\n", array_map(fn ($d) => sprintf('    %-30s 1;', $d), $platformDomains))
            : '    # (未配置平台域名)';

        // 租户自定义域名（租户自控内容，可收录）；排除与平台域名重复者避免 map 键冲突
        $emitted = array_flip($platformDomains);
        $domainLines = [];
        Tenant::query()
            ->whereNotNull('domain')
            ->where('status', 'active')
            ->orderBy('domain')
            ->get(['tenant_id', 'name', 'domain'])
            ->each(function ($tenant) use (&$domainLines, &$emitted) {
                if ($tenant->domain && ! isset($emitted[$tenant->domain])) {
                    $emitted[$tenant->domain] = true;
                    $domainLines[] = sprintf(
                        '    %-30s 1;  # %s (tenant_id: %s)',
                        $tenant->domain,
                        $tenant->name,
                        $tenant->tenant_id
                    );
                }
            });

        $domainsBlock = count($domainLines) > 0
            ? implode("\n", $domainLines)
            : '    # (暂无企业自定义域名)';

        $mapContent = implode("\n", [
            '# ===================================================',
            '# SEO/GEO 许可 map（map $host $seo_allowed）',
            '#',
            '# 此文件由脚本自动生成，请勿手动编辑',
            '# default 0：平台子域名（含 t-xxxxxx）禁止 SEO/GEO 收录（平台无法控制租户内容）',
            '# 仅平台域名与租户自定义域名为 1（可收录）',
            "# 更新时间: {$generatedAt}",
            '# ===================================================',
            '',
            'map $host $seo_allowed {',
            '    default 0;  # 平台子域名（含 t-xxxxxx）默认禁止收录',
            '',
            '    # ===== 平台域名（平台自有内容，可收录） =====',
            $platformLines,
            '',
            '    # ===== 租户自定义域名（租户自控内容，可收录） =====',
            $domainsBlock,
            '}',
            '',
            '# 由 $seo_allowed 派生的 X-Robots-Tag 响应头值：',
            '# 非收录域名（0）下发 noindex,nofollow；收录域名（1）置空（nginx 空值不下发该头）',
            'map $seo_allowed $x_robots_tag {',
            '    default "noindex, nofollow";',
            '    1       "";',
            '}',
            '',
        ]);

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $mapContent);
    }

    /**
     * 生成 AI 爬虫拦截 map（map $http_user_agent $is_ai_bot）。
     *
     * GEO（生成式引擎优化）防护：平台无法控制租户内容，拦截各厂 AI 爬虫
     * 对非收录域名（平台子域名含 t-xxxxxx）的抓取（避免租户内容被训练/检索）。
     * 匹配不区分大小写（~*）。
     *
     * 同时派生 $block_ai_bot（map "$is_ai_bot:$seo_allowed"）：仅当「是 AI 爬虫
     * 且 $seo_allowed=0」时为 1，基桩据此 return 403——收录域名（平台域名/租户
     * 自定义域名，$seo_allowed=1）放行 AI 爬虫（GEO 开放）。
     *
     * 覆盖主流 AI 爬虫：OpenAI(GPTBot/OAI-SearchBot)、Anthropic(ClaudeBot/anthropic-ai)、
     * 字节(Bytespider)、Common Crawl(CCBot)、Perplexity、Google-Extended、Meta、
     * Mistral、Cohere、Amazonbot、Applebot-Extended 等。
     */
    public function generateBotMap(?string $outputPath = null): void
    {
        $outputPath = $outputPath ?? config('domain.bot_map_file', '/etc/nginx/conf.d/bot.map');
        $generatedAt = now()->format('Y-m-d H:i:s');

        $bots = [
            'GPTBot' => 'OpenAI',
            'OAI-SearchBot' => 'OpenAI',
            'ChatGPT-User' => 'OpenAI',
            'ClaudeBot' => 'Anthropic',
            'Claude-Web' => 'Anthropic',
            'anthropic-ai' => 'Anthropic',
            'Bytespider' => 'ByteDance',
            'CCBot' => 'Common Crawl',
            'PerplexityBot' => 'Perplexity',
            'Google-Extended' => 'Google',
            'FacebookBot' => 'Meta',
            'Meta-ExternalAgent' => 'Meta',
            'MistralAI-User' => 'Mistral',
            'cohere-ai' => 'Cohere',
            'Amazonbot' => 'Amazon',
            'Applebot-Extended' => 'Apple',
            'YouBot' => 'You.com',
            'ImagesiftBot' => 'Imagesift',
            'Timpibot' => 'Timpi',
            'Omgilibot' => 'Omgili',
            'webzio-extended' => 'Webz.io',
            'Diffbot' => 'Diffbot',
        ];

        $botLines = implode("\n", array_map(
            fn ($ua, $vendor) => sprintf('    ~*%-28s 1;  # %s', $ua, $vendor),
            array_keys($bots),
            array_values($bots)
        ));

        $mapContent = implode("\n", [
            '# ===================================================',
            '# AI 爬虫拦截 map（map $http_user_agent $is_ai_bot）',
            '#',
            '# 此文件由脚本自动生成，请勿手动编辑',
            '# GEO（生成式引擎优化）防护：识别各厂 AI 爬虫',
            '# 基桩据 $block_ai_bot（$is_ai_bot 且 非收录域名）return 403',
            "# 更新时间: {$generatedAt}",
            '# ===================================================',
            '',
            'map $http_user_agent $is_ai_bot {',
            '    default 0;  # 默认放行（仅拦截明确识别的 AI 爬虫）',
            '',
            $botLines,
            '}',
            '',
            '# 条件拦截派生：仅对「非收录域名」（$seo_allowed=0）拦截 AI 爬虫。',
            '# 收录域名（平台域名/租户自定义域名，$seo_allowed=1）放行 AI 爬虫（GEO 开放）。',
            '# 键为 "$is_ai_bot:$seo_allowed"，"1:0" 即「是 AI 爬虫 且 非收录」→ 拦截。',
            'map "$is_ai_bot:$seo_allowed" $block_ai_bot {',
            '    "1:0"    1;',
            '    default  0;',
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
     * 生成租户二级域名白名单条目（精确放行，不做通配）。
     *
     * 两种同质形态：
     *   {tenant_id}.{base}（全体 active 租户的兜底形态）
     *   {slug}.{base}（仅 slug_status=active，含自动码 t-xxxxxx）
     *
     * 恶意子域名（如 evilrandom.dsplat.com）不在列表 → default 0 → 拒绝。
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
            fn (string $label, string $d) => sprintf('    %-30s 1;  # %s', $d, $label),
            array_values($domains),
            array_keys($domains)
        ));
    }

    /**
     * 已启用租户的二级域名映射：domain => 注释标签。
     *
     * 含两种同质形态：
     *   {tenant_id}.{base} —— 全体 active 租户（与自动码 t-xxxxxx 同质的兜底访问）
     *   {slug}.{base}      —— 仅 slug_status=active（含自动码 t-xxxxxx 与付费 slug）
     *
     * @return array<string, string>
     */
    protected function subdomainDomains(): array
    {
        $wildcardBase = config('domain.wildcard_base');

        if (! $wildcardBase) {
            return [];
        }

        $domains = [];

        Tenant::query()
            ->where('status', 'active')
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->each(function ($tenantId) use (&$domains, $wildcardBase) {
                $domains["{$tenantId}.{$wildcardBase}"] = "tenant_id: {$tenantId}";
            });

        Tenant::query()
            ->where('status', 'active')
            ->where('slug_status', 'active')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('slug')
            ->pluck('slug')
            ->each(function ($slug) use (&$domains, $wildcardBase) {
                $domains["{$slug}.{$wildcardBase}"] = "slug: {$slug}";
            });

        ksort($domains);

        return $domains;
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
            // tenant_id 子域名：全体 active 租户的兜底形态
            Tenant::where('status', 'active')
                ->pluck('tenant_id')
                ->each(function ($tenantId) use (&$domains, $wildcardBase) {
                    $domains["{$tenantId}.{$wildcardBase}"] = true;
                });

            // slug 二级域名（含自动码 t-xxxxxx）：仅 slug_status=active
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
     * 渲染基桩 server 块（default_server）。
     *
     * 监听形态由 domain.nginx_listen_mode 决定：
     *   https（默认）— 443 直连，启用 ssl.map/SNI 动态证书
     *   http         — 80 层（SLB 已卸载 SSL），无证书指令
     */
    public function renderTenantServerStub(): string
    {
        $stubFile = dirname((new \ReflectionClass($this))->getFileName(), 2)
            . '/deploy/nginx/tenant-server.conf.stub';

        $template = File::get($stubFile);
        $root = rtrim(config('domain.nginx_public_path') ?? public_path(), '/');
        $mode = strtolower((string) config('domain.nginx_listen_mode', 'https'));

        if ($mode === 'http') {
            $listen = 'listen 80 default_server;';
            $sslBlock = '# SSL 已由 SLB 层卸载，本层无证书指令';
            $httpsParam = '';
        } else {
            $listen = 'listen 443 ssl http2 default_server;';
            $sslBlock = implode("\n", [
                '# SNI 动态证书',
                'ssl_certificate     $ssl_cert_file;',
                'ssl_certificate_key $ssl_key_file;',
                'ssl_protocols TLSv1.2 TLSv1.3;',
                'ssl_prefer_server_ciphers on;',
                'ssl_ciphers "TLS13-AES-256-GCM-SHA384:TLS13-CHACHA20-POLY1305-SHA256:TLS13-AES-128-GCM-SHA256:EECDH+CHACHA20:EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:!MD5";',
                'ssl_session_cache shared:SSL:10m;',
                'ssl_session_timeout 5m;',
            ]);
            // 443 直连时告知 PHP 处于 HTTPS（SLB 卸载时由 SLB/回源头负责）
            $httpsParam = "fastcgi_param HTTPS on;\n        ";
        }

        return strtr($template, [
            '{{LISTEN}}' => $listen,
            '{{SSL_BLOCK}}' => $sslBlock,
            '{{FASTCGI_HTTPS_PARAM}}' => $httpsParam,
            '{{ROOT}}' => $root,
            '{{FASTCGI_PASS}}' => config('domain.nginx_fastcgi_pass')
                ?? '127.0.0.1:' . config('domain.nginx_fastcgi_port', 9001),
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
     * @return array{deploy_path:string,auth_map:string,ssl_map:string,seo_map:string,bot_map:string,stub:string,top_include:string,domains:string[]}
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

        $seoMap = "{$deployPath}/maps/seo.map";
        $this->generateSeoMap($seoMap);

        $botMap = "{$deployPath}/maps/bot.map";
        $this->generateBotMap($botMap);

        $stubFile = "{$deployPath}/stubs/tenant-server.conf";
        File::put($stubFile, $this->renderTenantServerStub());

        $links = $this->generateSymlinks("{$deployPath}/tenants-enabled", $stubFile);

        $topFile = "{$deployPath}/dsplat-tenants.conf";
        File::put($topFile, $this->renderTopLevelInclude($deployPath));

        return [
            'deploy_path' => $deployPath,
            'auth_map' => $authMap,
            'ssl_map' => $sslMap,
            'seo_map' => $seoMap,
            'bot_map' => $botMap,
            'stub' => $stubFile,
            'top_include' => $topFile,
            'domains' => $links,
        ];
    }
}
