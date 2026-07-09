<?php

namespace MultiTenantSaas\Modules\Voting\Services;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Voting\Models\Vote;
use MultiTenantSaas\Modules\Voting\Models\VoteOption;
use MultiTenantSaas\Modules\Voting\Models\VoteRecord;

/**
 * 投票系统服务
 *
 * 排行榜、防刷票、多类型投票。
 *
 * 特性:
 * - 单选/多选投票
 * - 排行榜实时更新
 * - 防刷票机制（IP/用户/租户/指纹）
 * - 投票项管理（图片、描述）
 * - 每日/总投票次数限制
 * - 实时统计
 * - 租户隔离
 */
class VotingService
{
    /**
     * 创建投票
     */
    public function createVote(array $data, int $tenantId): Vote
    {
        return DB::transaction(function () use ($data, $tenantId) {
            $vote = Vote::create([
                'tenant_id' => $tenantId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'vote_type' => $data['vote_type'] ?? 'single',
                'status' => $data['status'] ?? 'draft',
                'start_at' => $data['start_at'] ?? null,
                'end_at' => $data['end_at'] ?? null,
                'daily_limit' => $data['daily_limit'] ?? 0,
                'total_limit' => $data['total_limit'] ?? 0,
                'daily_limit_per_user' => $data['daily_limit_per_user'] ?? 1,
                'total_limit_per_user' => $data['total_limit_per_user'] ?? 0,
                'anti_cheat_ip' => $data['anti_cheat_ip'] ?? true,
                'show_result' => $data['show_result'] ?? true,
                'show_rank' => $data['show_rank'] ?? true,
                'metadata' => $data['metadata'] ?? null,
            ]);

            if (!empty($data['options'])) {
                $this->saveOptions($vote->getKey(), $data['options']);
            }

            return $vote;
        });
    }

    /**
     * 更新投票
     */
    public function updateVote(Vote $vote, array $data): Vote
    {
        $vote->update([
            'title' => $data['title'] ?? $vote->title,
            'description' => $data['description'] ?? $vote->description,
            'vote_type' => $data['vote_type'] ?? $vote->vote_type,
            'status' => $data['status'] ?? $vote->status,
            'start_at' => $data['start_at'] ?? $vote->start_at,
            'end_at' => $data['end_at'] ?? $vote->end_at,
            'daily_limit' => $data['daily_limit'] ?? $vote->daily_limit,
            'total_limit' => $data['total_limit'] ?? $vote->total_limit,
            'daily_limit_per_user' => $data['daily_limit_per_user'] ?? $vote->daily_limit_per_user,
            'total_limit_per_user' => $data['total_limit_per_user'] ?? $vote->total_limit_per_user,
            'anti_cheat_ip' => $data['anti_cheat_ip'] ?? $vote->anti_cheat_ip,
            'show_result' => $data['show_result'] ?? $vote->show_result,
            'show_rank' => $data['show_rank'] ?? $vote->show_rank,
            'metadata' => $data['metadata'] ?? $vote->metadata,
        ]);

        if (isset($data['options'])) {
            VoteOption::where('vote_id', $vote->getKey())->delete();
            $this->saveOptions($vote->getKey(), $data['options']);
        }

        return $vote->fresh(['options']);
    }

    /**
     * 投票
     *
     * @param  int     $voteId      投票ID
     * @param  array   $optionIds   选项ID列表
     * @param  int     $userId      用户ID
     * @param  int     $tenantId    租户ID
     * @param  string|null $ipAddress    IP地址
     * @param  string|null $userAgent    用户代理
     * @param  string|null $fingerprint  设备指纹（防刷票）
     */
    public function castVote(int $voteId, array $optionIds, int $userId, int $tenantId, ?string $ipAddress = null, ?string $userAgent = null, ?string $fingerprint = null): Collection
    {
        return DB::transaction(function () use ($voteId, $optionIds, $userId, $tenantId, $ipAddress, $userAgent, $fingerprint) {
            $vote = Vote::with('options')->findOrFail($voteId);

            $this->validateVote($vote, $optionIds, $userId, $tenantId, $ipAddress, $fingerprint);

            $records = collect();
            foreach ($optionIds as $optionId) {
                $option = VoteOption::where('vote_id', $voteId)->findOrFail($optionId);

                $record = VoteRecord::create([
                    'vote_id' => $voteId,
                    'vote_option_id' => $optionId,
                    'user_id' => $userId,
                    'tenant_id' => $tenantId,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'fingerprint' => $fingerprint,
                ]);

                $option->increment('vote_count');
                $vote->increment('total_votes');

                $records->push($record);
            }

            // 清除统计缓存
            $this->clearStatsCache($voteId);

            // 记录审计日志
            try {
                AuditService::log('voting.cast', 'vote', $voteId, null, [
                    'user_id' => $userId,
                    'option_ids' => $optionIds,
                ]);
            } catch (\Throwable $e) {
                // 忽略
            }

            return $records;
        });
    }

