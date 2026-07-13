<?php

namespace MultiTenantSaas\Modules\Domain\Services;

use Illuminate\Support\Facades\Validator;
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
}
