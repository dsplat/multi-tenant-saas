<?php

namespace MultiTenantSaas\Modules\Lottery\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Lottery\Models\LotteryActivity;
use MultiTenantSaas\Modules\Lottery\Models\LotteryActivityPrize;
use MultiTenantSaas\Modules\Lottery\Services\LotteryService;

/**
 * @OA\Tag(
 *     name="Lottery 抽奖",
 *     description="抽奖活动管理、奖品管理、抽奖执行、黑名单、统计查询"
 * )
 */
class LotteryController extends Controller
{
    use AuthorizesTenantAccess;

    // ========== 活动管理 ==========

    /**
     * @OA\Get(
     *     path="/v1/tenants/{tenantId}/lottery",
     *     summary="获取抽奖活动列表",
     *     tags={"Lottery 抽奖"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="tenantId", in="path", required=true, description="租户ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", description="状态筛选", @OA\Schema(type="string", enum={"draft","active","paused","ended"})),
     *
     *     @OA\Response(response=200, description="活动列表"),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=403, description="无权访问")
     * )
     */
    public function index(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $filters = array_filter([
            'status' => $request->query('status'),
        ]);

        $activities = LotteryService::getActivities($tenantId, $filters);

        return response()->json(['success' => true, 'data' => $activities]);
    }

    /**
     * @OA\Post(
     *     path="/v1/tenants/{tenantId}/lottery",
     *     summary="创建抽奖活动",
     *     tags={"Lottery 抽奖"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="tenantId", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *
     *         @OA\Property(property="title", type="string", description="活动标题"),
     *         @OA\Property(property="slug", type="string", description="URL标识"),
     *         @OA\Property(property="description", type="string", description="活动描述"),
     *         @OA\Property(property="rules", type="object", description="规则配置（含动画配置）"),
     *         @OA\Property(property="prizes", type="array", @OA\Items(type="object"), description="奖品列表")
     *     )),
     *
     *     @OA\Response(response=201, description="创建成功"),
     *     @OA\Response(response=422, description="验证失败")
     * )
     */
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
            // 动画配置
            'rules.animation_type' => ['nullable', 'string', 'in:wheel,scratch,egg,blindbox'],
            'rules.animation_duration' => ['nullable', 'integer', 'min:1000', 'max:10000'],
            'rules.animation_sound' => ['nullable', 'boolean'],
            'rules.animation_confetti' => ['nullable', 'boolean'],
            'rules.wheel_segments' => ['nullable', 'integer', 'min:4', 'max:24'],
            'rules.scratch_threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
        if (! empty($data['prizes'])) {
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

    /**
     * @OA\Post(
     *     path="/v1/tenants/{tenantId}/lottery/{activityId}/draw",
     *     summary="执行抽奖",
     *     tags={"Lottery 抽奖"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="tenantId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="activityId", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="抽奖结果", @OA\JsonContent(
     *
     *         @OA\Property(property="success", type="boolean"),
     *         @OA\Property(property="data", type="object",
     *             @OA\Property(property="result", type="string", enum={"win","miss","blacklist"}),
     *             @OA\Property(property="prize", type="object", nullable=true)
     *         )
     *     )),
     *
     *     @OA\Response(response=422, description="抽奖失败（活动未开始/已结束/黑名单等）")
     * )
     */
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

    // ========== 数据导出 ==========

    public function export(Request $request, int $tenantId, int $activityId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保活动属于当前租户
        LotteryActivity::where('activity_id', $activityId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $filters = array_filter([
            'user_id' => $request->query('user_id'),
            'result' => $request->query('result'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
        ]);

        $data = LotteryService::exportDrawLogs($activityId, $filters);

        return response()->json(['success' => true, 'data' => $data]);
    }
}
