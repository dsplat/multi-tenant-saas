<?php

namespace MultiTenantSaas\Services;

use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\CreditAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TenantService
{
    public function __construct(
        private IdGenerator $idGenerator
    ) {}

    /**
     * 获取租户列表（带分页和筛选）
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Tenant::query();

        // 搜索（name 或 slug）
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // 按状态筛选
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 按套餐筛选
        if (! empty($filters['plan'])) {
            $query->byPlan($filters['plan']);
        }

        // 只查询激活的
        if (! empty($filters['active_only'])) {
            $query->active();
        }

        // 排序
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // 分页
        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    /**
     * 创建租户
     */
    public function create(array $data): Tenant
    {
        DB::beginTransaction();
        try {
            // 创建租户
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'status' => $data['status'] ?? 'active',
                'subscription_plan' => $data['plan'] ?? 'free',
                'custom_domain' => $data['custom_domain'] ?? null,
                'description' => $data['description'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'total_credits' => $data['total_credits'] ?? 0,
                'used_credits' => 0,
                'settings' => $data['settings'] ?? [],
                'branding' => $data['branding'] ?? [],
            ]);

            // 创建默认积分账户
            CreditAccount::create([
                'tenant_id' => $tenant->tenant_id,
                'user_id' => null, // 租户级别账户
                'balance' => $data['total_credits'] ?? 0,
                'total_earned' => $data['total_credits'] ?? 0,
                'total_spent' => 0,
                'status' => 'active',
            ]);

            DB::commit();
            return $tenant->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 更新租户
     */
    public function update(int $tenantId, array $data): Tenant
    {
        DB::beginTransaction();
        try {
            $tenant = Tenant::findOrFail($tenantId);

            $tenant->update([
                'name' => $data['name'] ?? $tenant->name,
                'slug' => $data['slug'] ?? $tenant->slug,
                'status' => $data['status'] ?? $tenant->status,
                'subscription_plan' => $data['plan'] ?? $tenant->subscription_plan,
                'custom_domain' => $data['custom_domain'] ?? $tenant->custom_domain,
                'description' => $data['description'] ?? $tenant->description,
                'contact_name' => $data['contact_name'] ?? $tenant->contact_name,
                'contact_email' => $data['contact_email'] ?? $tenant->contact_email,
                'contact_phone' => $data['contact_phone'] ?? $tenant->contact_phone,
                'total_credits' => $data['total_credits'] ?? $tenant->total_credits,
                'settings' => $data['settings'] ?? $tenant->settings,
                'branding' => $data['branding'] ?? $tenant->branding,
            ]);

            DB::commit();
            return $tenant->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 删除租户（软删除）
     */
    public function delete(int $tenantId): bool
    {
        DB::beginTransaction();
        try {
            $tenant = Tenant::findOrFail($tenantId);
            $result = $tenant->delete();

            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 查找租户
     */
    public function find(int $tenantId): Tenant
    {
        return Tenant::findOrFail($tenantId);
    }

    /**
     * 获取租户成员列表
     */
    public function getMembers(int $tenantId): Collection
    {
        $tenant = Tenant::findOrFail($tenantId);

        return $tenant->users()
            ->withPivot('role', 'credits', 'is_active', 'joined_at')
            ->orderBy('tenant_users.joined_at', 'desc')
            ->get();
    }

    /**
     * 获取租户财务信息
     */
    public function getFinancials(int $tenantId): array
    {
        $tenant = Tenant::findOrFail($tenantId);

        // 获取积分统计
        $creditAccount = $tenant->creditAccounts()
            ->whereNull('user_id')
            ->first();

        // 一次查询取出所需财务记录，PHP 侧聚合，避免多次 sum 查询。
        // 直接走 financial_records 表（Tenant 模型未声明 financialRecords 关联）。
        $financialRecords = DB::table('financial_records')
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['recharge', 'commission', 'refund'])
            ->get();
        // 收入: 充值、佣金
        $totalRevenue = $financialRecords->whereIn('type', ['recharge', 'commission'])->sum('amount');
        // 支出: 退款
        $totalExpense = $financialRecords->where('type', 'refund')->sum('amount');

        // 预加载 transactions 关联，避免 flatMap 回查造成 N+1
        $recentTransactions = $tenant->creditAccounts()
            ->with('transactions')
            ->get()
            ->flatMap(fn ($account) => $account->transactions ?? collect())
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        return [
            'tenant' => $tenant,
            'credit_account' => $creditAccount,
            'total_credits' => $tenant->total_credits,
            'used_credits' => $tenant->used_credits,
            'available_credits' => $tenant->available_credits,
            'total_revenue' => $totalRevenue,
            'total_expense' => $totalExpense,
            'net_balance' => $totalRevenue - $totalExpense,
            'recent_transactions' => $recentTransactions,
        ];
    }
}
