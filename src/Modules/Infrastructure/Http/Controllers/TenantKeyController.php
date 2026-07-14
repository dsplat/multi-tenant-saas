<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantKey;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantKeyService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Scopes\TenantScope;

class TenantKeyController extends Controller
{
    use ApiResponse, AuthorizesTenantAccess;

    public function __construct(
        protected TenantKeyService $tenantKeyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $keys = TenantKey::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', (int) $tenantId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TenantKey $key) => [
                'tenant_key_id' => $key->tenant_key_id,
                'key_type' => $key->key_type,
                'status' => $key->status,
                'previous_key_id' => $key->previous_key_id,
                'rotated_at' => $key->rotated_at,
                'created_at' => $key->created_at,
            ]);

        return $this->successResponse($keys);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        try {
            $key = $this->tenantKeyService->generateKey((int) $tenantId);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage());
        }

        AuditService::log('create', 'tenant_key', $key->tenant_key_id, null, [
            'tenant_id' => $tenantId,
            'key_type' => $key->key_type,
        ]);

        return $this->createdResponse([
            'tenant_key_id' => $key->tenant_key_id,
            'key_type' => $key->key_type,
            'status' => $key->status,
            'created_at' => $key->created_at,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $key = $this->tenantKeyService->findKey($id);
        if (! $key || (int) $key->tenant_id !== (int) $tenantId) {
            return $this->notFoundResponse('Tenant key not found');
        }

        if ($key->status !== 'active') {
            return $this->errorResponse('Only active keys can be revoked');
        }

        $key->update(['status' => 'revoked']);

        AuditService::log('revoke', 'tenant_key', $id, ['status' => 'active'], ['status' => 'revoked']);

        return $this->deletedResponse('Key revoked');
    }
}
