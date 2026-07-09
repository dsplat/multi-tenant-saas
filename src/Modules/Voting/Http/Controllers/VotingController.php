<?php

namespace MultiTenantSaas\Modules\Voting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Voting\Models\Vote;
use MultiTenantSaas\Modules\Voting\Models\VoteOption;
use MultiTenantSaas\Modules\Voting\Services\VotingService;

/**
 * @OA\Tag(
 *     name="Voting 投票",
 *     description="投票活动管理、投票执行、排行榜、统计查询"
 * )
 */
class VotingController extends Controller
{
    use AuthorizesTenantAccess;

    public function __construct(
        private VotingService $votingService,
    ) {}

    // ========== 投票活动管理 ==========

    /**
     * @OA\Get(
     *     path="/v1/tenants/{tenantId}/voting",
     *     summary="获取投票活动列表",
     *     tags={"Voting 投票"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="tenantId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"draft","active","ended"})),
     *     @OA\Response(response=200, description="投票列表"),
     *     @OA\Response(response=401, description="未认证"),
     *     @OA\Response(response=403, description="无权访问")
     * )
     */
    public function index(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $query = Vote::where('tenant_id', $tenantId);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $votes = $query->orderByDesc('created_at')
            ->paginate($request->query('per_page', 15));

        return response()->json(['success' => true, 'data' => $votes]);
    }

    /**
     * @OA\Post(
     *     path="/v1/tenants/{tenantId}/voting",
     *     summary="创建投票活动",
     *     tags={"Voting 投票"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="tenantId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="title", type="string", description="投票标题"),
     *         @OA\Property(property="vote_type", type="string", enum={"single","multiple"}, description="投票类型"),
     *         @OA\Property(property="options", type="array", @OA\Items(type="object"), description="投票选项（至少2个）")
     *     )),
     *     @OA\Response(response=201, description="创建成功"),
     *     @OA\Response(response=422, description="验证失败")
     * )
     */
    public function store(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'vote_type' => ['sometimes', 'string', 'in:single,multiple'],
            'status' => ['sometimes', 'string', 'in:draft,active,ended'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'total_limit' => ['nullable', 'integer', 'min:0'],
            'daily_limit_per_user' => ['nullable', 'integer', 'min:0'],
            'total_limit_per_user' => ['nullable', 'integer', 'min:0'],
            'anti_cheat_ip' => ['nullable', 'boolean'],
            'show_result' => ['nullable', 'boolean'],
            'show_rank' => ['nullable', 'boolean'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.title' => ['required', 'string', 'max:255'],
            'options.*.image' => ['nullable', 'string', 'max:512'],
            'options.*.description' => ['nullable', 'string'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ]);

        $vote = $this->votingService->createVote($data, $tenantId);

        return response()->json([
            'success' => true,
            'data' => $vote->load('options'),
        ], 201);
    }

    public function show(Request $request, int $tenantId, int $voteId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $vote = Vote::where('vote_id', $voteId)
            ->where('tenant_id', $tenantId)
            ->with('options')
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $vote]);
    }

    public function update(Request $request, int $tenantId, int $voteId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $vote = Vote::where('vote_id', $voteId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'vote_type' => ['sometimes', 'string', 'in:single,multiple'],
            'status' => ['sometimes', 'string', 'in:draft,active,ended'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'total_limit' => ['nullable', 'integer', 'min:0'],
            'daily_limit_per_user' => ['nullable', 'integer', 'min:0'],
            'total_limit_per_user' => ['nullable', 'integer', 'min:0'],
            'anti_cheat_ip' => ['nullable', 'boolean'],
            'show_result' => ['nullable', 'boolean'],
            'show_rank' => ['nullable', 'boolean'],
            'options' => ['sometimes', 'array', 'min:2'],
            'options.*.title' => ['required_with:options', 'string', 'max:255'],
            'options.*.image' => ['nullable', 'string', 'max:512'],
            'options.*.description' => ['nullable', 'string'],
            'options.*.sort_order' => ['nullable', 'integer'],
        ]);

        $vote = $this->votingService->updateVote($vote, $data);

        return response()->json(['success' => true, 'data' => $vote]);
    }

    public function destroy(Request $request, int $tenantId, int $voteId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $vote = Vote::where('vote_id', $voteId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($vote->status === 'active') {
            return response()->json([
                'success' => false,
                'message' => '无法删除进行中的投票',
            ], 422);
        }

        $vote->delete();

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    // ========== 投票执行 ==========

    /**
     * @OA\Post(
     *     path="/v1/tenants/{tenantId}/voting/{voteId}/cast",
     *     summary="执行投票",
     *     tags={"Voting 投票"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="tenantId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="voteId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         @OA\Property(property="option_ids", type="array", @OA\Items(type="integer"), description="选项ID列表")
     *     )),
     *     @OA\Response(response=200, description="投票成功"),
     *     @OA\Response(response=422, description="投票失败（活动未开始/已结束/次数限制等）")
     * )
     */
    public function castVote(Request $request, int $tenantId, int $voteId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate([
            'option_ids' => ['required', 'array', 'min:1'],
            'option_ids.*' => ['integer'],
        ]);

        try {
            $records = $this->votingService->castVote(
                $voteId,
                $request->option_ids,
                $request->user()->user_id,
                $tenantId,
                $request->ip(),
                $request->userAgent()
            );

            return response()->json(['success' => true, 'data' => $records]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // ========== 排行榜与统计 ==========

    /**
     * @OA\Get(
     *     path="/v1/tenants/{tenantId}/voting/{voteId}/ranking",
     *     summary="获取投票排行榜",
     *     tags={"Voting 投票"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="tenantId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="voteId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="排行榜数据")
     * )
     */
    public function ranking(Request $request, int $tenantId, int $voteId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保投票属于当前租户
        Vote::where('vote_id', $voteId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $ranking = $this->votingService->getRanking($voteId);

        return response()->json(['success' => true, 'data' => $ranking]);
    }

    /**
     * @OA\Get(
     *     path="/v1/tenants/{tenantId}/voting/{voteId}/statistics",
     *     summary="获取投票统计",
     *     tags={"Voting 投票"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(name="tenantId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="voteId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="统计数据", @OA\JsonContent(
     *         @OA\Property(property="data", type="object",
     *             @OA\Property(property="total_votes", type="integer"),
     *             @OA\Property(property="today_votes", type="integer"),
     *             @OA\Property(property="options", type="array"),
     *             @OA\Property(property="daily_stats", type="array")
     *         )
     *     ))
     * )
     */
    public function statistics(Request $request, int $tenantId, int $voteId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保投票属于当前租户
        Vote::where('vote_id', $voteId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $stats = $this->votingService->getStatistics($voteId);

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function records(Request $request, int $tenantId, int $voteId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保投票属于当前租户
        Vote::where('vote_id', $voteId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $filters = array_filter([
            'user_id' => $request->query('user_id'),
            'option_id' => $request->query('option_id'),
        ]);

        $records = $this->votingService->getRecords($voteId, $filters, $request->query('per_page'));

        return response()->json(['success' => true, 'data' => $records]);
    }
}
