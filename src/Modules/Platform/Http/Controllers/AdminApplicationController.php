<?php

namespace MultiTenantSaas\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Modules\Platform\Models\TenantApplication;

/**
 * 租户申请审批控制器（/admin 侧）
 *
 * 提供平台管理员查看申请列表、审批/拒绝申请等功能。
 * 审批通过后自动创建 Tenant + OperatorTenant 关联。
 */
class AdminApplicationController extends Controller
{
    public function __construct(
        protected MailerService $mailer,
    ) {}

    /**
     * 申请列表（带筛选 + 分页）。
     *
     * GET /admin/applications
     */
    public function index(Request $request): JsonResponse
    {
        $query = TenantApplication::with('operator');

        // 按状态筛选
        if ($status = $request->input('status')) {
            $query->ofStatus($status);
        }

        // 按组织名称搜索
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('org_name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // 仅待审核
        if ($request->boolean('pending')) {
            $query->pending();
        }

        $applications = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $applications->getCollection()->map(fn ($app) => $this->formatApplicationWithOperator($app)),
                'total' => $applications->total(),
                'page' => $applications->currentPage(),
                'per_page' => $applications->perPage(),
                'last_page' => $applications->lastPage(),
            ],
        ]);
    }

    /**
     * 申请详情。
     *
     * GET /admin/applications/{id}
     */
    public function show(int $id): JsonResponse
    {
        $application = TenantApplication::with('operator', 'reviewer')->find($id);

        if (! $application) {
            return response()->json(['success' => false, 'message' => trans('platform.application_not_found')], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatApplicationDetail($application),
        ]);
    }

    /**
     * 审批通过：创建 Tenant + OperatorTenant + 发邮件。
     *
     * POST /admin/applications/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'review_notes' => 'nullable|string|max:2000',
            'subscription_plan' => 'nullable|string|max:50',
            'total_credits' => 'nullable|integer|min:0',
        ]);

        $application = TenantApplication::find($id);

        if (! $application) {
            return response()->json(['success' => false, 'message' => trans('platform.application_not_found')], 404);
        }

        if (! in_array($application->status, [
            TenantApplication::STATUS_SUBMITTED,
            TenantApplication::STATUS_UNDER_REVIEW,
        ])) {
            return response()->json([
                'success' => false,
                'message' => trans('platform.application_already_reviewed'),
            ], 409);
        }

        $reviewer = $request->user();
        $idGenerator = app(IdGeneratorContract::class);

        DB::beginTransaction();
        try {
            // 1. 创建租户
            $slug = Str::slug($application->org_name) . '-' . Str::random(4);
            $contactInfo = $application->contact_info ?? [];

            $tenant = Tenant::create([
                'tenant_id' => $idGenerator->generate(),
                'name' => $application->org_name,
                'slug' => $slug,
                'status' => 'active',
                'subscription_plan' => $request->input('subscription_plan', 'free'),
                'contact_name' => $contactInfo['name'] ?? $application->operator?->name,
                'contact_email' => $contactInfo['email'] ?? $application->operator?->email,
                'contact_phone' => $contactInfo['phone'] ?? $application->operator?->phone,
                'total_credits' => $request->input('total_credits', 0),
                'used_credits' => 0,
                'settings' => [],
                'branding' => [],
            ]);

            // 2. 创建 OperatorTenant 关联
            OperatorTenant::create([
                'operator_id' => $application->operator_id,
                'tenant_id' => $tenant->tenant_id,
                'role' => 'tenant_admin',
                'is_active' => true,
                'accepted_at' => now(),
            ]);

            // 3. 更新申请状态
            $application->update([
                'status' => TenantApplication::STATUS_APPROVED,
                'review_notes' => $request->input('review_notes'),
                'reviewed_by' => $reviewer instanceof Operator ? $reviewer->operator_id : null,
                'reviewed_at' => now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => trans('platform.approval_failed', ['error' => $e->getMessage()]),
            ], 500);
        }

        // 4. 发送审批通过邮件（事务外执行，避免邮件失败回滚数据）
        $operator = $application->operator;
        if ($operator) {
            $this->mailer->sendTemplate($operator->email, 'application_approved', [
                'name' => $operator->name,
                'org_name' => $application->org_name,
                'application_code' => $application->code,
                'console_url' => url('/console/login'),
                'platform_name' => config('app.name'),
                'current_year' => date('Y'),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => trans('platform.application_approved'),
            'data' => [
                'application' => $this->formatApplicationWithOperator($application->fresh()),
                'tenant' => [
                    'tenant_id' => $tenant->tenant_id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ],
            ],
        ]);
    }

    /**
     * 审批拒绝。
     *
     * POST /admin/applications/{id}/reject
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'review_notes' => 'required|string|max:2000',
        ]);

        $application = TenantApplication::find($id);

        if (! $application) {
            return response()->json(['success' => false, 'message' => trans('platform.application_not_found')], 404);
        }

        if (! in_array($application->status, [
            TenantApplication::STATUS_SUBMITTED,
            TenantApplication::STATUS_UNDER_REVIEW,
        ])) {
            return response()->json([
                'success' => false,
                'message' => trans('platform.application_already_reviewed'),
            ], 409);
        }

        $reviewer = $request->user();

        $application->update([
            'status' => TenantApplication::STATUS_REJECTED,
            'review_notes' => $request->input('review_notes'),
            'reviewed_by' => $reviewer instanceof Operator ? $reviewer->operator_id : null,
            'reviewed_at' => now(),
        ]);

        // 发送拒绝邮件
        $operator = $application->operator;
        if ($operator) {
            $this->mailer->sendTemplate($operator->email, 'application_rejected', [
                'name' => $operator->name,
                'org_name' => $application->org_name,
                'application_code' => $application->code,
                'reject_reason' => $application->review_notes,
                'platform_name' => config('app.name'),
                'current_year' => date('Y'),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => trans('platform.application_rejected'),
            'data' => [
                'application' => $this->formatApplicationWithOperator($application->fresh()),
            ],
        ]);
    }

    /**
     * 格式化申请（含 Operator 基本信息）。
     */
    protected function formatApplicationWithOperator(TenantApplication $application): array
    {
        return [
            'application_id' => $application->application_id,
            'code' => $application->code,
            'org_name' => $application->org_name,
            'org_industry' => $application->org_industry,
            'org_size' => $application->org_size,
            'contact_info' => $application->contact_info,
            'status' => $application->status,
            'review_notes' => $application->review_notes,
            'created_at' => $application->created_at?->toISOString(),
            'reviewed_at' => $application->reviewed_at?->toISOString(),
            'operator' => $application->operator ? [
                'operator_id' => $application->operator->operator_id,
                'name' => $application->operator->name,
                'email' => $application->operator->email,
            ] : null,
        ];
    }

    /**
     * 格式化申请详情（含审批人信息）。
     */
    protected function formatApplicationDetail(TenantApplication $application): array
    {
        $data = $this->formatApplicationWithOperator($application);
        $data['reviewer'] = $application->reviewer ? [
            'operator_id' => $application->reviewer->operator_id,
            'name' => $application->reviewer->name,
        ] : null;

        return $data;
    }
}
