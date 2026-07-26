<?php

namespace MultiTenantSaas\Modules\User\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\User\Http\Requests\StoreMemberRequest;
use MultiTenantSaas\Modules\User\Http\Resources\TenantUserResource;

class TenantMemberController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, ?int $tenantId = null)
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        $members = TenantUser::where('tenant_users.tenant_id', $tenantId)
            ->join('users', 'users.user_id', '=', 'tenant_users.user_id')
            ->select(
                'users.user_id', 'users.name', 'users.email',
                'tenant_users.is_active', 'tenant_users.joined_at'
            )
            ->get();

        return response()->json(['success' => true, 'data' => TenantUserResource::collection($members)]);
    }

    public function store(StoreMemberRequest $request, ?int $tenantId = null)
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        TenantUser::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $request->user_id],
            ['is_active' => true, 'joined_at' => now()]
        );

        app(AuditService::class)->log('create', 'tenant_user', $request->user_id, null, [
            'tenant_id' => $tenantId,
        ]);

        return response()->json(['success' => true, 'message' => trans('tenant.member_added')]);
    }

    public function update(Request $request, int $userId)
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
        ]);

        $member = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $oldValues = ['is_active' => $member->is_active];
        $member->update($validated);
        $newValues = $validated;

        app(AuditService::class)->log('update', 'tenant_user', $userId, $oldValues, $newValues);

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    /**
     * 移除租户成员
     */
    public function destroy(Request $request, ?int $tenantId = null, int $userId = 0)
    {
        $tenantId = $tenantId ?? TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId);

        $member = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $oldValues = ['is_active' => $member->is_active];
        $member->delete();

        // 删除该用户在此租户上下文的 token
        \DB::table('personal_access_tokens')
            ->where('tokenable_id', $userId)
            ->where('tenant_id', $tenantId)
            ->delete();

        app(AuditService::class)->log('remove', 'tenant_user', $userId, $oldValues, null);

        return response()->json(['success' => true, 'message' => trans('tenant.member_removed')]);
    }
}
