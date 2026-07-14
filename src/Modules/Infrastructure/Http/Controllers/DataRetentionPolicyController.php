<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Infrastructure\Models\DataRetentionPolicy;
use MultiTenantSaas\Modules\Infrastructure\Services\RetentionService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class DataRetentionPolicyController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected RetentionService $retentionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenant_id');
        $policies = $this->retentionService->listPolicies($tenantId ? (int) $tenantId : null);

        return $this->successResponse($policies);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data_type' => 'required|string|max:255',
            'retention_days' => 'required|integer|min:1',
            'auto_cleanup' => 'sometimes|boolean',
            'cleanup_strategy' => 'sometimes|string|in:delete,anonymize',
            'tenant_id' => 'nullable|integer',
            'description' => 'nullable|string|max:1000',
        ]);

        $policy = $this->retentionService->createOrUpdatePolicy(
            $validated['data_type'],
            $validated['retention_days'],
            $validated['auto_cleanup'] ?? true,
            $validated['cleanup_strategy'] ?? DataRetentionPolicy::STRATEGY_ANONYMIZE,
            $validated['tenant_id'] ?? null,
            $validated['description'] ?? null,
        );

        AuditService::log('create', 'data_retention_policy', $policy->data_retention_policy_id, null, $validated);

        return $this->createdResponse($policy);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $policy = DataRetentionPolicy::find($id);
        if (! $policy) {
            return $this->notFoundResponse('Data retention policy not found');
        }

        $validated = $request->validate([
            'retention_days' => 'sometimes|integer|min:1',
            'auto_cleanup' => 'sometimes|boolean',
            'cleanup_strategy' => 'sometimes|string|in:delete,anonymize',
            'is_exempt' => 'sometimes|boolean',
            'description' => 'nullable|string|max:1000',
        ]);

        $old = $policy->toArray();
        $policy->update($validated);

        AuditService::log('update', 'data_retention_policy', $id, $old, $validated);

        return $this->successResponse($policy->fresh());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->retentionService->deletePolicy($id)) {
            return $this->notFoundResponse('Data retention policy not found');
        }

        AuditService::log('delete', 'data_retention_policy', $id, null, null);

        return $this->deletedResponse();
    }
}
