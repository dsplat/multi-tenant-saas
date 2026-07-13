<?php

namespace MultiTenantSaas\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Billing\Models\CreditAccount;
use MultiTenantSaas\Modules\Billing\Models\CreditTransaction;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\IdGenerator;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\Notification\Services\NotificationService;
use MultiTenantSaas\Modules\User\Http\Resources\TenantResource;

/**
 * @OA\Tag(
 *     name="租户管理",
 *     description="租户的创建、查询、更新、暂停和恢复"
 * )
 */
class TenantController extends Controller
{
    /**
     * 确保用户有权访问目标租户（平台 operator 可访问任意租户）
     */
    private function ensureTenantAccessOrSuperAdmin(Request $request, int $tenantId): void
    {
        $user = $request->user();

        // 检查是否是平台级 operator
        $isPlatformOperator = \DB::table('operator_tenants')
            ->join('operators', 'operators.operator_id', '=', 'operator_tenants.operator_id')
            ->where('operator_tenants.user_id', $user->user_id)
            ->where('operators.scope', 'platform')
            ->where('operator_tenants.is_active', true)
            ->exists();

        if ($isPlatformOperator) {
            return;
        }

        $isMember = \DB::table('tenant_users')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            abort(403, trans('common.not_in_tenant'));
        }
    }

    /**
     * @OA\Get(
     *     path="/v1/tenants",
     *     summary="获取租户列表",
     *     tags={"租户管理"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="page", in="query", description="页码", @OA\Schema(type="integer", default=1)),
     *
     *     @OA\Response(response=200, description="租户列表", @OA\JsonContent()),
     *     @OA\Response(response=403, description="无权限")
     * )
     */
    public function index(Request $request)
    {
        if (! RbacService::check('tenant.view')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
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
        if (! RbacService::check('tenant.view')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
        }

        $this->ensureTenantAccessOrSuperAdmin($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant)]);
    }

    /**
     * 创建租户并执行开通流程
     */
    /**
     * @OA\Post(
     *     path="/v1/tenants",
     *     summary="创建租户并初始化",
     *     tags={"租户管理"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="application/json",
     *
     *             @OA\Schema(
     *
     *                 @OA\Property(property="name", type="string", description="租户名称"),
     *                 @OA\Property(property="slug", type="string", description="租户标识"),
     *                 @OA\Property(property="domain", type="string", description="自定义域名"),
     *                 @OA\Property(property="subscription_plan", type="string", enum={"free","basic","pro","enterprise"}, description="订阅计划"),
     *                 @OA\Property(property="welcome_credits", type="integer", description="欢迎积分")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="创建成功"),
     *     @OA\Response(response=403, description="无权限"),
     *     @OA\Response(response=422, description="参数错误")
     * )
     */
    public function store(Request $request)
    {
        if (! RbacService::check('tenant.create')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
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

        // 为新租户开通默认模块
        app(ModuleManager::class)->provisionTenantModules(
            $tenant->tenant_id,
            $request->subscription_plan ?? 'free'
        );

        Event::dispatch(new TenantCreated($tenant));
        AuditService::log('create', 'tenant', $tenant->tenant_id, null, [
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->subscription_plan,
        ]);

        return response()->json([
            'success' => true,
            'message' => trans('tenant.created'),
            'data' => new TenantResource($tenant),
        ], 201);
    }

    public function update(Request $request, int $tenantId)
    {
        if (! RbacService::check('tenant.update')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
        }

        $this->ensureTenantAccessOrSuperAdmin($request, $tenantId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,suspended,inactive',
            'subscription_plan' => 'sometimes|in:free,basic,pro,enterprise',
            'custom_domain' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:20',
        ]);

        $tenant = Tenant::findOrFail($tenantId);
        $oldValues = $tenant->only(array_keys($validated));
        $tenant->update($validated);

        AuditService::log('update', 'tenant', $tenantId, $oldValues, $validated);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant)]);
    }

    public function destroy(Request $request, int $tenantId)
    {
        if (! RbacService::check('tenant.delete')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
        }

        $tenant = Tenant::findOrFail($tenantId);

        AuditService::log('delete', 'tenant', $tenantId, ['name' => $tenant->name], null);

        $tenant->delete();

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    /**
     * 暂停租户
     */
    public function suspend(Request $request, int $tenantId)
    {
        if (! RbacService::check('tenant.suspend')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->status === 'suspended') {
            return response()->json(['success' => false, 'message' => trans('tenant.already_suspended')], 400);
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

        // 通知租户所有成员
        NotificationService::notifyTenantSuspended($tenant, $request->reason);

        return response()->json(['success' => true, 'message' => trans('tenant.suspended')]);
    }

    /**
     * 恢复租户
     */
    public function activate(Request $request, int $tenantId)
    {
        if (! RbacService::check('tenant.activate')) {
            return response()->json(['success' => false, 'message' => trans('common.no_permission')], 403);
        }

        $tenant = Tenant::findOrFail($tenantId);

        if ($tenant->status === 'active') {
            return response()->json(['success' => false, 'message' => trans('tenant.already_active')], 400);
        }

        $oldStatus = $tenant->status;
        $tenant->status = 'active';
        $tenant->save();

        AuditService::log('activate', 'tenant', $tenantId, ['status' => $oldStatus], [
            'status' => 'active',
        ]);

        return response()->json(['success' => true, 'message' => trans('tenant.resumed')]);
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
            CreditAccount::create([
                'tenant_id' => $tenant->tenant_id,
                'balance' => $welcomeCredits,
                'total_recharged' => $welcomeCredits,
                'total_consumed' => 0,
            ]);

            CreditTransaction::create([
                'tenant_id' => $tenant->tenant_id,
                'amount' => $welcomeCredits,
                'type' => 'recharge',
                'description' => '开通赠送积分',
                'created_at' => now(),
            ]);
        }
    }
}
