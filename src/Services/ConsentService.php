<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Consent;

/**
 * 同意管理服务（GDPR 合规）
 *
 * 功能：
 *  - Cookie 同意记录
 *  - 数据处理同意
 *  - 营销同意
 *  - 条款版本追踪
 *  - 同意撤回
 *
 * 所有同意记录具有法律效力，记录 IP 和时间戳。
 */
class ConsentService
{
    /** 同意类型：Cookie */
    public const TYPE_COOKIE = 'cookie';

    /** 同意类型：数据处理 */
    public const TYPE_DATA_PROCESSING = 'data_processing';

    /** 同意类型：营销 */
    public const TYPE_MARKETING = 'marketing';

    /** 同意类型：条款 */
    public const TYPE_TERMS = 'terms';

    /**
     * 所有合法的同意类型
     */
    protected array $validTypes = [
        self::TYPE_COOKIE,
        self::TYPE_DATA_PROCESSING,
        self::TYPE_MARKETING,
        self::TYPE_TERMS,
    ];

    /**
     * 授予同意（撤销旧记录后创建新记录，保留历史）
     *
     * @param  int  $userId  用户 ID
     * @param  string  $type  同意类型
     * @param  string  $version  条款版本
     * @param  string  $ip  IP 地址
     * @param  string  $userAgent  User-Agent
     * @param  int|null  $tenantId  租户 ID（null 时从上下文获取）
     * @return Consent
     * @throws \InvalidArgumentException
     */
    public function grantConsent(
        int $userId,
        string $type,
        string $version,
        string $ip,
        string $userAgent,
        ?int $tenantId = null,
    ): Consent {
        $this->validateType($type);

        // 撤销该类型的旧同意记录
        $this->revokeConsent($userId, $type, $tenantId);

        return Consent::create([
            'tenant_id' => $tenantId ?? TenantContext::getId(),
            'user_id' => $userId,
            'type' => $type,
            'version' => $version,
            'is_granted' => true,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'granted_at' => now(),
        ]);
    }

    /**
     * 撤回同意
     *
     * @param  int  $userId  用户 ID
     * @param  string  $type  同意类型
     * @param  int|null  $tenantId  租户 ID
     * @return bool 是否成功撤回（无有效记录时返回 false）
     */
    public function revokeConsent(int $userId, string $type, ?int $tenantId = null): bool
    {
        $query = Consent::where('user_id', $userId)
            ->where('type', $type)
            ->where('is_granted', true)
            ->whereNull('revoked_at');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $updated = $query->update([
            'is_granted' => false,
            'revoked_at' => now(),
        ]);

        return $updated > 0;
    }

    /**
     * 检查用户是否有指定类型的有效同意
     *
     * @param  int  $userId  用户 ID
     * @param  string  $type  同意类型
     * @param  int|null  $tenantId  租户 ID（null 表示不限定租户）
     * @return bool
     */
    public function hasConsent(int $userId, string $type, ?int $tenantId = null): bool
    {
        $query = Consent::where('user_id', $userId)
            ->where('type', $type)
            ->where('is_granted', true)
            ->whereNull('revoked_at');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->exists();
    }

