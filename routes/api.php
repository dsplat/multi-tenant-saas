<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\CreditTransaction;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\SSL\Services\TenantSslService;
use MultiTenantSaas\Services\TenantSettingService;
use MultiTenantSaas\Services\TenantCreditService;
use MultiTenantSaas\Services\TenantMemberService;
use MultiTenantSaas\Services\QuotaService;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\SystemSetting;

// ========== 认证 API ==========
Route::prefix('v1/auth')->group(function () {
    
    Route::post('/login', function (Request $request) {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !password_verify($request->password, $user->password)) {
            return response()->json(['success' => false, 'message' => '邮箱或密码错误'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['success' => false, 'message' => '账号已被禁用'], 403);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        // 获取用户关联的租户
        $tenantUser = TenantUser::where('user_id', $user->user_id)
            ->where('is_active', true)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'user_id' => $user->user_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'tenant_id' => $tenantUser?->tenant_id,
                'token' => $token,
            ],
        ]);
    });

    Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $request->user()->user_id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'role' => $request->user()->role,
            ],
        ]);
    });

    Route::middleware('auth:sanctum')->post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => '已登出']);
    });
});

// ========== 需要认证的 API ==========
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    
    // ----- 租户管理 (admin) -----
    Route::get('/tenants', function () {
        $tenants = Tenant::paginate(15);
        return response()->json([
            'success' => true,
            'data' => $tenants->items(),
            'meta' => [
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
                'total' => $tenants->total(),
            ],
        ]);
    });

    Route::get('/tenants/{tenantId}', function (int $tenantId) {
        $tenant = Tenant::findOrFail($tenantId);
        return response()->json(['success' => true, 'data' => $tenant]);
    });

    Route::put('/tenants/{tenantId}', function (Request $request, int $tenantId) {
        $tenant = Tenant::findOrFail($tenantId);
        $tenant->update($request->only(['name', 'status', 'subscription_plan', 'custom_domain', 'description', 'contact_name', 'contact_email', 'contact_phone']));
        return response()->json(['success' => true, 'data' => $tenant]);
    });

    Route::delete('/tenants/{tenantId}', function (int $tenantId) {
        Tenant::findOrFail($tenantId)->delete();
        return response()->json(['success' => true, 'message' => '已删除']);
    });

    // ----- 成员管理 -----
    Route::get('/tenants/{tenantId}/members', function (int $tenantId) {
        $members = TenantUser::where('tenant_id', $tenantId)
            ->join('users', 'users.user_id', '=', 'tenant_users.user_id')
            ->select('users.user_id', 'users.name', 'users.email', 'tenant_users.role', 'tenant_users.is_active', 'tenant_users.joined_at')
            ->get();
        return response()->json(['success' => true, 'data' => $members]);
    });

    Route::post('/tenants/{tenantId}/members', function (Request $request, int $tenantId) {
        $request->validate(['user_id' => 'required', 'role' => 'in:tenant_admin,end_user']);
        
        TenantUser::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $request->user_id],
            ['role' => $request->role ?? 'end_user', 'is_active' => true, 'joined_at' => now()]
        );
        
        return response()->json(['success' => true, 'message' => '成员已添加']);
    });

    Route::put('/tenants/{tenantId}/members/{userId}', function (Request $request, int $tenantId, int $userId) {
        $member = TenantUser::where('tenant_id', $tenantId)->where('user_id', $userId)->firstOrFail();
        $member->update($request->only(['role', 'is_active']));
        return response()->json(['success' => true, 'message' => '已更新']);
    });

    // ----- 积分管理 -----
    Route::get('/tenants/{tenantId}/credits', function (int $tenantId) {
        $account = CreditAccount::where('tenant_id', $tenantId)->whereNull('user_id')->first();
        $transactions = CreditTransaction::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => [
                'balance' => [
                    'total' => $account?->total_earned ?? 0,
                    'used' => $account?->total_spent ?? 0,
                    'available' => $account?->balance ?? 0,
                ],
                'transactions' => $transactions,
            ],
        ]);
    });

    // ----- 域名管理 -----
    Route::get('/tenants/{tenantId}/domain', function (int $tenantId) {
        $service = new DomainService();
        return response()->json(['success' => true, 'data' => $service->getDomainInfo($tenantId)]);
    });

    Route::put('/tenants/{tenantId}/domain', function (Request $request, int $tenantId) {
        $request->validate(['domain' => 'required|string']);
        $service = new DomainService();
        $service->updateDomain($tenantId, $request->domain);
        return response()->json(['success' => true, 'message' => '域名已更新，等待审核']);
    });

    Route::post('/tenants/{tenantId}/domain/approve', function (int $tenantId) {
        $service = new DomainService();
        $service->approveDomain($tenantId);
        return response()->json(['success' => true, 'message' => '域名已审核通过']);
    });

    Route::post('/tenants/{tenantId}/domain/reject', function (Request $request, int $tenantId) {
        $service = new DomainService();
        $service->rejectDomain($tenantId, $request->reason ?? '');
        return response()->json(['success' => true, 'message' => '域名已拒绝']);
    });

    // ----- SSL 证书管理 -----
    Route::get('/tenants/{tenantId}/ssl', function (int $tenantId) {
        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService();
        return response()->json(['success' => true, 'data' => $service->getCertInfo($tenant)]);
    });

    Route::post('/tenants/{tenantId}/ssl', function (Request $request, int $tenantId) {
        $request->validate([
            'certificate' => 'required|string',
            'private_key' => 'required|string',
        ]);
        
        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService();
        $service->storeCertificate($tenant, $request->certificate, $request->private_key);
        return response()->json(['success' => true, 'message' => 'SSL证书已上传']);
    });

    Route::delete('/tenants/{tenantId}/ssl', function (int $tenantId) {
        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService();
        $service->removeCertificate($tenant);
        return response()->json(['success' => true, 'message' => 'SSL证书已删除']);
    });

    // ----- 租户配置 -----
    Route::get('/tenants/{tenantId}/settings/{group?}', function (int $tenantId, string $group = null) {
        $service = app(TenantSettingService::class);
        
        if ($group) {
            $data = match ($group) {
                'info' => $service->getTenantInfo($tenantId),
                'oauth' => $service->getOAuthConfig($tenantId),
                'auth' => $service->getAuthConfig($tenantId),
                'mail' => $service->getMailConfig($tenantId),
                'registration' => $service->getRegistrationConfig($tenantId),
                default => [],
            };
        } else {
            $data = $service->getAllConfig($tenantId);
        }
        
        return response()->json(['success' => true, 'data' => $data]);
    });

    Route::put('/tenants/{tenantId}/settings/{group}', function (Request $request, int $tenantId, string $group) {
        $service = app(TenantSettingService::class);
        
        match ($group) {
            'info' => $service->updateTenantInfo($tenantId, $request->all()),
            'auth' => $service->updateAuthConfig($tenantId, $request->all()),
            'mail' => $service->updateMailConfig($tenantId, $request->all()),
            'registration' => $service->updateRegistrationConfig($tenantId, $request->all()),
            default => abort(400, '未知配置组'),
        };
        
        return response()->json(['success' => true, 'message' => '配置已更新']);
    });

    // ----- 短信配置 -----
    Route::get('/tenants/{tenantId}/settings/sms', function (int $tenantId) {
        $service = app(TenantSettingService::class);
        $data = $service->getSmsConfig($tenantId);
        return response()->json(['success' => true, 'data' => $data]);
    });

    Route::put('/tenants/{tenantId}/settings/sms', function (Request $request, int $tenantId) {
        $service = app(TenantSettingService::class);
        $service->updateSmsConfig($tenantId, $request->all());
        return response()->json(['success' => true, 'message' => '短信配置已更新']);
    });

    // ----- 支付订单 -----
    Route::get('/tenants/{tenantId}/payments', function (Request $request, int $tenantId) {
        $query = \MultiTenantSaas\Models\Payment::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc');

        $perPage = min((int) $request->get('per_page', 15), 100);
        $payments = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    });

    Route::post('/tenants/{tenantId}/payments', function (Request $request, int $tenantId) {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
        ]);

        $payment = \MultiTenantSaas\Models\Payment::create([
            'tenant_id' => $tenantId,
            'amount' => $request->amount,
            'payment_method' => $request->payment_method,
            'status' => 'pending',
        ]);

        return response()->json(['success' => true, 'data' => $payment], 201);
    });

    // ----- 审计日志 -----
    Route::get('/tenants/{tenantId}/audit-logs', function (Request $request, int $tenantId) {
        $query = AuditLog::where('tenant_id', $tenantId)->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->resource_type);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    });

    // ----- API Token 管理 -----
    Route::get('/tenants/{tenantId}/api-tokens', function (int $tenantId) {
        $tokens = \MultiTenantSaas\Models\ApiToken::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $tokens]);
    });

    Route::post('/tenants/{tenantId}/api-tokens', function (Request $request, int $tenantId) {
        $request->validate([
            'name' => 'required|string|max:255',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $plainToken = \Str::random(40);
        $token = \MultiTenantSaas\Models\ApiToken::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'token' => hash('sha256', $plainToken),
            'expires_at' => $request->expires_at,
        ]);

        return response()->json([
            'success' => true,
            'data' => array_merge($token->toArray(), ['plain_token' => $plainToken]),
        ], 201);
    });

    Route::delete('/tenants/{tenantId}/api-tokens/{tokenId}', function (int $tenantId, int $tokenId) {
        \MultiTenantSaas\Models\ApiToken::where('tenant_id', $tenantId)
            ->where('id', $tokenId)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Token已删除']);
    });

    // ----- 配额 -----
    Route::get('/tenants/{tenantId}/quotas', function (int $tenantId) {
        $service = app(QuotaService::class);
        $quotas = $service->getAll($tenantId);
        return response()->json(['success' => true, 'data' => $quotas]);
    });

    // ----- 系统设置 (super_admin) -----
    Route::middleware(function (Request $request, \Closure $next) {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限访问'], 403);
        }
        return $next($request);
    })->group(function () {
        Route::get('/admin/settings', function () {
            $settings = SystemSetting::all()->groupBy('group');
            return response()->json(['success' => true, 'data' => $settings]);
        });

        Route::put('/admin/settings/{group}', function (Request $request, string $group) {
            $allowedGroups = ['system', 'mail', 'credit', 'dify'];
            if (!in_array($group, $allowedGroups)) {
                return response()->json(['success' => false, 'message' => '未知配置组'], 400);
            }

            foreach ($request->all() as $key => $value) {
                SystemSetting::updateOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $value]
                );
            }

            return response()->json(['success' => true, 'message' => '系统设置已更新']);
        });
    });
});
