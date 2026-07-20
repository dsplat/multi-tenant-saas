<?php

namespace MultiTenantSaas\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantOnboardingService;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * 租户引导式注册控制器（Operator 直连租户模式）
 *
 * 中间件：operator.auth（要求请求以已认证 Operator 身份发起）
 * Onboarding 流程只采集租户本身信息，不再要求 admin_email/password
 *
 * 流程：
 *   POST /tenants/onboarding/start           — 启动 Step1（租户基本信息）
 *   POST /tenants/onboarding/{step}           — 提交指定步骤数据
 *   GET  /tenants/onboarding/status           — 查询进度
 *   POST /tenants/onboarding/complete         — 完成，创建 Tenant（pending_approval）
 *
 * 平台审核通过后，监听器 ListenTenantActivated 自动写入 operator_tenants 关联
 * 此时 Operator 用原 operator_token 登录 /console 即进入该租户后台
 */
class TenantOnboardingController extends Controller
{
    public function __construct(
        private TenantOnboardingService $onboardingService
    ) {}

    /**
     * 启动注册流程（Step 1：租户基本信息）
     *
     * Body: { name, industry?, size?, contact_phone? }
     * 返回 onboarding session token（与 operator_token 不同，用于多步骤续填）
     */
    public function register(Request $request): JsonResponse
    {
        /** @var Operator|null $operator */
        $operator = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'industry' => 'nullable|string|max:100',
            'size' => 'nullable|string|in:small,medium,large',
            'contact_phone' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $this->onboardingService->startRegistration(
            $validator->validated(),
            $operator?->operator_id,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'data' => [
                'onboarding_token' => $token,
                'current_step' => 2,
            ],
        ], 201);
    }

    /**
     * 提交指定步骤数据
     *
     * Path param: step (2-4)
     * Body: { onboarding_token, ...stepData }
     */
    public function saveStep(Request $request, int $step): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'onboarding_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $request->input('onboarding_token');
        $data = $request->except('onboarding_token');

        // 仅允许提交数据的步骤（2/3/4），Step1 由 register 启动，Step5 由 complete 触发
        if (! in_array($step, [2, 3, 4], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid step',
            ], 422);
        }

        try {
            $result = $this->onboardingService->saveStep(
                $token,
                $step,
                $data,
                $request->ip()
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * 查询注册状态
     *
     * Query/Body: onboarding_token
     */
    public function status(Request $request): JsonResponse
    {
        $token = $request->input('onboarding_token') ?: $request->query('onboarding_token');
        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'onboarding_token required',
            ], 422);
        }

        $status = $this->onboardingService->getStatus($token, $request->ip());

        if ($status === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired token',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * 完成注册，创建 Tenant（status=pending_approval）
     *
     * Body: { onboarding_token }
     *
     * 返回 tenant_id + pending_approval 状态，提示用户等待平台审核
     * 不再返回新的 auth_token（Operator 继续用原 operator_token）
     */
    public function complete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'onboarding_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $request->input('onboarding_token');

        try {
            $tenant = $this->onboardingService->complete($token, $request->ip());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,  // pending_approval
                'onboarding_step' => $tenant->onboarding_step,
                'onboarding_completed' => (bool) $tenant->onboarding_completed,
                'message' => '租户已创建，等待平台审核通过后即可登录控制台',
            ],
        ], 201);
    }
}
