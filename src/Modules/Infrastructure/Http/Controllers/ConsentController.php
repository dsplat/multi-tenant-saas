<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Infrastructure\Models\Consent;
use MultiTenantSaas\Modules\Infrastructure\Services\ConsentService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class ConsentController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ConsentService $consentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Consent::query();

        if ($request->has('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->query('type'));
        }

        $consents = $query->orderByDesc('created_at')->paginate($request->query('per_page', 20));

        return $this->paginatedResponse($consents);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
            'type' => 'required|string|in:cookie,data_processing,marketing,terms',
            'version' => 'sometimes|string|max:50',
        ]);

        $consent = $this->consentService->grantConsent(
            $validated['user_id'],
            $validated['type'],
            $validated['version'] ?? $this->consentService->getCurrentTermsVersion(),
            $request->ip(),
            $request->userAgent(),
        );

        AuditService::log('grant', 'consent', $consent->consent_id, null, [
            'user_id' => $validated['user_id'],
            'type' => $validated['type'],
            'version' => $validated['version'] ?? $this->consentService->getCurrentTermsVersion(),
        ]);

        return $this->createdResponse($consent);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $consent = Consent::find($id);
        if (! $consent) {
            return $this->notFoundResponse('Consent not found');
        }

        if (! $consent->is_granted) {
            return $this->errorResponse('Consent already revoked');
        }

        $revoked = $this->consentService->revokeConsent(
            $consent->user_id,
            $consent->type,
            $consent->tenant_id ? (int) $consent->tenant_id : null,
        );

        if (! $revoked) {
            return $this->errorResponse('Failed to revoke consent');
        }

        AuditService::log('revoke', 'consent', $id, ['is_granted' => true], ['is_granted' => false]);

        return $this->successResponse(null, 'Consent revoked');
    }
}