    /**
     * 获取同意历史记录
     *
     * @param  int  $userId  用户 ID
     * @param  string|null  $type  同意类型（null 表示所有类型）
     * @return Collection<int, Consent>
     */
    public function getConsentHistory(int $userId, ?string $type = null): Collection
    {
        $query = Consent::where('user_id', $userId)->orderByDesc('created_at');

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    /**
     * 获取用户当前所有同意状态
     *
     * @param  int  $userId  用户 ID
     * @return array<string, array{granted: bool, version: string|null, granted_at: string|null}>
     */
    public function getConsentStatus(int $userId): array
    {
        $consents = Consent::where('user_id', $userId)
            ->where('is_granted', true)
            ->whereNull('revoked_at')
            ->whereIn('type', $this->validTypes)
            ->selectRaw('type, MAX(version) as version, MAX(granted_at) as granted_at')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $status = [];
        foreach ($this->validTypes as $type) {
            $consent = $consents->get($type);
            $status[$type] = [
                'granted' => $consent !== null,
                'version' => $consent?->version,
                'granted_at' => $consent?->granted_at ? (new \Illuminate\Support\Carbon($consent->granted_at))->toIso8601String() : null,
            ];
        }

        return $status;
    }

    /**
     * 记录 Cookie 同意
     *
     * @param  int  $userId  用户 ID
     * @param  string  $ip  IP 地址
     * @param  string  $userAgent  User-Agent
     * @param  int|null  $tenantId  租户 ID
     * @return Consent
     */
    public function recordCookieConsent(int $userId, string $ip, string $userAgent, ?int $tenantId = null): Consent
    {
        return $this->grantConsent($userId, self::TYPE_COOKIE, $this->getCurrentTermsVersion(), $ip, $userAgent, $tenantId);
    }

    /**
     * 记录数据处理同意
     *
     * @param  int  $userId  用户 ID
     * @param  string  $ip  IP 地址
     * @param  string  $userAgent  User-Agent
     * @param  int|null  $tenantId  租户 ID
     * @return Consent
     */
    public function recordDataProcessingConsent(int $userId, string $ip, string $userAgent, ?int $tenantId = null): Consent
    {
        $version = $this->getCurrentTermsVersion();

        return $this->grantConsent($userId, self::TYPE_DATA_PROCESSING, $version, $ip, $userAgent, $tenantId);
    }

    /**
     * 记录营销同意
     *
     * @param  int  $userId  用户 ID
     * @param  string  $ip  IP 地址
     * @param  string  $userAgent  User-Agent
     * @param  int|null  $tenantId  租户 ID
     * @return Consent
     */
    public function recordMarketingConsent(int $userId, string $ip, string $userAgent, ?int $tenantId = null): Consent
    {
        $version = $this->getCurrentTermsVersion();

        return $this->grantConsent($userId, self::TYPE_MARKETING, $version, $ip, $userAgent, $tenantId);
    }

    /**
     * 接受条款
     *
     * @param  int  $userId  用户 ID
     * @param  string|null  $version  条款版本（null 时使用当前版本）
     * @param  string  $ip  IP 地址
     * @param  string  $userAgent  User-Agent
     * @param  int|null  $tenantId  租户 ID
     * @return Consent
     */
    public function acceptTerms(int $userId, ?string $version = null, string $ip = '', string $userAgent = '', ?int $tenantId = null): Consent
    {
        return $this->grantConsent(
            $userId,
            self::TYPE_TERMS,
            $version ?? $this->getCurrentTermsVersion(),
            $ip,
            $userAgent,
            $tenantId
        );
    }

    /**
     * 获取当前条款版本
     *
     * @return string
     */
    public function getCurrentTermsVersion(): string
    {
        return (string) config('tenancy.gdpr.terms_version', '1.0');
    }

    /**
     * 检查用户是否需要重新接受条款
     *
     * @param  int  $userId  用户 ID
     * @return bool 是否需要重新接受
     */
    public function needsTermsAcceptance(int $userId): bool
    {
        $currentVersion = $this->getCurrentTermsVersion();

        $latestTerms = Consent::where('user_id', $userId)
            ->where('type', self::TYPE_TERMS)
            ->where('is_granted', true)
            ->whereNull('revoked_at')
            ->latest()
            ->first();

        if (! $latestTerms) {
            return true;
        }

        return $latestTerms->version !== $currentVersion;
    }

    /**
     * 验证同意类型是否合法
     *
     * @param  string  $type  同意类型
     * @throws \InvalidArgumentException
     */
    protected function validateType(string $type): void
    {
        if (! in_array($type, $this->validTypes, true)) {
            throw new \InvalidArgumentException(
                trans('tenant.consent_invalid_type').': '.$type
            );
        }
    }

    /**
     * 获取所有合法的同意类型
     *
     * @return array<int, string>
     */
    public function getValidTypes(): array
    {
        return $this->validTypes;
    }
}
