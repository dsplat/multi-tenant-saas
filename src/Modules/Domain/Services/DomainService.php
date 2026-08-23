<?php

namespace MultiTenantSaas\Modules\Domain\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

class DomainService
{
    const GROUP_DOMAIN = 'domain';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    /** 保证金联动 TenantSetting 键（domain 组） */
    const SETTING_DEPOSIT_TX_ID = 'deposit_lock_tx_id';

    const SETTING_DEPOSIT_AMOUNT = 'deposit_lock_amount';

    /** 第三方平台（微信/企微/支付宝）域名验证文件存储键（domain 组，JSON 数组，存完整文件名含 .txt） */
    const SETTING_THIRD_PARTY_VERIFY_FILES = 'third_party_verify_files';

    /** 第三方验证文件名安全前缀白名单（防路径穿越/乱录；内容 = 文件名去 .txt） */
    const THIRD_PARTY_VERIFY_PREFIXES = ['WW_verify', 'MP_verify', 'alipay_verify', 'verify_'];

    /** 第三方验证文件名正则（前缀 + 分隔下划线 + 8~64 位字母数字/下划线，不含路径分隔符） */
    const THIRD_PARTY_VERIFY_PATTERN = '/^(WW_verify|MP_verify|alipay_verify|verify_)[A-Za-z0-9_]{8,64}$/';

    public function getDomainInfo(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);

        $wildcardBase = config('domain.wildcard_base');

