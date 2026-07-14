<?php

namespace MultiTenantSaas\Modules\Coupon\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Coupon\Models\Coupon;
use MultiTenantSaas\Modules\Coupon\Services\CouponService;

class CouponController extends Controller
{
    use AuthorizesTenantAccess;

    // ========== 优惠券管理 ==========

    public function index(Request $request): JsonResponse
    {
        $filters = array_filter([
            'type' => $request->query('type'),
            'applies_to' => $request->query('applies_to'),
            'is_active' => $request->has('is_active') ? (bool) $request->query('is_active') : null,
            'subscription_plan_id' => $request->query('subscription_plan_id'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
            'keyword' => $request->query('keyword'),
        ]);

        $coupons = CouponService::getCoupons($filters, $request->query('per_page'));

        return response()->json(['success' => true, 'data' => $coupons]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'prefix' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string', 'max:512'],
            'type' => ['required', 'string', 'in:fixed,percentage'],
            'value' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['sometimes', 'string', 'in:subscription,all'],
            'subscription_plan_id' => ['nullable', 'integer'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_tenant' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $coupon = CouponService::createCoupon($data);

            return response()->json(['success' => true, 'data' => $coupon], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(Request $request, int $couponId): JsonResponse
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $coupon = Coupon::findOrFail($couponId);

        return response()->json(['success' => true, 'data' => $coupon]);
    }

    public function activate(Request $request, int $couponId): JsonResponse
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $coupon = CouponService::activate($couponId);

        return response()->json(['success' => true, 'data' => $coupon]);
    }

    public function deactivate(Request $request, int $couponId): JsonResponse
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $coupon = CouponService::deactivate($couponId);

        return response()->json(['success' => true, 'data' => $coupon]);
    }

    // ========== 核销与统计 ==========

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'tenant_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'user_id' => ['nullable', 'integer'],
            'invoice_id' => ['nullable', 'integer'],
            'subscription_plan_id' => ['nullable', 'integer'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);

        try {
            $usage = CouponService::redeem(
                $data['code'],
                $data['tenant_id'] ?? null,
                $data
            );

            return response()->json(['success' => true, 'data' => $usage], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'tenant_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'subscription_plan_id' => ['nullable', 'integer'],
        ]);

        try {
            $coupon = CouponService::validate(
                $data['code'],
                $data['tenant_id'] ?? null,
                $data['amount'] ?? null,
                $data['subscription_plan_id'] ?? null
            );

            return response()->json(['success' => true, 'data' => $coupon]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function usages(int $couponId, Request $request): JsonResponse
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $tenantId = $request->query('tenant_id');
        $usages = CouponService::getUsages($couponId, $tenantId);

        return response()->json(['success' => true, 'data' => $usages]);
    }

    public function statistics(Request $request, int $couponId): JsonResponse
    {
        $this->ensureTenantAccess($request, TenantContext::getId());

        $stats = CouponService::getStatistics($couponId);

        return response()->json(['success' => true, 'data' => $stats]);
    }

    // ========== 模板管理 ==========

    public function indexTemplates(Request $request): JsonResponse
    {
        $filters = array_filter([
            'type' => $request->query('type'),
            'applies_to' => $request->query('applies_to'),
            'is_active' => $request->has('is_active') ? (bool) $request->query('is_active') : null,
            'keyword' => $request->query('keyword'),
        ]);

        $templates = CouponService::getTemplates($filters, $request->query('per_page'));

        return response()->json(['success' => true, 'data' => $templates]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:512'],
            'type' => ['required', 'string', 'in:fixed,percentage'],
            'value' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['sometimes', 'string', 'in:subscription,all'],
            'subscription_plan_id' => ['nullable', 'integer'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_tenant' => ['nullable', 'integer', 'min:1'],
            'valid_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $template = CouponService::createTemplate($data);

        return response()->json(['success' => true, 'data' => $template], 201);
    }

    public function updateTemplate(Request $request, int $templateId): JsonResponse
    {
        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:512'],
            'type' => ['sometimes', 'string', 'in:fixed,percentage'],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['sometimes', 'string', 'in:subscription,all'],
            'subscription_plan_id' => ['nullable', 'integer'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_tenant' => ['nullable', 'integer', 'min:1'],
            'valid_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $template = CouponService::updateTemplate($templateId, $data);

        return response()->json(['success' => true, 'data' => $template]);
    }

    public function deleteTemplate(int $templateId): JsonResponse
    {
        try {
            CouponService::deleteTemplate($templateId);

            return response()->json(['success' => true, 'message' => trans('common.deleted')]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function activateTemplate(int $templateId): JsonResponse
    {
        $template = CouponService::activateTemplate($templateId);

        return response()->json(['success' => true, 'data' => $template]);
    }

    public function deactivateTemplate(int $templateId): JsonResponse
    {
        $template = CouponService::deactivateTemplate($templateId);

        return response()->json(['success' => true, 'data' => $template]);
    }

    // ========== 批量发券 ==========

    public function generateFromTemplate(Request $request, int $templateId): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'prefix' => ['nullable', 'string', 'max:8'],
        ]);

        try {
            $codes = CouponService::generateFromTemplate($templateId, $data['quantity'], $data['prefix'] ?? '');

            return response()->json(['success' => true, 'data' => ['codes' => $codes, 'count' => count($codes)]], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function bulkDistribute(Request $request, int $templateId): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
            'tenant_id' => ['required', 'integer'],
        ]);

        try {
            $result = CouponService::bulkDistribute($templateId, $data['user_ids'], $data['tenant_id']);

            return response()->json(['success' => true, 'data' => $result], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // ========== 裂变发券 ==========

    public function share(Request $request, int $templateId): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer'],
        ]);

        try {
            $share = CouponService::shareCoupon(
                $request->user()->user_id,
                $templateId,
                $data['tenant_id']
            );

            return response()->json(['success' => true, 'data' => $share], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function acceptShare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'share_code' => ['required', 'string', 'max:32'],
            'tenant_id' => ['required', 'integer'],
        ]);

        try {
            $result = CouponService::acceptShare(
                $data['share_code'],
                $request->user()->user_id,
                $data['tenant_id']
            );

            return response()->json(['success' => true, 'data' => $result], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function shareRecords(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $filters = array_filter([
            'sharer_id' => $request->query('sharer_id'),
            'status' => $request->query('status'),
        ]);

        $records = CouponService::getShareRecords($tenantId, $filters, $request->query('per_page'));

        return response()->json(['success' => true, 'data' => $records]);
    }
}
