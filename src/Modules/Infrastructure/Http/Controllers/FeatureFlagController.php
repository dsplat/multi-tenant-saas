<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Infrastructure\Models\FeatureFlag;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class FeatureFlagController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = FeatureFlag::query();

        if ($request->has('scope')) {
            $query->where('scope', $request->query('scope'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $flags = $query->orderBy('name')->paginate($request->query('per_page', 20));

        return $this->paginatedResponse($flags);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:feature_flags,name',
            'description' => 'nullable|string|max:1000',
            'scope' => 'sometimes|string|in:global,tenant,user',
            'conditions' => 'nullable|array',
            'dependencies' => 'nullable|array',
            'dependencies.*' => 'string|max:255',
            'rollout_percentage' => 'sometimes|integer|min:0|max:100',
        ]);

        $flag = FeatureFlag::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'scope' => $validated['scope'] ?? FeatureFlag::SCOPE_GLOBAL,
            'conditions' => $validated['conditions'] ?? null,
            'dependencies' => $validated['dependencies'] ?? null,
            'rollout_percentage' => $validated['rollout_percentage'] ?? 0,
            'status' => FeatureFlag::STATUS_INACTIVE,
        ]);

        app(AuditService::class)->log('create', 'feature_flag', $flag->feature_flag_id, null, [
            'name' => $validated['name'],
            'scope' => $validated['scope'] ?? FeatureFlag::SCOPE_GLOBAL,
        ]);

        return $this->createdResponse($flag);
    }

    public function show(int $id): JsonResponse
    {
        $flag = FeatureFlag::find($id);
        if (! $flag) {
            return $this->notFoundResponse('Feature flag not found');
        }

        return $this->successResponse($flag);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $flag = FeatureFlag::find($id);
        if (! $flag) {
            return $this->notFoundResponse('Feature flag not found');
        }

        $validated = $request->validate([
            'description' => 'nullable|string|max:1000',
            'scope' => 'sometimes|string|in:global,tenant,user',
            'conditions' => 'nullable|array',
            'dependencies' => 'nullable|array',
            'dependencies.*' => 'string|max:255',
            'rollout_percentage' => 'sometimes|integer|min:0|max:100',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $old = $flag->toArray();
        $flag->update($validated);

        app(AuditService::class)->log('update', 'feature_flag', $id, $old, $validated);

        return $this->successResponse($flag->fresh());
    }

    public function toggle(int $id): JsonResponse
    {
        $flag = FeatureFlag::find($id);
        if (! $flag) {
            return $this->notFoundResponse('Feature flag not found');
        }

        $oldStatus = $flag->status;
        $newStatus = $oldStatus === FeatureFlag::STATUS_ACTIVE
            ? FeatureFlag::STATUS_INACTIVE
            : FeatureFlag::STATUS_ACTIVE;

        $flag->update(['status' => $newStatus]);

        app(AuditService::class)->log('toggle', 'feature_flag', $id, ['status' => $oldStatus], ['status' => $newStatus]);

        return $this->successResponse($flag->fresh(), "Feature flag {$newStatus}");
    }
}
