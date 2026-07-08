<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\Lottery\LotteryActivity;
use MultiTenantSaas\Models\Lottery\LotteryActivityPrize;
use MultiTenantSaas\Models\Lottery\LotteryBlacklist;
use MultiTenantSaas\Services\LotteryService;

class LotteryController extends Controller
{
    use AuthorizesTenantAccess;

    // ========== 活动管理 ==========

    public function index(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $filters = array_filter([
            'status' => $request->query('status'),
        ]);

        $activities = LotteryService::getActivities($tenantId, $filters);

        return response()->json(['success' => true, 'data' => $activities]);
    }

    public function store(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:draft,active,paused,ended'],
            'rules' => ['nullable', 'array'],
            'rules.max_per_user' => ['nullable', 'integer', 'min:0'],
            'rules.require_login' => ['nullable', 'boolean'],
            'rules.anti_bot' => ['nullable', 'boolean'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'prizes' => ['nullable', 'array'],
            'prizes.*.name' => ['required_with:prizes', 'string', 'max:128'],
            'prizes.*.image_url' => ['nullable', 'string', 'max:512'],
            'prizes.*.type' => ['sometimes', 'string', 'in:physical,virtual,credit,coupon'],
            'prizes.*.value' => ['nullable', 'numeric', 'min:0'],
            'prizes.*.total_count' => ['required_with:prizes', 'integer', 'min:0'],
            'prizes.*.weight' => ['required_with:prizes', 'integer', 'min:0'],
            'prizes.*.sort_order' => ['nullable', 'integer'],
        ]);

        $data['tenant_id'] = $tenantId;

        $activity = LotteryService::createActivity($data);

        // 创建奖品
        if (!empty($data['prizes'])) {
            foreach ($data['prizes'] as $prize) {
                $prize['tenant_id'] = $tenantId;
                $prize['activity_id'] = $activity->activity_id;
                $prize['remaining_count'] = $prize['total_count'];
                LotteryActivityPrize::create($prize);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $activity->load('prizes'),
        ], 201);
    }

    public function show(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $activity = LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->with('prizes')
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $activity]);
    }

    public function update(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:draft,active,paused,ended'],
            'rules' => ['nullable', 'array'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date'],
        ]);

        $activity = LotteryService::updateActivity($activityId, $data);

        return response()->json(['success' => true, 'data' => $activity]);
    }

    public function destroy(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $activity = LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($activity->status === 'active') {
            return response()->json([
                'success' => false,
                'message' => '无法删除进行中的活动',
            ], 422);
        }

        $activity->delete();

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    public function updateStatus(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'status' => ['required', 'string', 'in:draft,active,paused,ended'],
        ]);

        $activity = LotteryService::updateActivityStatus($activityId, $request->status);

        return response()->json(['success' => true, 'data' => $activity]);
    }

    // ========== 奖品管理 ==========

    public function indexPrizes(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $prizes = LotteryService::getPrizes($activityId);

        return response()->json(['success' => true, 'data' => $prizes]);
    }

    public function storePrize(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'image_url' => ['nullable', 'string', 'max:512'],
            'type' => ['sometimes', 'string', 'in:physical,virtual,credit,coupon'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'total_count' => ['required', 'integer', 'min:0'],
            'weight' => ['required', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $prize = LotteryService::addPrize($activityId, $data);

        return response()->json(['success' => true, 'data' => $prize], 201);
    }

    public function updatePrize(Request $request, int $tenantId, int $activityId, int $prizeId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // 确保奖品属于该活动
        LotteryActivityPrize::where('prize_id', $prizeId)
            ->where('activity_id', $activityId)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'image_url' => ['nullable', 'string', 'max:512'],
            'type' => ['sometimes', 'string', 'in:physical,virtual,credit,coupon'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'total_count' => ['sometimes', 'integer', 'min:0'],
            'weight' => ['sometimes', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $prize = LotteryService::updatePrize($prizeId, $data);

        return response()->json(['success' => true, 'data' => $prize]);
    }

    public function destroyPrize(Request $request, int $tenantId, int $activityId, int $prizeId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // 确保奖品属于该活动
        LotteryActivityPrize::where('prize_id', $prizeId)
            ->where('activity_id', $activityId)
            ->firstOrFail();

        LotteryService::deletePrize($prizeId);

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    // ========== 抽奖执行 ==========

    public function draw(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        try {
            $result = LotteryService::draw(
                $activityId,
                $request->user()->user_id,
                $request->ip(),
                $request->userAgent()
            );

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // ========== 黑名单管理 ==========

    public function indexBlacklist(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $blacklist = LotteryService::getBlacklist($activityId);

        return response()->json(['success' => true, 'data' => $blacklist]);
    }

    public function storeBlacklist(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $data = $request->validate([
            'identifier_type' => ['required', 'string', 'in:user_id,ip,device_id'],
            'identifier' => ['required', 'string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:512'],
        ]);

        $blacklist = LotteryService::addToBlacklist(
            $tenantId,
            $activityId,
            $data['identifier_type'],
            $data['identifier'],
            $data['reason'] ?? null
        );

        return response()->json(['success' => true, 'data' => $blacklist], 201);
    }

    public function destroyBlacklist(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'identifier_type' => ['required', 'string', 'in:user_id,ip,device_id'],
            'identifier' => ['required', 'string', 'max:128'],
        ]);

        $removed = LotteryService::removeFromBlacklist(
            $activityId,
            $request->identifier_type,
            $request->identifier
        );

        return response()->json([
            'success' => true,
            'message' => $removed ? trans('common.deleted') : '未找到匹配的黑名单记录',
        ]);
    }

    // ========== 统计查询 ==========

    public function statistics(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $stats = LotteryService::getDrawStats($activityId);

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function userDrawLogs(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $userId = $request->user()->user_id;
        $logs = LotteryService::getUserDrawLogs($activityId, $userId);

        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function winLogs(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $limit = (int) $request->query('limit', 50);
        $logs = LotteryService::getWinLogs($activityId, $limit);

        return response()->json(['success' => true, 'data' => $logs]);
    }
}
