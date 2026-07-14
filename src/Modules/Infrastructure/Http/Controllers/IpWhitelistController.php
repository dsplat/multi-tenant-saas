<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Services\IpWhitelistService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class IpWhitelistController extends Controller
{
    use ApiResponse, AuthorizesTenantAccess;

    public function __construct(
        protected IpWhitelistService $ipWhitelistService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $entries = $this->ipWhitelistService->list($request->query('scope'));

        return $this->successResponse($entries);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $validated = $request->validate([
            'ip' => 'required|string|max:45',
            'description' => 'nullable|string|max:500',
            'scope' => 'sometimes|string|in:all,api,admin',
        ]);

        $entry = $this->ipWhitelistService->create(
            $validated['ip'],
            $validated['scope'] ?? 'all',
            $validated['description'] ?? null,
        );

        AuditService::log('create', 'ip_whitelist', $entry->ip_whitelist_id, null, [
            'ip' => $validated['ip'],
            'scope' => $validated['scope'] ?? 'all',
        ]);

        return $this->createdResponse($entry);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        if (! $this->ipWhitelistService->delete($id)) {
            return $this->notFoundResponse('IP whitelist entry not found');
        }

        AuditService::log('delete', 'ip_whitelist', $id, null, null);

        return $this->deletedResponse();
    }
}
