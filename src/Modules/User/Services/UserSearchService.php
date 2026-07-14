<?php

namespace MultiTenantSaas\Modules\User\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Auth\Models\User;

class UserSearchService
{
    /**
     * Search users within a tenant with filters
     *
     * @param  int  $tenantId  Tenant ID to scope results
     * @param  string  $query  Search term (name or email)
     * @param  array  $filters  Additional filters: role, is_active, email_verified, sort_by, sort_direction, per_page
     */
    public function search(int $tenantId, string $query, array $filters = []): LengthAwarePaginator
    {
        $builder = User::query()
            ->whereHas('tenants', function ($q) use ($tenantId) {
                $q->where('tenants.tenant_id', $tenantId)
                    ->where('tenant_users.is_active', true);
            })
            ->with(['tenants' => function ($q) use ($tenantId) {
                $q->where('tenants.tenant_id', $tenantId)
                    ->select('tenants.tenant_id')
                    ->withPivot('role', 'is_active', 'joined_at');
            }]);

        if ($query !== '') {
            $builder->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            });
        }

        if (! empty($filters['role'])) {
            $builder->whereHas('tenants', function ($q) use ($filters, $tenantId) {
                $q->where('tenants.tenant_id', $tenantId)
                    ->where('tenant_users.role', $filters['role']);
            });
        }

        if (isset($filters['is_active'])) {
            $builder->whereHas('tenants', function ($q) use ($filters, $tenantId) {
                $q->where('tenants.tenant_id', $tenantId)
                    ->where('tenant_users.is_active', $filters['is_active']);
            });
        }

        if (isset($filters['email_verified'])) {
            if ($filters['email_verified']) {
                $builder->whereNotNull('email_verified_at');
            } else {
                $builder->whereNull('email_verified_at');
            }
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $builder->orderBy($sortBy, $sortDirection);

        $perPage = $filters['per_page'] ?? 15;

        return $builder->paginate($perPage);
    }

    /**
     * Get recently active users in a tenant
     *
     * @param  int  $tenantId  Tenant ID
     * @param  int  $limit  Number of users to return
     */
    public function getRecentUsers(int $tenantId, int $limit = 10): Collection
    {
        return User::query()
            ->whereHas('tenants', function ($q) use ($tenantId) {
                $q->where('tenants.tenant_id', $tenantId)
                    ->where('tenant_users.is_active', true);
            })
            ->orderByDesc('last_active_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get user statistics for a tenant
     */
    public function getUserStats(int $tenantId): array
    {
        $total = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->count();

        $active = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $inactive = $total - $active;

        $verified = User::query()
            ->whereHas('tenants', function ($q) use ($tenantId) {
                $q->where('tenants.tenant_id', $tenantId)
                    ->where('tenant_users.is_active', true);
            })
            ->whereNotNull('email_verified_at')
            ->count();

        $newThisMonth = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('joined_at', '>=', now()->startOfMonth())
            ->count();

        $byRole = DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select('role', DB::raw('count(*) as count'))
            ->groupBy('role')
            ->pluck('count', 'role')
            ->toArray();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'email_verified' => $verified,
            'new_this_month' => $newThisMonth,
            'by_role' => $byRole,
        ];
    }
}
