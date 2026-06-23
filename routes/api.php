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
                'sms' => [
                    'driver' => config('services.sms.driver', 'log'),
                    'ww_endpoint' => config('services.sms.ww_endpoint', ''),
                    'ww_account' => config('services.sms.ww_account', ''),
                    'ww_sign' => config('services.sms.ww_sign', ''),
                    'mtedu_endpoint' => config('services.sms.mtedu_endpoint', ''),
                ],
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
            'sms' => (function () use ($request) {
                $allowed = ['driver', 'ww_endpoint', 'ww_account', 'ww_password', 'ww_sign', 'ww_product_id', 'mtedu_endpoint'];
                foreach ($request->only($allowed) as $key => $value) {
                    SystemSetting::updateOrCreate(
                        ['group' => 'sms', 'key' => $key],
                        ['value' => $value]
                    );
                }
            })(),
            default => abort(400, '未知配置组'),
        };
        
        return response()->json(['success' => true, 'message' => '配置已更新']);
    });

    // ----- 短信测试发送 -----
    Route::post('/tenants/{tenantId}/settings/sms/test', function (Request $request, int $tenantId) {
        $request->validate(['phone' => 'required|string|regex:/^1[3-9]\d{9}$/']);
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $result = \MultiTenantSaas\Services\SmsService::send($request->phone, $code, 'test');
        if ($result) {
            return response()->json(['success' => true, 'message' => '测试短信已发送']);
        }
        return response()->json(['success' => false, 'message' => '短信发送失败'], 500);
    });

    // ----- 支付订单 -----
    Route::get('/tenants/{tenantId}/payment-orders', function (Request $request, int $tenantId) {
        $query = \MultiTenantSaas\Models\FinancialRecord::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $records = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    });

    Route::post('/tenants/{tenantId}/payment-orders', function (Request $request, int $tenantId) {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string|max:255',
        ]);

        $record = \MultiTenantSaas\Models\FinancialRecord::create([
            'tenant_id' => $tenantId,
            'type' => 'recharge',
            'amount' => $request->amount,
            'description' => $request->description ?? '充值',
        ]);

        return response()->json(['success' => true, 'data' => $record], 201);
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
        // 使用 tenant_settings 存储 token 信息
        $tokens = \MultiTenantSaas\Models\TenantSetting::where('tenant_id', $tenantId)
            ->where('group', 'api_token')
            ->get()
            ->map(function ($s) {
                $data = json_decode($s->value, true) ?? [];
                return [
                    'id' => $s->id,
                    'name' => $data['name'] ?? $s->key,
                    'created_at' => $s->created_at,
                    'last_used_at' => $data['last_used_at'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                ];
            });

        return response()->json(['success' => true, 'data' => $tokens]);
    });

    Route::post('/tenants/{tenantId}/api-tokens', function (Request $request, int $tenantId) {
        $request->validate(['name' => 'required|string|max:255']);

        $plainToken = \Illuminate\Support\Str::random(40);
        $tokenHash = hash('sha256', $plainToken);

        \MultiTenantSaas\Models\TenantSetting::create([
            'tenant_id' => $tenantId,
            'group' => 'api_token',
            'key' => 'token_' . substr($tokenHash, 0, 8),
            'value' => json_encode([
                'name' => $request->name,
                'token_hash' => $tokenHash,
                'expires_at' => $request->expires_at,
            ]),
        ]);

        return response()->json([
            'success' => true,
            'data' => ['name' => $request->name, 'token' => $plainToken],
        ], 201);
    });

    Route::delete('/tenants/{tenantId}/api-tokens/{tokenId}', function (int $tenantId, int $tokenId) {
        \MultiTenantSaas\Models\TenantSetting::where('tenant_id', $tenantId)
            ->where('group', 'api_token')
            ->where('id', $tokenId)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Token已删除']);
    });

    // ----- 配额 -----
    Route::get('/tenants/{tenantId}/quotas', function (int $tenantId) {
        $tenant = Tenant::findOrFail($tenantId);
        $quotas = [
            ['resource' => 'members', 'label' => '成员数量', 'limit' => 100, 'used' => TenantUser::where('tenant_id', $tenantId)->count()],
            ['resource' => 'credits', 'label' => '积分余额', 'limit' => $tenant->total_credits, 'used' => $tenant->used_credits],
            ['resource' => 'storage', 'label' => '存储空间', 'limit' => 10240, 'used' => 0],
        ];
        return response()->json(['success' => true, 'data' => $quotas]);
    });

    // ----- 系统设置 (super_admin) -----
    Route::get('/admin/settings', function (Request $request) {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限访问'], 403);
        }
        $settings = SystemSetting::all()->groupBy('group');
        return response()->json(['success' => true, 'data' => $settings]);
    });

    Route::put('/admin/settings/{group}', function (Request $request, string $group) {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限访问'], 403);
        }

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
