<?php

namespace MultiTenantSaas\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Billing\Services\SubscriptionService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\Storage\Models\FileUpload;

class TenantQuotaController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $tenant = Tenant::findOrFail($tenantId);
        $plan = SubscriptionService::getCurrentPlan($tenantId);

        $maxUsers = $plan?->getLimit('max_users');
        $maxStorage = $plan?->getLimit('max_storage_mb');

        $usedStorage = FileUpload::where('tenant_id', $tenantId)->sum('size');
        $usedStorageMb = round($usedStorage / 1024 / 1024, 2);

        $quotas = [
            [
                'resource' => 'members',
                'label' => trans('subscription.quota_members'),
                'limit' => $maxUsers,
                'used' => TenantUser::where('tenant_id', $tenantId)->count(),
            ],
            [
                'resource' => 'credits',
                'label' => trans('subscription.quota_credits'),
                'limit' => $tenant->total_credits,
                'used' => $tenant->used_credits,
            ],
            [
                'resource' => 'storage',
                'label' => trans('subscription.quota_storage'),
                'limit' => $maxStorage,
                'used' => $usedStorageMb,
                'unit' => 'MB',
            ],
        ];

        return response()->json(['success' => true, 'data' => $quotas]);
    }
}