    /**
     * 校验投票资格
     */
    protected function validateVote(Vote $vote, array $optionIds, int $userId, int $tenantId, ?string $ipAddress, ?string $fingerprint = null): void
    {
        if ($vote->status !== 'active') {
            throw new \RuntimeException(trans('voting.vote_not_active'));
        }

        if (Carbon::parse($vote->start_at)->isFuture()) {
            throw new \RuntimeException(trans('voting.vote_not_started'));
        }

        if (Carbon::parse($vote->end_at)->isPast()) {
            throw new \RuntimeException(trans('voting.vote_ended'));
        }

        // 单选校验
        if ($vote->vote_type === 'single' && count($optionIds) > 1) {
            throw new \RuntimeException(trans('voting.vote_single_only'));
        }

        // 总投票次数限制
        if ($vote->total_limit > 0) {
            $totalVotes = VoteRecord::where('vote_id', $vote->getKey())->count();
            if ($totalVotes >= $vote->total_limit) {
                throw new \RuntimeException(trans('voting.vote_total_limit'));
            }
        }

        // 每日投票次数限制
        if ($vote->daily_limit > 0) {
            $todayVotes = VoteRecord::where('vote_id', $vote->getKey())
                ->whereDate('created_at', today())
                ->count();
            if ($todayVotes >= $vote->daily_limit) {
                throw new \RuntimeException(trans('voting.vote_daily_limit'));
            }
        }

        // 用户每日限制
        if ($vote->daily_limit_per_user > 0) {
            $userTodayVotes = VoteRecord::where('vote_id', $vote->getKey())
                ->where('user_id', $userId)
                ->whereDate('created_at', today())
                ->count();
            if ($userTodayVotes >= $vote->daily_limit_per_user) {
                throw new \RuntimeException(trans('voting.vote_user_daily_limit'));
            }
        }

        // 用户总限制
        if ($vote->total_limit_per_user > 0) {
            $userTotalVotes = VoteRecord::where('vote_id', $vote->getKey())
                ->where('user_id', $userId)
                ->count();
            if ($userTotalVotes >= $vote->total_limit_per_user) {
                throw new \RuntimeException(trans('voting.vote_user_total_limit'));
            }
        }

        // IP 防刷票
        if ($vote->anti_cheat_ip && $ipAddress) {
            $ipRecent = VoteRecord::where('vote_id', $vote->getKey())
                ->where('ip_address', $ipAddress)
                ->where('created_at', '>=', now()->subSeconds(10))
                ->count();
            if ($ipRecent >= 10) {
                throw new \RuntimeException(trans('voting.vote_ip_limit'));
            }
        }

        // 设备指纹防刷票
        if ($fingerprint) {
            $fingerprintRecent = VoteRecord::where('vote_id', $vote->getKey())
                ->where('fingerprint', $fingerprint)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->count();
            if ($fingerprintRecent >= 5) {
                throw new \RuntimeException(trans('voting.vote_fingerprint_limit'));
            }
        }

        // 检查选项是否属于该投票
        $validOptionIds = $vote->options->pluck('vote_option_id')->toArray();
        foreach ($optionIds as $optionId) {
            if (!in_array((int) $optionId, $validOptionIds)) {
                throw new \RuntimeException(trans('voting.vote_invalid_option'));
            }
        }
    }

    /**
     * 保存投票选项
     */
    protected function saveOptions(int $voteId, array $options): void
    {
        foreach ($options as $index => $option) {
            VoteOption::create([
                'vote_id' => $voteId,
                'title' => $option['title'],
                'image' => $option['image'] ?? null,
                'description' => $option['description'] ?? null,
                'sort_order' => $option['sort_order'] ?? $index,
                'metadata' => $option['metadata'] ?? null,
            ]);
        }
    }

    /**
     * 获取排行榜
     */
    public function getRanking(int $voteId): array
    {
        $vote = Vote::with('options')->findOrFail($voteId);

        $ranked = $vote->options->sortByDesc('vote_count')->values()->map(function ($option, $index) {
            return [
                'rank' => $index + 1,
                'option_id' => $option->getKey(),
                'title' => $option->title,
                'image' => $option->image,
                'vote_count' => $option->vote_count,
                'percentage' => $option->percentage,
            ];
        });

        $totalVotes = $vote->total_votes;

        return [
            'vote_id' => $voteId,
            'title' => $vote->title,
            'total_votes' => $totalVotes,
            'ranking' => $ranked->toArray(),
        ];
    }

    /**
     * 获取投票统计（带缓存）
     */
    public function getStatistics(int $voteId): array
    {
        $cacheKey = "voting:stats:{$voteId}";

        return Cache::remember($cacheKey, 60, function () use ($voteId) {
            $vote = Vote::with('options')->findOrFail($voteId);

            $dailyStats = VoteRecord::where('vote_id', $voteId)
                ->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $options = $vote->options->map(function ($option) {
                return [
                    'option_id' => $option->getKey(),
                    'title' => $option->title,
                    'vote_count' => $option->vote_count,
                    'percentage' => $option->percentage,
                ];
            });

            return [
                'vote_id' => $voteId,
                'title' => $vote->title,
                'total_votes' => $vote->total_votes,
                'today_votes' => VoteRecord::where('vote_id', $voteId)->whereDate('created_at', today())->count(),
                'options' => $options->toArray(),
                'daily_stats' => $dailyStats->toArray(),
            ];
        });
    }

    /**
     * 清除投票统计缓存
     */
    public function clearStatsCache(int $voteId): void
    {
        Cache::forget("voting:stats:{$voteId}");
    }

    /**
     * 查询投票记录
     */
    public function getRecords(int $voteId, array $filters = [], ?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = VoteRecord::where('vote_id', $voteId)->with('option');

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['option_id'])) {
            $query->where('vote_option_id', $filters['option_id']);
        }

        $query->orderByDesc('created_at');

        return $perPage !== null ? $query->paginate($perPage) : $query->get();
    }
}