<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use MultiTenantSaas\Services\TenantOnboardingService;

/**
 * 租户引导式注册控制器
 *
 * 处理多步骤租户注册流程（Onboarding）
 */
class TenantOnboardingController extends Controller
{
    public function __construct(
        private TenantOnboardingService $onboardingService
    ) {}

    /**
     * 启动注册流程（步骤1：基本信息）
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'admin_email' => 'required|email|max:255',
            'password' => 'required|string|min:8',
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
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'current_step' => 1,
            ],
        ], 201);
    }

    /**
     * 提交步骤数据
     */
    public function saveStep(Request $request, int $step): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $request->input('token');
        $data = $request->except('token');

        $result = $this->onboardingService->saveStep(
            $token,
            $step,
            $data,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * 获取注册状态
     */
    public function status(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $request->input('token');
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
     * 完成注册
     */
    public function complete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = $request->input('token');
        $tenant = $this->onboardingService->complete($token, $request->ip());

        return response()->json([
            'success' => true,
            'data' => [
                'tenant_id' => $tenant->tenant_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'onboarding_step' => $tenant->onboarding_step,
                'onboarding_completed' => (bool) $tenant->onboarding_completed,
                'trial_active' => $tenant->trial_ends_at !== null && $tenant->trial_ends_at->isFuture(),
            ],
        ], 201);
    }
}
