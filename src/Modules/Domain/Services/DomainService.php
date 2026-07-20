<?php

namespace MultiTenantSaas\Modules\Domain\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

class DomainService
{
    const GROUP_DOMAIN = 'domain';

    const STATUS_PENDING = 'pending';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    public function getDomainInfo(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);

        return [
            'custom_domain' => $tenant->custom_domain,
            'domain_status' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_PENDING),
            'icp_verified' => (bool) TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'icp_verified', false),
            'icp_verified_at' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'icp_verified_at', null),
            'domain_verified_at' => TenantSetting::get($tenantId, self::GROUP_DOMAIN, 'domain_verified_at', null),
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

        $existing = Tenant::where('custom_domain', $domain)
            ->where('tenant_id', '!=', $tenantId)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'domain' => trans('domain.already_used'),
            ]);
        }

        $tenant = Tenant::findOrFail($tenantId);
        $tenant->custom_domain = $domain;
        $tenant->save();

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_PENDING);
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'icp_verified', false);

        // 域名变更后自动生成验证 token
        $this->generateVerificationToken($tenantId);
    }

    public function approveDomain(int $tenantId): void
    {
        $tenant = Tenant::findOrFail($tenantId);

        if (empty($tenant->custom_domain)) {
            throw new \RuntimeException(trans('domain.not_configured'));
        }

        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_status', self::STATUS_APPROVED);
        TenantSetting::set($tenantId, self::GROUP_DOMAIN, 'domain_verified_at', now()->toDateTimeString());
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
        $domain = $tenant->custom_domain;

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
        $domain = $tenant->custom_domain;

        if (empty($domain)) {
            throw new \RuntimeException(trans('domain.not_configured'));
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
        $domain = $tenant->custom_domain;
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
        ];
    }
}
