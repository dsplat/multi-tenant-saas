<?php

namespace MultiTenantSaas\Modules\User\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\User\Http\Resources\UserResource;
use MultiTenantSaas\Modules\User\Services\UserSearchService;
use MultiTenantSaas\Modules\User\Services\UserService;

class UserController extends Controller
{
    use AuthorizesTenantAccess;

    public function __construct(
        private UserService $userService,
        private UserSearchService $userSearchService,
    ) {}

    /**
     * List users in the current tenant (paginated, with search)
     */
    public function index(Request $request, ?int $tenantId = null): JsonResponse
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        $query = $request->input('search', '');
        $filters = $request->only([
            'role', 'is_active', 'email_verified',
            'sort_by', 'sort_direction', 'per_page',
        ]);

        $users = $this->userSearchService->search($tenantId, $query, $filters);

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Show user details within a tenant
     */
    public function show(Request $request, int $tenantId, int $userId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $user = $this->userService->find($userId);

        // Verify user belongs to this tenant
        if (! $user->tenants()->where('tenants.tenant_id', $tenantId)->exists()) {
            return response()->json(['success' => false, 'message' => trans('user.not_in_tenant')], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Update user profile (name, phone, avatar)
     */
    public function update(Request $request, int $tenantId, int $userId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|string|max:500',
        ]);

        $user = $this->userService->find($userId);

        if (! $user->tenants()->where('tenants.tenant_id', $tenantId)->exists()) {
            return response()->json(['success' => false, 'message' => trans('user.not_in_tenant')], 404);
        }

        $oldValues = $user->only(array_keys($validated));
        $user = $this->userService->update($userId, $validated);

        app(AuditService::class)->log('update', 'user', $userId, $oldValues, $validated);

        return response()->json([
            'success' => true,
            'message' => trans('common.updated'),
            'data' => new UserResource($user),
        ]);
    }

    /**
     * Deactivate user (soft delete)
     */
    public function destroy(Request $request, int $tenantId, int $userId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $user = $this->userService->find($userId);

        if (! $user->tenants()->where('tenants.tenant_id', $tenantId)->exists()) {
            return response()->json(['success' => false, 'message' => trans('user.not_in_tenant')], 404);
        }

        $this->userService->delete($userId);

        app(AuditService::class)->log('deactivate', 'user', $userId, ['is_active' => true], ['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => trans('user.deactivated'),
        ]);
    }

    /**
     * Search users by name/email within a tenant
     */
    public function search(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate([
            'q' => 'required|string|min:1|max:255',
        ]);

        $query = $request->input('q', '');
        $filters = $request->only(['role', 'is_active', 'per_page']);
        $filters['per_page'] = $filters['per_page'] ?? 20;

        $users = $this->userSearchService->search($tenantId, $query, $filters);

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Admin: list users in a specific tenant
     */
    public function adminIndex(Request $request, int $tenantId): JsonResponse
    {
        $query = $request->input('search', '');
        $filters = $request->only([
            'role', 'is_active', 'email_verified',
            'sort_by', 'sort_direction', 'per_page',
        ]);

        $users = $this->userSearchService->search($tenantId, $query, $filters);

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Admin: global user search across all tenants
     */
    public function globalSearch(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:255',
        ]);

        $filters = $request->only(['role', 'email_verified', 'per_page']);
        $perPage = $filters['per_page'] ?? 20;

        $users = $this->userService->list(array_merge($filters, [
            'search' => $request->input('q'),
            'per_page' => $perPage,
        ]));

        return response()->json([
            'success' => true,
            'data' => UserResource::collection($users),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }
}
