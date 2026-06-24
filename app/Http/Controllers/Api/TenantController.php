<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantSetting;
use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Services\IdGenerator;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }

        $tenants = Tenant::paginate(15);

        return response()->json([
            'success' => true,
            'data' => TenantResource::collection($tenants),
            'meta' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
                'total' => $tenants->total(),
            ],
        ]);
    }

    public function show(Request $request, int $tenantId)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }

        $tenant = Tenant::findOrFail($tenantId);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant)]);
    }

    /**
     * 创建租户并执行开通流程
     */
    public function store(Request $request)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:tenants,slug',
            'domain' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:20',
            'subscription_plan' => 'nullable|in:free,basic,pro,enterprise',
            'welcome_credits' => 'nullable|integer|min:0',
        ]);

        $tenant = Tenant::create([
            'tenant_id' => app(IdGenerator::class)->generate(),
            'name' => $request->name,
            'slug' => $request->slug,
            'domain' => $request->domain,
            'description' => $request->description,
            'contact_name' => $request->contact_name,
            'contact_email' => $request->contact_email,
            'contact_phone' => $request->contact_phone,
            'subscription_plan' => $request->subscription_plan ?? 'free',
            'subscription_started_at' => now(),
            'status' => 'active',
        ]);

        // 开通流程：初始化默认配置
        $this->provisionTenant($tenant, $request->welcome_credits ?? 0);

        AuditService::log('create', 'tenant', $tenant->tenant_id, null, [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->subscription_plan,
        ]);

        return response()->json([
            'success' => true,
            'message' => '租户已创建并初始化',
            'data' => new TenantResource($tenant),
        ], 201);
    }

    public function update(Request $request, int $tenantId)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }

        $tenant = Tenant::findOrFail($tenantId);
        $oldValues = $tenant->only(['name', 'status', 'subscription_plan', 'custom_domain', 'description', 'contact_name', 'contact_email', 'contact_phone']);
        $tenant->update($request->only([
            'name', 'status', 'subscription_plan', 'custom_domain',
            'description', 'contact_name', 'contact_email', 'contact_phone',
        ]));

        AuditService::log('update', 'tenant', $tenantId, $oldValues, $request->only([
            'name', 'status', 'subscription_plan', 'custom_domain',
            'description', 'contact_name', 'contact_email', 'contact_phone',
        ]));

        return response()->json(['success' => true, 'data' => new TenantResource($tenant)]);
    }

    public function destroy(Request $request, int $tenantId)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }

        $tenant = Tenant::findOrFail($tenantId);

        AuditService::log('delete', 'tenant', $tenantId, ['name' => $tenant->name], null);

        $tenant->delete();

        return response()->json(['success' => true, 'message' => '已删除']);
    }

    /**
     * 暂停租户
     */
    public function suspend(Request $request, int $tenantId)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->status === 'suspended') {
            return response()->json(['success' => false, 'message' => '租户已处于暂停状态'], 400);
        }

        $oldStatus = $tenant->status;
        $tenant->status = 'suspended';
        $tenant->save();

        // 禁用该租户所有成员的 token
        \DB::table('personal_access_tokens')
            ->whereIn('tokenable_id', function ($query) use ($tenantId) {
                $query->select('user_id')
                    ->from('tenant_users')
                    ->where('tenant_id', $tenantId);
            })
            ->delete();

        AuditService::log('suspend', 'tenant', $tenantId, ['status' => $oldStatus], [
            'status' => 'suspended',
            'reason' => $request->reason,
        ]);

        return response()->json(['success' => true, 'message' => '租户已暂停']);
    }

    /**
     * 恢复租户
     */
    public function activate(Request $request, int $tenantId)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }

        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->status === 'active') {
            return response()->json(['success' => false, 'message' => '租户已处于活跃状态'], 400);
        }

        $oldStatus = $tenant->status;
        $tenant->status = 'active';
        $tenant->save();

        AuditService::log('activate', 'tenant', $tenantId, ['status' => $oldStatus], [
            'status' => 'active',
        ]);

        return response()->json(['success' => true, 'message' => '租户已恢复']);
    }

    /**
     * 租户开通流程：初始化默认配置
     */
    private function provisionTenant(Tenant $tenant, int $welcomeCredits = 0): void
    {
        // 默认信息配置
        TenantSetting::set($tenant->tenant_id, 'info', 'name', $tenant->name);

        // 默认认证配置
        TenantSetting::set($tenant->tenant_id, 'auth', 'allow_phone_login', false);
        TenantSetting::set($tenant->tenant_id, 'auth', 'allow_password_login', true);
        TenantSetting::set($tenant->tenant_id, 'auth', 'email_domains', '');

        // 默认注册配置
        TenantSetting::set($tenant->tenant_id, 'registration', 'allow_register', true);
        TenantSetting::set($tenant->tenant_id, 'registration', 'welcome_credits', $welcomeCredits);

        // 初始化积分账户（如果有欢迎积分）
        if ($welcomeCredits > 0) {
            \MultiTenantSaas\Models\CreditAccount::create([
                'tenant_id' => $tenant->tenant_id,
                'balance' => $welcomeCredits,
                'total_recharged' => $welcomeCredits,
                'total_consumed' => 0,
            ]);

            \MultiTenantSaas\Models\CreditTransaction::create([
                'tenant_id' => $tenant->tenant_id,
                'amount' => $welcomeCredits,
                'type' => 'recharge',
                'description' => '开通赠送积分',
                'created_at' => now(),
            ]);
        }
    }
}
