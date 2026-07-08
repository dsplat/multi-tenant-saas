<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\Vote;
use MultiTenantSaas\Models\VoteOption;
use MultiTenantSaas\Services\VotingService;

class VotingController extends Controller
{
    use AuthorizesTenantAccess;

    public function __construct(
        private VotingService $votingService,
    ) {}

    // ========== 投票活动管理 ==========

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
