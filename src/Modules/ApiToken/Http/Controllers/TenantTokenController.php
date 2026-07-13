<?php

namespace MultiTenantSaas\Modules\ApiToken\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

class TenantTokenController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 可用的 Token abilities
     */
    private const AVAILABLE_ABILITIES = [
        'tenant:read',
        'tenant:write',
        'member:read',
        'member:write',
        'credit:read',
        'credit:write',
        'file:read',
        'file:write',
        'payment:read',
        'payment:write',
        'subscription:read',
        'subscription:write',
        'notification:read',
        'notification:write',
    ];

    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tokens = TenantSetting::where('tenant_id', $tenantId)
            ->where('group', 'api_token')
            ->get()
            ->map(function ($s) {
                $data = json_decode($s->value, true) ?? [];

                return [
                    'id' => $s->id,
                    'name' => $data['name'] ?? $s->key,
                    'abilities' => $data['abilities'] ?? ['*'],
                    'created_at' => $s->created_at,
                    'last_used_at' => $data['last_used_at'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                ];
            });

        return response()->json(['success' => true, 'data' => $tokens]);
    }

    public function store(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array',
            'abilities.*' => 'string|in:' . implode(',', self::AVAILABLE_ABILITIES),
            'expires_at' => 'nullable|date|after:now',
        ]);

        $abilities = $validated['abilities'] ?? ['*'];

        $plainToken = Str::random(40);
        $tokenHash = hash('sha256', $plainToken);

        TenantSetting::create([
            'tenant_id' => $tenantId,
            'group' => 'api_token',
            'key' => 'token_' . substr($tokenHash, 0, 8),
            'value' => json_encode([
                'name' => $validated['name'],
                'token_hash' => $tokenHash,
                'abilities' => $abilities,
                'expires_at' => $validated['expires_at'] ?? null,
            ]),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $validated['name'],
                'token' => $plainToken,
                'abilities' => $abilities,
            ],
        ], 201);
    }

    public function destroy(Request $request, int $tenantId, int $tokenId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        TenantSetting::where('tenant_id', $tenantId)
            ->where('group', 'api_token')
            ->where('id', $tokenId)
            ->delete();

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    /**
     * 返回可用的 Token abilities 列表
     */
    public function abilities(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        return response()->json([
            'success' => true,
            'data' => self::AVAILABLE_ABILITIES,
        ]);
    }
}
