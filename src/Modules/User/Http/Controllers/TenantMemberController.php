<?php

namespace MultiTenantSaas\Modules\User\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\Logging\Services\AuditService;
use MultiTenantSaas\Modules\User\Http\Resources\TenantUserResource;

class TenantMemberController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $members = TenantUser::where('tenant_id', $tenantId)
            ->join('users', 'users.user_id', '=', 'tenant_users.user_id')
            ->select(
                'users.user_id', 'users.name', 'users.email',
                'tenant_users.role', 'tenant_users.is_active', 'tenant_users.joined_at'
            )
            ->get();

        return response()->json(['success' => true, 'data' => TenantUserResource::collection($members)]);
    }

    public function store(Request $request, int $tenantId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate([
            'user_id' => 'required',
            'role' => 'in:tenant_admin,end_user',
        ]);

        TenantUser::updateOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $request->user_id],
            ['role' => $request->role ?? 'end_user', 'is_active' => true, 'joined_at' => now()]
        );

        AuditService::log('create', 'tenant_user', $request->user_id, null, [
            'tenant_id' => $tenantId,
            'role' => $request->role ?? 'end_user',
        ]);

        return response()->json(['success' => true, 'message' => trans('tenant.member_added')]);
    }

    public function update(Request $request, int $tenantId, int $userId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $member = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->firstOrFail();

        $oldValues = ['role' => $member->role, 'is_active' => $member->is_active];
        $member->update($request->only(['role', 'is_active']));
        $newValues = $request->only(['role', 'is_active']);

        AuditService::log('update', 'tenant_user', $userId, $oldValues, $newValues);

        return response()->json(['success' => true, 'message' => trans('common.updated')]);
    }

    /**
     * 移除租户成员
     */
    public function destroy(Request $request, int $tenantId, int $userId)
    {
        $this->ensureTenantAccess($request, $tenantId);

        $member = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->firstOrFail();

        // 防止最后一个管理员被移除
        if ($member->role === 'tenant_admin') {
            $adminCount = TenantUser::where('tenant_id', $tenantId)
                ->where('role', 'tenant_admin')
                ->where('is_active', true)
                ->count();
            if ($adminCount <= 1) {
                return response()->json(['success' => false, 'message' => trans('tenant.last_admin_protected')], 400);
            }
        }

        $oldValues = ['role' => $member->role, 'is_active' => $member->is_active];
        $member->delete();

        // 删除该用户在此租户上下文的 token
        \DB::table('personal_access_tokens')
            ->where('tokenable_id', $userId)
            ->delete();

        AuditService::log('remove', 'tenant_user', $userId, $oldValues, null);

        return response()->json(['success' => true, 'message' => trans('tenant.member_removed')]);
    }
}
