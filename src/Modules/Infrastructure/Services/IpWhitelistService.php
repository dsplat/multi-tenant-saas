<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\IpWhitelist;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;

/**
 * IP 白名单服务
 *
 * 功能：
 *  - 租户级 IP 白名单 CRUD
 *  - 支持单个 IP / CIDR / IP 范围匹配
 *  - 白名单生效范围控制（全站 / 仅 API / 仅管理后台）
 *  - 白名单开启 / 关闭
 *  - IP 审计日志
 */
class IpWhitelistService
{
    /** 默认生效范围 */
    public const SCOPE_ALL = 'all';

    public const SCOPE_API = 'api';

    public const SCOPE_ADMIN = 'admin';

    /**
     * 白名单列表
     */
    public function list(?string $scope = null)
    {
        $query = IpWhitelist::query();

        if ($scope !== null) {
            $query->where('scope', $scope);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 新增白名单条目
     */
    public function create(string $ipValue, string $scope = self::SCOPE_ALL, ?string $description = null, bool $enabled = true): IpWhitelist
    {
        $entry = IpWhitelist::create([
            'ip_value' => $ipValue,
            'description' => $description,
            'scope' => $scope,
            'is_enabled' => $enabled,
        ]);

        $this->audit('ip_whitelist.create', $entry->ip_whitelist_id, null, $entry->toArray());

        return $entry;
    }

    /**
     * 更新白名单条目
     */
    public function update(int $id, array $attributes): ?IpWhitelist
    {
        $entry = $this->find($id);
        if (! $entry) {
            return null;
        }

        $old = $entry->toArray();
        $entry->update($attributes);

        $this->audit('ip_whitelist.update', $entry->ip_whitelist_id, $old, $entry->fresh()->toArray());

        return $entry->fresh();
    }

    /**
     * 删除白名单条目
     */
    public function delete(int $id): bool
    {
        $entry = $this->find($id);
        if (! $entry) {
            return false;
        }

        $snapshot = $entry->toArray();
        $entry->delete();

        $this->audit('ip_whitelist.delete', $id, $snapshot, null);

        return true;
    }

    /**
     * 启用白名单条目
     */
    public function enable(int $id): ?IpWhitelist
    {
        return $this->update($id, ['is_enabled' => true]);
    }

    /**
     * 禁用白名单条目
     */
    public function disable(int $id): ?IpWhitelist
    {
        return $this->update($id, ['is_enabled' => false]);
    }

    /**
     * 校验 IP 是否在租户白名单中
     */
    public function isAllowed(string $ip, string $scope = self::SCOPE_ALL): bool
    {
        $entries = IpWhitelist::where('is_enabled', true)->get();

        foreach ($entries as $entry) {
            if (! $this->scopeMatches($entry->scope, $scope)) {
                continue;
            }

            if ($this->ipMatches($ip, $entry->ip_value)) {
                $this->audit('ip_whitelist.allow', $entry->ip_whitelist_id, null, [
                    'ip' => $ip,
                    'scope' => $scope,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * 是否启用任意白名单（启用即触发拦截策略）
     */
    public function hasActiveWhitelist(?string $scope = null): bool
    {
        $query = IpWhitelist::where('is_enabled', true);

        if ($scope !== null) {
            $query->where(function ($q) use ($scope) {
                $q->where('scope', self::SCOPE_ALL)->orWhere('scope', $scope);
            });
        }

        return $query->exists();
    }

    /**
     * 查找单条记录
     */
    public function find(int $id): ?IpWhitelist
    {
        return IpWhitelist::where('ip_whitelist_id', $id)->first();
    }

    // ----------------------------------------
    // 匹配逻辑
    // ----------------------------------------

    /**
     * 白名单条目范围是否覆盖请求范围
     */
    public function scopeMatches(string $entryScope, string $requestScope): bool
    {
        if ($entryScope === self::SCOPE_ALL) {
            return true;
        }

        return $entryScope === $requestScope;
    }

    /**
     * IP 是否匹配条目（支持单个 IP / CIDR / 范围）
     */
    public function ipMatches(string $ip, string $ipValue): bool
    {
        $ipValue = trim($ipValue);

        // IP 范围：start - end
        if (str_contains($ipValue, '-')) {
            return $this->ipInRange($ip, $ipValue);
        }

        // CIDR
        if (str_contains($ipValue, '/')) {
            return $this->ipInCidr($ip, $ipValue);
        }

        // 单个 IP
        return hash_equals($ipValue, $ip);
    }

    /**
     * 校验 IP 是否在 CIDR 内
     */
    public function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2) {
            return false;
        }

        [$subnet, $mask] = $parts;
        $mask = (int) $mask;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        if ($mask < 0 || $mask > 32) {
            return false;
        }

        if ($mask === 0) {
            return true;
        }

        $maskLong = -1 << (32 - $mask);
        $maskLong = $maskLong & 0xFFFFFFFF;

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * 校验 IP 是否在范围内（"start - end"）
     */
    public function ipInRange(string $ip, string $range): bool
    {
        $parts = array_map('trim', explode('-', $range, 2));
        if (count($parts) !== 2) {
            return false;
        }

        $start = ip2long($parts[0]);
        $end = ip2long($parts[1]);
        $ipLong = ip2long($ip);

        if ($start === false || $end === false || $ipLong === false) {
            return false;
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return $ipLong >= $start && $ipLong <= $end;
    }

    // ----------------------------------------
    // 审计
    // ----------------------------------------

    /**
     * 记录 IP 白名单审计日志
     *
     * @param  array|string|null  $oldValues
     * @param  array|string|null  $newValues
     */
    protected function audit(string $action, ?int $resourceId, $oldValues = null, $newValues = null): void
    {
        try {
            AuditLog::create([
                'tenant_id' => TenantContext::getId(),
                'user_id' => auth()->id(),
                'action' => $action,
                'resource_type' => 'ip_whitelist',
                'resource_id' => $resourceId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('IpWhitelistService audit failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
