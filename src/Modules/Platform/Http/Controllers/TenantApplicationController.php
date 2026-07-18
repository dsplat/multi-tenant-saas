<?php

namespace MultiTenantSaas\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Modules\Infrastructure\Services\MailerService;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Platform\Models\TenantApplication;

/**
 * 租户申请控制器（Operator 侧）
 *
 * 提供 Operator 提交申请、查看申请列表、公开查询进度等功能。
 */
class TenantApplicationController extends Controller
{
    public function __construct(
        protected MailerService $mailer,
    ) {}

    /**
     * 提交租户申请。
     *
     * POST /console/operator/apply（需 Operator 认证）
     * POST /operator-auth/apply（公开，可选）
     */
    public function apply(Request $request): JsonResponse
    {
        $request->validate([
            'org_name' => 'required|string|max:255',
            'org_industry' => 'nullable|string|max:100',
            'org_size' => 'nullable|string|max:50',
            'contact_info' => 'nullable|array',
        ]);

        // 获取当前 Operator（认证或公开 token）
        $operator = $request->user();

        if (! $operator instanceof Operator) {
            return response()->json(['success' => false, 'message' => trans('auth.unauthenticated')], 401);
        }

        // 检查是否已有待审核的申请
        $pendingCount = TenantApplication::where('operator_id', $operator->operator_id)
            ->whereIn('status', [TenantApplication::STATUS_SUBMITTED, TenantApplication::STATUS_UNDER_REVIEW])
            ->count();

        if ($pendingCount > 0) {
            return response()->json([
                'success' => false,
                'message' => trans('platform.application_pending'),
            ], 409);
        }

        $idGenerator = app(IdGeneratorContract::class);

        $application = TenantApplication::create([
            'application_id' => $idGenerator->generate(),
            'operator_id' => $operator->operator_id,
            'code' => TenantApplication::generateCode(),
            'org_name' => $request->org_name,
            'org_industry' => $request->org_industry,
            'org_size' => $request->org_size,
            'contact_info' => $request->contact_info,
            'status' => TenantApplication::STATUS_SUBMITTED,
        ]);

        // 发送申请提交确认邮件
        $this->mailer->sendTemplate($operator->email, 'application_submitted', [
            'name' => $operator->name,
            'org_name' => $application->org_name,
            'application_code' => $application->code,
            'status_url' => url('/apply/status/' . $application->code),
            'platform_name' => config('app.name'),
            'current_year' => date('Y'),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'application' => $this->formatApplication($application),
            ],
        ], 201);
    }

    /**
     * 获取当前 Operator 的申请列表。
     *
     * GET /console/operator/applications（需 Operator 认证）
     */
    public function myApplications(Request $request): JsonResponse
    {
        $operator = $request->user();

        if (! $operator instanceof Operator) {
            return response()->json(['success' => false, 'message' => trans('auth.unauthenticated')], 401);
        }

        $applications = TenantApplication::where('operator_id', $operator->operator_id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $applications->getCollection()->map(fn ($app) => $this->formatApplication($app)),
                'total' => $applications->total(),
                'page' => $applications->currentPage(),
                'per_page' => $applications->perPage(),
            ],
        ]);
    }

    /**
     * 公开查询申请进度。
     *
     * GET /public/apply/{code}（无需认证）
     */
    public function status(string $code): JsonResponse
    {
        $application = TenantApplication::where('code', $code)->first();

        if (! $application) {
            return response()->json(['success' => false, 'message' => trans('platform.application_not_found')], 404);
        }

        $timeline = [
            [
                'status' => TenantApplication::STATUS_SUBMITTED,
                'label' => trans('platform.timeline.submitted'),
                'time' => $application->created_at?->toISOString(),
                'completed' => true,
            ],
        ];

        if ($application->status !== TenantApplication::STATUS_SUBMITTED) {
            $timeline[] = [
                'status' => $application->status,
                'label' => trans('platform.timeline.' . $application->status),
                'time' => $application->reviewed_at?->toISOString(),
                'completed' => in_array($application->status, [TenantApplication::STATUS_APPROVED, TenantApplication::STATUS_REJECTED]),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $application->code,
                'org_name' => $application->org_name,
                'status' => $application->status,
                'review_notes' => $application->review_notes,
                'created_at' => $application->created_at?->toISOString(),
                'reviewed_at' => $application->reviewed_at?->toISOString(),
                'timeline' => $timeline,
            ],
        ]);
    }

    /**
     * 格式化申请数据。
     */
    protected function formatApplication(TenantApplication $application): array
    {
        return [
            'application_id' => $application->application_id,
            'code' => $application->code,
            'org_name' => $application->org_name,
            'org_industry' => $application->org_industry,
            'org_size' => $application->org_size,
            'status' => $application->status,
            'review_notes' => $application->review_notes,
            'created_at' => $application->created_at?->toISOString(),
            'reviewed_at' => $application->reviewed_at?->toISOString(),
        ];
    }
}
