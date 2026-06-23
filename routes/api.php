<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\TenantUser;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\CreditTransaction;
use MultiTenantSaas\Models\AuditLog;
use MultiTenantSaas\Models\SystemSetting;
use MultiTenantSaas\Modules\Domain\Services\DomainService;
use MultiTenantSaas\Modules\SSL\Services\TenantSslService;
use MultiTenantSaas\Services\PayService;
use MultiTenantSaas\Services\SocialiteService;

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

    // 注册
    Route::post('/register', function (Request $request) {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'platform_user',
        ]);

        // 关联到当前租户
        if ($tenantId) {
            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'role' => 'end_user',
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'user_id' => $user->user_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'tenant_id' => $tenantId,
                'token' => $token,
            ],
        ], 201);
    });

    // 忘记密码（发送重置邮件）
    Route::post('/forgot-password', function (Request $request) {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        // 始终返回成功，避免邮箱枚举
        if ($user) {
            // TODO: 发送密码重置邮件
        }

        return response()->json([
            'success' => true,
            'message' => '如果该邮箱已注册，您将收到重置密码邮件',
        ]);
    });

    // 重置密码
    Route::post('/reset-password', function (Request $request) {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'token' => 'required|string',
        ]);

        // TODO: 验证 token
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => '用户不存在'], 404);
        }

        $user->password = bcrypt($request->password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => '密码已重置',
        ]);
    });
});