        return [
            'domain' => $tenant->domain,
            'domain_status' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_PENDING),
            'icp_verified' => (bool) TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'icp_verified', false),
            'icp_verified_at' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'icp_verified_at', null),
            'domain_verified_at' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'domain_verified_at', null),
            // 前端域名设置页展示用：免费二级域名基底（{slug}.wildcard_base）+ 自定义域名 CNAME 目标
            'wildcard_base' => $wildcardBase,
            'cname_target' => $wildcardBase ? 'app.' . $wildcardBase : null,
        ];
    }

    public function updateDomain(int $tenantId, string $domain): void
    {
        $validator = Validator::make(
            ['domain' => $domain],
            ['domain' => 'required|string|max:255|regex:/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/']
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // 平台保留域名黑名单校验（绝对禁止绑定）
        $this->assertDomainNotReserved($domain);

        $existing = Tenant::where('domain', $domain)
            ->where('tenant_id', '!=', $tenantId)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'domain' => trans('domain.already_used'),
            ]);
        }

        $tenant = Tenant::findOrFail($tenantId);
        $tenant->domain = $domain;
        $tenant->save();

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_PENDING);
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'icp_verified', false);

        // 域名变更后自动生成验证 token
        $this->generateVerificationToken($tenantId);
    }

    /**
     * 审批通过域名（admin）
     *
     * 保证金联动：config('commerce.domain_deposit_fen') > 0 时经
     * SupplySettlementService 锁定保证金；经 TenantSetting（deposit_lock_tx_id）
     * 幂等防重复锁。Commerce 模块未启用时静默跳过。
     */
    public function approveDomain(int $tenantId, ?int $operatorId = null): void
    {
        $tenant = Tenant::findOrFail($tenantId);

        if (empty($tenant->domain)) {
            throw new ServiceUnavailableException(trans('domain.not_configured'));
        }

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_APPROVED);
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_verified_at', now()->toDateTimeString());

        // 保证金生命周期联动（0 = 关闭；幂等：已锁过不重复锁）
        $this->lockDepositOnApprove($tenantId, $operatorId);

        // 自定义域名生效后，自动子域名（t-xxxxxx 免费兜底）退役
        $this->deactivateAutoSlug($tenantId);
    }

    /**
     * 停用域名（admin）：状态置 rejected + 退还保证金（有锁定记录才退）
     *
     * 违规扣除不走本方法，沿用 admin 手工 deductDeposit。
     */
    public function deactivateDomain(int $tenantId, int $operatorId): void
    {
        Tenant::findOrFail($tenantId);

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_REJECTED);

        $this->releaseDepositOnDeactivate($tenantId, $operatorId);
    }

    public function rejectDomain(int $tenantId, string $reason = ''): void
    {
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_REJECTED);

        if ($reason) {
            TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'reject_reason', $reason);
        }
    }

    public function verifyIcp(int $tenantId): bool
    {
        if (! config('domain.icp_check_enabled', false)) {
            return true;
        }

        $tenant = Tenant::findOrFail($tenantId);
        $domain = $tenant->domain;

        if (empty($domain)) {
            return false;
        }

        $verified = $this->checkIcpRecord($domain);

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'icp_verified', $verified);

        if ($verified) {
            TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'icp_verified_at', now()->toDateTimeString());
        }

        return $verified;
    }

    protected function checkIcpRecord(string $domain): bool
    {
        return true;
    }

    public function getDomainStatus(int $tenantId): string
    {
        return TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_PENDING);
    }

    public function isDomainApproved(int $tenantId): bool
    {
        return $this->getDomainStatus($tenantId) === self::STATUS_APPROVED;
    }

    /**
     * 解析租户规范入口 host（不含 scheme）
     *
     * 与 EnforceCanonicalEntry 同规则，单一事实源：
     *   自定义域名（domain 非空 且 domain_status=approved）
     *   > {slug}.{wildcard_base}（slug_status=active，含自动码 t-xxxxxx）
     *   > {tenant_id}.{wildcard_base}（兑底）
     * 全不满足返回 null（无规范入口）。
     */
    public function getCanonicalHost(int $tenantId): ?string
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            return null;
        }

        // 1. 自定义域名 approved 优先
        if (! empty($tenant->domain)
            && TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_PENDING) === self::STATUS_APPROVED) {
            return $tenant->domain;
        }

        $wildcardBase = config('domain.wildcard_base');

        // 2. slug active 二级域名
        if (! empty($tenant->slug) && $tenant->slug_status === 'active' && $wildcardBase) {
            return "{$tenant->slug}.{$wildcardBase}";
        }

        // 3. tenant_id 兑底
        if ($wildcardBase) {
            return "{$tenant->tenant_id}.{$wildcardBase}";
        }

        return null;
    }

    // ========================================
    // 域名归属文件验证（Domain Ownership Verification）
    // ========================================

    /**
     * 生成域名验证 token 并存储
     *
     * 租户需在域名根目录放置文件：
     *   路径: /.well-known/tenant-verify/{token}.txt
     *   内容: token 字符串
     */
    public function generateVerificationToken(int $tenantId): string
    {
        $length = (int) config('domain.verification.token_length', 32);
        $token = Str::random($length);

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'verification_token', $token);
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'verification_token_generated_at', now()->toDateTimeString());
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'verification_attempts', 0);

        return $token;
    }

    /**
     * 执行域名归属文件验证
     *
     * 平台侧主动 HTTP GET 租户域名的验证文件，校验内容匹配。
     * 通过后自动将 domain_status 设为 approved。
     */
    public function verifyDomainOwnership(int $tenantId): bool
    {
        $tenant = Tenant::findOrFail($tenantId);
        $domain = $tenant->domain;

        if (empty($domain)) {
            throw new ServiceUnavailableException(trans('domain.not_configured'));
        }

        $token = TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'verification_token');

        if (empty($token)) {
            $token = $this->generateVerificationToken($tenantId);
        }

        // 检查尝试次数
        $maxAttempts = (int) config('domain.verification.max_attempts', 5);
        $attempts = (int) TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'verification_attempts', 0);

        if ($attempts >= $maxAttempts) {
            Log::warning('DomainService: verification max attempts reached', [
                'tenant_id' => $tenantId,
                'domain' => $domain,
            ]);

            return false;
        }

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'verification_attempts', $attempts + 1);

        $pathPrefix = config('domain.verification.path_prefix', '.well-known/tenant-verify');
        $timeout = (int) config('domain.verification.http_timeout', 10);
        $verifyUrl = "https://{$domain}/{$pathPrefix}/{$token}.txt";

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => false])
                ->get($verifyUrl);

            if ($response->successful() && trim($response->body()) === $token) {
                // 验证通过
                TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_APPROVED);
                TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_verified_at', now()->toDateTimeString());
                TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'verification_method', 'file');

                // 自定义域名生效后，自动子域名（t-xxxxxx 免费兜底）退役
                $this->deactivateAutoSlug($tenantId);

                Log::info('DomainService: domain ownership verified', [
                    'tenant_id' => $tenantId,
                    'domain' => $domain,
                ]);

                return true;
            }

            Log::warning('DomainService: verification file check failed', [
                'tenant_id' => $tenantId,
                'domain' => $domain,
                'url' => $verifyUrl,
                'http_status' => $response->status(),
                'body_preview' => mb_substr($response->body(), 0, 100),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('DomainService: verification request exception', [
                'tenant_id' => $tenantId,
                'domain' => $domain,
                'url' => $verifyUrl,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 获取域名验证指引（返回给前端展示）
     */
    public function getVerificationInstructions(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $domain = $tenant->domain;
        $token = TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'verification_token');
        $pathPrefix = config('domain.verification.path_prefix', '.well-known/tenant-verify');

        if (empty($token)) {
            $token = $this->generateVerificationToken($tenantId);
        }

        return [
            'domain' => $domain,
            'token' => $token,
            'file_path' => "/{$pathPrefix}/{$token}.txt",
            'file_content' => $token,
            'verify_url' => $domain ? "https://{$domain}/{$pathPrefix}/{$token}.txt" : null,
            'status' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_PENDING),
            'verified_at' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'domain_verified_at'),
            'generated_at' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'verification_token_generated_at'),
            'attempts' => (int) TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'verification_attempts', 0),
            'max_attempts' => (int) config('domain.verification.max_attempts', 5),
            // 第三方平台（微信/企微/支付宝）域名验证文件：完整文件名（含 .txt），内容为去掉 .txt 的文件名
            'third_party_verify_files' => $this->getThirdPartyVerifyFiles($tenantId),
        ];
    }

    /**
     * 获取第三方平台域名验证文件列表（完整文件名，含 .txt）
     */
    public function getThirdPartyVerifyFiles(int $tenantId): array
    {
        $raw = TenantSetting::get($tenantId, self::GROUP_DOMAIN, self::SETTING_THIRD_PARTY_VERIFY_FILES, []);

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, fn ($f) => is_string($f) && $f !== ''));
    }

    /**
     * 保存第三方平台域名验证文件列表（覆盖式）
     *
     * 入参允许带或不带 .txt 后缀；统一归一化为完整文件名（含 .txt）。
     * 文件名须匹配安全白名单（前缀 + 8~64 位字母数字），防路径穿越与任意文件写入。
     */
    public function saveThirdPartyVerifyFiles(int $tenantId, array $files): array
    {
        $normalized = [];

        foreach ($files as $file) {
            $name = trim((string) $file);
            if ($name === '') {
                continue;
            }

            // 容错：输入带 .txt 时剥掉
            if (str_ends_with($name, '.txt')) {
                $name = substr($name, 0, -4);
            }

            if (! preg_match(self::THIRD_PARTY_VERIFY_PATTERN, $name)) {
                throw ValidationException::withMessages([
                    'files' => trans('domain.verify_file_invalid', ['name' => $name]),
                ]);
            }

            $normalized[] = $name . '.txt';
        }

        $normalized = array_values(array_unique($normalized));

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, self::SETTING_THIRD_PARTY_VERIFY_FILES, $normalized);

        return $normalized;
    }

    /**
     * 自定义域名生效后，退役自动子域名（t-xxxxxx 免费兜底）。
     *
     * 仅当当前 slug 为系统自动码（AUTO_PREFIX）时才失活：用户付费设置的
     * 自定义 slug 属二级域名付费层，不随自定义域名自动退役。
     * 失活后 slug_status=rejected，NginxConfigService 重生成时自动从白名单移除。
     */
    protected function deactivateAutoSlug(int $tenantId): void
    {
        $tenant = Tenant::find($tenantId);

        if (! $tenant || empty($tenant->slug)) {
            return;
        }

        $slugService = new SlugService;

        if ($slugService->isReservedAutoPrefix($tenant->slug)) {
            $slugService->rejectSlug($tenantId, '自定义域名生效，自动子域名退役');
        }
    }

    /**
     * 审批通过时锁定域名保证金（幂等 + Commerce 模块可选）
     */
    protected function lockDepositOnApprove(int $tenantId, ?int $operatorId): void
    {
        $amountFen = (int) config('commerce.domain_deposit_fen', 0);

        if ($amountFen <= 0) {
            return; // 联动关闭
        }
        if (TenantSetting::get($tenantId, self::GROUP_DOMAIN, self::SETTING_DEPOSIT_TX_ID)) {
            return; // 幂等：已有锁定记录
        }
        if (! class_exists(\MultiTenantSaas\Modules\Commerce\Services\SupplySettlementService::class)) {
            Log::warning('DomainService: commerce module unavailable, skip deposit lock', ['tenant_id' => $tenantId]);

            return;
        }

        $tx = app(\MultiTenantSaas\Modules\Commerce\Services\SupplySettlementService::class)->lockDeposit(
            $tenantId,
            $amountFen,
            $operatorId ?? 0,
            '域名审批通过自动锁定保证金'
        );

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, self::SETTING_DEPOSIT_TX_ID, $tx->getKey());
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, self::SETTING_DEPOSIT_AMOUNT, $amountFen);
    }

    /**
     * 停用域名时退还保证金（仅存在锁定记录时）
     */
    protected function releaseDepositOnDeactivate(int $tenantId, int $operatorId): void
    {
        $txId = TenantSetting::get($tenantId, self::GROUP_DOMAIN, self::SETTING_DEPOSIT_TX_ID);

        if (! $txId) {
            return; // 未锁过保证金（联动关闭或历史域名）
        }
        if (! class_exists(\MultiTenantSaas\Modules\Commerce\Services\SupplySettlementService::class)) {
            Log::warning('DomainService: commerce module unavailable, skip deposit release', ['tenant_id' => $tenantId]);

            return;
        }

        $amountFen = (int) TenantSetting::get($tenantId, self::GROUP_DOMAIN, self::SETTING_DEPOSIT_AMOUNT, 0);
        if ($amountFen <= 0) {
            $amountFen = (int) config('commerce.domain_deposit_fen', 0);
        }

        app(\MultiTenantSaas\Modules\Commerce\Services\SupplySettlementService::class)->releaseDeposit(
            $tenantId,
            $amountFen,
            $operatorId,
            '域名停用自动退还保证金'
        );

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, self::SETTING_DEPOSIT_TX_ID, null);
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, self::SETTING_DEPOSIT_AMOUNT, null);
    }

    /**
     * 校验域名是否在平台保留黑名单中
     *
     * 黑名单来源（取并集）：
     * 1. config('domain.reserved_domains') — 从 .env 读取的平台域名
     * 2. config('tenancy.platform_domains') — 平台域名数组
     * 3. system_settings 动态配置（Admin 后台管理）
     * 4. 通配符基础域名（wildcard_base）及其子域名
     *
     * @throws ValidationException
     */
    protected function assertDomainNotReserved(string $domain): void
    {
        $domain = strtolower(trim($domain));

        // 合并所有保留域名源（静态配置 + 动态配置）
        $dynamicReserved = SystemSetting::get('domain', 'reserved_domains', []);
        $reserved = array_map('strtolower', array_filter(array_merge(
            (array) config('domain.reserved_domains', []),
            (array) config('tenancy.platform_domains', []),
            is_array($dynamicReserved) ? $dynamicReserved : [],
        )));

        // 精确匹配
        if (in_array($domain, $reserved, true)) {
            throw ValidationException::withMessages([
                'domain' => trans('domain.reserved', ['domain' => $domain]),
            ]);
        }

        // 通配符基础域名检查（如 *.scrm.com 不允许绑定 scrm.com 或其子域）
        $wildcardBase = config('domain.wildcard_base');
        if ($wildcardBase) {
            $wildcardBase = strtolower($wildcardBase);
            if ($domain === $wildcardBase || str_ends_with($domain, ".{$wildcardBase}")) {
                throw ValidationException::withMessages([
                    'domain' => trans('domain.reserved_wildcard', ['domain' => $domain]),
                ]);
            }
        }
    }
}