// ========== 需要认证的 API ==========
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    
    // ----- 租户管理 (仅 super_admin) -----
    Route::get('/tenants', function (Request $request) {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }
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

    Route::get('/tenants/{tenantId}', function (Request $request, int $tenantId) {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }
        $tenant = Tenant::findOrFail($tenantId);
        return response()->json(['success' => true, 'data' => $tenant]);
    });

    Route::put('/tenants/{tenantId}', function (Request $request, int $tenantId) {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }
        $tenant = Tenant::findOrFail($tenantId);
        $tenant->update($request->only(['name', 'status', 'subscription_plan', 'custom_domain', 'description', 'contact_name', 'contact_email', 'contact_phone']));
        return response()->json(['success' => true, 'data' => $tenant]);
    });

    Route::delete('/tenants/{tenantId}', function (Request $request, int $tenantId) {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => '无权限'], 403);
        }
        Tenant::findOrFail($tenantId)->delete();
        return response()->json(['success' => true, 'message' => '已删除']);
    });

    // ----- 租户数据操作（需要验证用户属于该租户） -----
    
    // 辅助函数：验证用户是否属于该租户
    $ensureTenantAccess = function (Request $request, int $tenantId) {
        $user = $request->user();
        
        // super_admin 不能访问租户数据
        if ($user->role === 'super_admin') {
            return response()->json(['success' => false, 'message' => '系统管理员不能访问租户数据'], 403);
        }
        
        // 检查用户是否属于该租户
        $tenantUser = $user->tenants()
            ->where('tenants.tenant_id', $tenantId)
            ->wherePivot('is_active', true)
            ->first();
        
        if (!$tenantUser) {
            return response()->json(['success' => false, 'message' => '您不属于该租户'], 403);
        }
        
        return null; // 验证通过
    };

    // ----- 成员管理 -----
    Route::get('/tenants/{tenantId}/members', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $members = TenantUser::where('tenant_id', $tenantId)
            ->join('users', 'users.user_id', '=', 'tenant_users.user_id')
            ->select('users.user_id', 'users.name', 'users.email', 'tenant_users.role', 'tenant_users.is_active', 'tenant_users.joined_at')
            ->get();
        return response()->json(['success' => true, 'data' => $members]);
    });

    Route::post('/tenants/{tenantId}/members', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $request->validate(['user_id' => 'required', 'role' => 'in:tenant_admin,end_user']);
        
        TenantUser::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $request->user_id],
            ['role' => $request->role ?? 'end_user', 'is_active' => true, 'joined_at' => now()]
        );
        
        return response()->json(['success' => true, 'message' => '成员已添加']);
    });

    Route::put('/tenants/{tenantId}/members/{userId}', function (Request $request, int $tenantId, int $userId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $member = TenantUser::where('tenant_id', $tenantId)->where('user_id', $userId)->firstOrFail();
        $member->update($request->only(['role', 'is_active']));
        return response()->json(['success' => true, 'message' => '已更新']);
    });

    // ----- 积分管理 -----
    Route::get('/tenants/{tenantId}/credits', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
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
    Route::get('/tenants/{tenantId}/domain', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $service = new DomainService();
        return response()->json(['success' => true, 'data' => $service->getDomainInfo($tenantId)]);
    });

    Route::put('/tenants/{tenantId}/domain', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $request->validate(['domain' => 'required|string']);
        $service = new DomainService();
        $service->updateDomain($tenantId, $request->domain);
        return response()->json(['success' => true, 'message' => '域名已更新，等待审核']);
    });

    Route::post('/tenants/{tenantId}/domain/approve', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $service = new DomainService();
        $service->approveDomain($tenantId);
        return response()->json(['success' => true, 'message' => '域名已审核通过']);
    });

    Route::post('/tenants/{tenantId}/domain/reject', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $service = new DomainService();
        $service->rejectDomain($tenantId, $request->reason ?? '');
        return response()->json(['success' => true, 'message' => '域名已拒绝']);
    });

    // ----- SSL 证书管理 -----
    Route::get('/tenants/{tenantId}/ssl', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService();
        return response()->json(['success' => true, 'data' => $service->getCertInfo($tenant)]);
    });

    Route::post('/tenants/{tenantId}/ssl', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $request->validate([
            'certificate' => 'required|string',
            'private_key' => 'required|string',
        ]);
        
        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService();
        $service->storeCertificate($tenant, $request->certificate, $request->private_key);
        return response()->json(['success' => true, 'message' => 'SSL证书已上传']);
    });

    Route::delete('/tenants/{tenantId}/ssl', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $tenant = Tenant::findOrFail($tenantId);
        $service = new TenantSslService();
        $service->removeCertificate($tenant);
        return response()->json(['success' => true, 'message' => 'SSL证书已删除']);
    });

    // ----- 租户配置（通用） -----
    Route::get('/tenants/{tenantId}/settings/{group?}', function (Request $request, int $tenantId, string $group = null) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        if ($group) {
            // SMS 配置从全局 config 读取
            if ($group === 'sms') {
                return response()->json(['success' => true, 'data' => [
                    'driver' => config('services.sms.driver', 'log'),
                    'ww_endpoint' => config('services.sms.ww_endpoint', ''),
                    'ww_account' => config('services.sms.ww_account', ''),
                    'ww_sign' => config('services.sms.ww_sign', ''),
                    'mtedu_endpoint' => config('services.sms.mtedu_endpoint', ''),
                ]]);
            }
            $data = \MultiTenantSaas\Models\TenantSetting::getGroup($tenantId, $group);
        } else {
            $data = \MultiTenantSaas\Models\TenantSetting::getAll($tenantId);
        }
        
        return response()->json(['success' => true, 'data' => $data]);
    });

    Route::put('/tenants/{tenantId}/settings/{group}', function (Request $request, int $tenantId, string $group) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        // SMS 配置存储到全局 system_settings
        if ($group === 'sms') {
            $allowed = ['driver', 'ww_endpoint', 'ww_account', 'ww_password', 'ww_sign', 'ww_product_id', 'mtedu_endpoint'];
            foreach ($request->only($allowed) as $key => $value) {
                SystemSetting::updateOrCreate(
                    ['group' => 'sms', 'key' => $key],
                    ['value' => $value]
                );
            }
            return response()->json(['success' => true, 'message' => '短信配置已更新']);
        }

        // 其他配置存储到 tenant_settings
        $allowedGroups = ['info', 'oauth', 'auth', 'mail', 'registration'];
        if (!in_array($group, $allowedGroups)) {
            return response()->json(['success' => false, 'message' => '未知配置组'], 400);
        }

        foreach ($request->all() as $key => $value) {
            \MultiTenantSaas\Models\TenantSetting::set($tenantId, $group, $key, $value);
        }

        return response()->json(['success' => true, 'message' => '配置已更新']);
    });

    // ----- 短信测试发送 -----
    Route::post('/tenants/{tenantId}/settings/sms/test', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $request->validate(['phone' => 'required|string|regex:/^1[3-9]\d{9}$/']);
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $result = \MultiTenantSaas\Services\SmsService::send($request->phone, $code, 'test');
        if ($result) {
            return response()->json(['success' => true, 'message' => '测试短信已发送']);
        }
        return response()->json(['success' => false, 'message' => '短信发送失败'], 500);
    });

    // ----- 支付订单 -----
    Route::get('/tenants/{tenantId}/payment-orders', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
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

    Route::post('/tenants/{tenantId}/payment-orders', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
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
    Route::get('/tenants/{tenantId}/audit-logs', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
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
    Route::get('/tenants/{tenantId}/api-tokens', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
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

    Route::post('/tenants/{tenantId}/api-tokens', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
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

    Route::delete('/tenants/{tenantId}/api-tokens/{tokenId}', function (Request $request, int $tenantId, int $tokenId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        \MultiTenantSaas\Models\TenantSetting::where('tenant_id', $tenantId)
            ->where('group', 'api_token')
            ->where('id', $tokenId)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Token已删除']);
    });

    // ----- 配额 -----
    Route::get('/tenants/{tenantId}/quotas', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        $tenant = Tenant::findOrFail($tenantId);
        $quotas = [
            ['resource' => 'members', 'label' => '成员数量', 'limit' => 100, 'used' => TenantUser::where('tenant_id', $tenantId)->count()],
            ['resource' => 'credits', 'label' => '积分余额', 'limit' => $tenant->total_credits, 'used' => $tenant->used_credits],
            ['resource' => 'storage', 'label' => '存储空间', 'limit' => 10240, 'used' => 0],
        ];
        return response()->json(['success' => true, 'data' => $quotas]);
    });

    // ----- 租户支付配置 -----
    Route::get('/tenants/{tenantId}/payment/config', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        return response()->json(['success' => true, 'data' => PayService::getPaymentConfig($tenantId)]);
    });

    Route::put('/tenants/{tenantId}/payment/{driver}', function (Request $request, int $tenantId, string $driver) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        if (!in_array($driver, ['wechat', 'alipay'])) {
            return response()->json(['success' => false, 'message' => '不支持的支付方式'], 400);
        }
        PayService::updatePaymentConfig($tenantId, $driver, $request->all());
        return response()->json(['success' => true, 'message' => '支付配置已更新']);
    });

    // ----- 租户 OAuth 配置 -----
    Route::get('/tenants/{tenantId}/oauth/config', function (Request $request, int $tenantId) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        return response()->json(['success' => true, 'data' => SocialiteService::getOAuthConfigForDisplay($tenantId)]);
    });

    Route::put('/tenants/{tenantId}/oauth/{provider}', function (Request $request, int $tenantId, string $provider) use ($ensureTenantAccess) {
        if ($error = $ensureTenantAccess($request, $tenantId)) return $error;
        
        SocialiteService::updateOAuthConfig($tenantId, $provider, $request->all());
        return response()->json(['success' => true, 'message' => 'OAuth 配置已更新']);
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

    // ----- 第三方登录 -----
    Route::get('/auth/{provider}/redirect', function (Request $request, string $provider) {
        $tenantId = $request->attributes->get('tenant_id');
        $url = SocialiteService::getRedirectUrl($provider, $tenantId);
        return response()->json(['success' => true, 'data' => ['url' => $url]]);
    });

    Route::get('/auth/{provider}/callback', function (Request $request, string $provider) {
        $tenantId = $request->attributes->get('tenant_id');
        $result = SocialiteService::handleCallback($provider, $tenantId);
        return response()->json(['success' => true, 'data' => $result]);
    });

    // ----- 租户支付配置 -----
    Route::get('/tenants/{tenantId}/payment/config', function (int $tenantId) {
        return response()->json(['success' => true, 'data' => PayService::getPaymentConfig($tenantId)]);
    });

    Route::put('/tenants/{tenantId}/payment/{driver}', function (Request $request, int $tenantId, string $driver) {
        if (!in_array($driver, ['wechat', 'alipay'])) {
            return response()->json(['success' => false, 'message' => '不支持的支付方式'], 400);
        }
        PayService::updatePaymentConfig($tenantId, $driver, $request->all());
        return response()->json(['success' => true, 'message' => '支付配置已更新']);
    });

    // ----- 租户 OAuth 配置 -----
    Route::get('/tenants/{tenantId}/oauth/config', function (int $tenantId) {
        return response()->json(['success' => true, 'data' => SocialiteService::getOAuthConfigForDisplay($tenantId)]);
    });

    Route::put('/tenants/{tenantId}/oauth/{provider}', function (Request $request, int $tenantId, string $provider) {
        SocialiteService::updateOAuthConfig($tenantId, $provider, $request->all());
        return response()->json(['success' => true, 'message' => 'OAuth 配置已更新']);
    });

    // ----- 支付回调（无需认证） -----
    Route::post('/pay/wechat/notify', function (Request $request) {
        $result = PayService::handleCallback('wechat', $request);
        // 处理支付成功逻辑：更新订单状态、充值积分等
        return response('success');
    });

    Route::post('/pay/alipay/notify', function (Request $request) {
        $result = PayService::handleCallback('alipay', $request);
        // 处理支付成功逻辑
        return response('success');
    });
});
