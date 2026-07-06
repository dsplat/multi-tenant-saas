<?php

namespace MultiTenantSaas\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Models\Lottery;
use MultiTenantSaas\Models\LotteryPrize;
use MultiTenantSaas\Models\LotteryRecord;

/**
 * 抽奖系统服务
 *
 * 概率控制、防刷、奖品池管理。
 *
 * 特性:
 * - 多奖品池管理
 * - 概率权重控制（千分比）
 * - 每日/总参与次数限制
 * - 防刷机制（IP/用户/租户维度）
 * - 奖品库存自动扣减
 * - 中奖记录查询
 * - 租户隔离
 */
class LotteryService
{
    /**
     * 创建抽奖活动
     */
    public function createLottery(array $data, int $tenantId): Lottery
    {
        return DB::transaction(function () use ($data, $tenantId) {
            $lottery = Lottery::create([
                'tenant_id' => $tenantId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'start_at' => $data['start_at'],
                'end_at' => $data['end_at'],
                'daily_limit' => $data['daily_limit'] ?? 0,
                'total_limit' => $data['total_limit'] ?? 0,
                'daily_limit_per_user' => $data['daily_limit_per_user'] ?? 0,
                'total_limit_per_user' => $data['total_limit_per_user'] ?? 0,
                'anti_cheat_ip' => $data['anti_cheat_ip'] ?? true,
                'prize_show_count' => $data['prize_show_count'] ?? 8,
                'metadata' => $data['metadata'] ?? null,
            ]);

            if (!empty($data['prizes'])) {
                $this->savePrizes($lottery->getKey(), $data['prizes']);
            }

            return $lottery;
        });
    }

    /**
     * 更新抽奖活动
     */
    public function updateLottery(Lottery $lottery, array $data): Lottery
    {
        $lottery->update([
            'title' => $data['title'] ?? $lottery->title,
            'description' => $data['description'] ?? $lottery->description,
            'status' => $data['status'] ?? $lottery->status,
            'start_at' => $data['start_at'] ?? $lottery->start_at,
            'end_at' => $data['end_at'] ?? $lottery->end_at,
            'daily_limit' => $data['daily_limit'] ?? $lottery->daily_limit,
            'total_limit' => $data['total_limit'] ?? $lottery->total_limit,
            'daily_limit_per_user' => $data['daily_limit_per_user'] ?? $lottery->daily_limit_per_user,
            'total_limit_per_user' => $data['total_limit_per_user'] ?? $lottery->total_limit_per_user,
            'anti_cheat_ip' => $data['anti_cheat_ip'] ?? $lottery->anti_cheat_ip,
            'prize_show_count' => $data['prize_show_count'] ?? $lottery->prize_show_count,
            'metadata' => $data['metadata'] ?? $lottery->metadata,
        ]);

        if (isset($data['prizes'])) {
            LotteryPrize::where('lottery_id', $lottery->getKey())->delete();
            $this->savePrizes($lottery->getKey(), $data['prizes']);
        }

        return $lottery->fresh(['prizes']);
    }

    /**
     * 执行抽奖
     */
    public function draw(int $lotteryId, int $userId, int $tenantId, string $ipAddress = null, string $userAgent = null): LotteryRecord
    {
        return DB::transaction(function () use ($lotteryId, $userId, $tenantId, $ipAddress, $userAgent) {
            $lottery = Lottery::with('prizes')->findOrFail($lotteryId);

            $this->validateLottery($lottery, $userId, $tenantId, $ipAddress);

            $prize = $this->rollPrize($lottery);

            $record = LotteryRecord::create([
                'lottery_id' => $lotteryId,
                'prize_id' => $prize ? $prize->getKey() : null,
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'is_winner' => $prize !== null,
                'prize_name' => $prize ? $prize->name : '谢谢参与',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            if ($prize) {
                $prize->decrement('stock');
                $lottery->increment('total_draws');
                $lottery->increment('total_wins');
            } else {
                $lottery->increment('total_draws');
            }

            return $record;
        });
    }

    /**
     * 校验抽奖资格
     */
    protected function validateLottery(Lottery $lottery, int $userId, int $tenantId, ?string $ipAddress): void
    {
        if ($lottery->status !== 'active') {
            throw new \RuntimeException('抽奖活动未开启');
        }

        if (Carbon::parse($lottery->start_at)->isFuture()) {
            throw new \RuntimeException('抽奖活动尚未开始');
        }

        if (Carbon::parse($lottery->end_at)->isPast()) {
            throw new \RuntimeException('抽奖活动已结束');
        }

        // 总参与次数限制
        if ($lottery->total_limit > 0) {
            $totalDraws = LotteryRecord::where('lottery_id', $lottery->getKey())->count();
            if ($totalDraws >= $lottery->total_limit) {
                throw new \RuntimeException('活动总参与次数已达上限');
            }
        }

        // 每日参与次数限制
        if ($lottery->daily_limit > 0) {
            $todayDraws = LotteryRecord::where('lottery_id', $lottery->getKey())
                ->whereDate('created_at', today())
                ->count();
            if ($todayDraws >= $lottery->daily_limit) {
                throw new \RuntimeException('今日参与次数已达上限');
            }
        }

        // 用户每日限制
        if ($lottery->daily_limit_per_user > 0) {
            $userTodayDraws = LotteryRecord::where('lottery_id', $lottery->getKey())
                ->where('user_id', $userId)
                ->whereDate('created_at', today())
                ->count();
            if ($userTodayDraws >= $lottery->daily_limit_per_user) {
                throw new \RuntimeException('您今日参与次数已达上限');
            }
        }

        // 用户总限制
        if ($lottery->total_limit_per_user > 0) {
            $userTotalDraws = LotteryRecord::where('lottery_id', $lottery->getKey())
                ->where('user_id', $userId)
                ->count();
            if ($userTotalDraws >= $lottery->total_limit_per_user) {
                throw new \RuntimeException('您的参与次数已达上限');
            }
        }

        // IP 防刷
        if ($lottery->anti_cheat_ip && $ipAddress) {
            $ipRecent = LotteryRecord::where('lottery_id', $lottery->getKey())
                ->where('ip_address', $ipAddress)
                ->where('created_at', '>=', now()->subSeconds(5))
                ->count();
            if ($ipRecent >= 5) {
                throw new \RuntimeException('操作过于频繁，请稍后再试');
            }
        }
    }

    /**
     * 按概率权重抽取奖品
     */
    protected function rollPrize(Lottery $lottery): ?LotteryPrize
    {
        $prizes = $lottery->prizes->filter(function ($prize) {
            return $prize->stock > 0;
        });

        if ($prizes->isEmpty()) {
            return null;
        }

        $totalWeight = $prizes->sum('probability');
        $totalWeight += $lottery->no_prize_probability ?? 0;

        if ($totalWeight <= 0) {
            return null;
        }

        $rand = random_int(1, $totalWeight);
        $current = 0;

        foreach ($prizes as $prize) {
            $current += $prize->probability;
            if ($rand <= $current) {
                return $prize;
            }
        }

        return null;
    }

    /**
     * 保存奖品配置
     */
    protected function savePrizes(int $lotteryId, array $prizes): void
    {
        foreach ($prizes as $index => $prize) {
            LotteryPrize::create([
                'lottery_id' => $lotteryId,
                'name' => $prize['name'],
                'image' => $prize['image'] ?? null,
                'prize_type' => $prize['prize_type'] ?? 'physical',
                'probability' => $prize['probability'] ?? 0,
                'stock' => $prize['stock'] ?? 0,
                'sort_order' => $prize['sort_order'] ?? $index,
                'metadata' => $prize['metadata'] ?? null,
            ]);
        }
    }

    /**
     * 查询抽奖记录
     */
    public function getRecords(int $lotteryId, array $filters = [], ?int $perPage = null)
    {
        $query = LotteryRecord::where('lottery_id', $lotteryId)->with('prize');

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (isset($filters['is_winner'])) {
            $query->where('is_winner', $filters['is_winner']);
        }

        $query->orderByDesc('created_at');

        return $perPage !== null ? $query->paginate($perPage) : $query->get();
    }

    /**
     * 中奖统计
     */
    public function getStatistics(int $lotteryId): array
    {
        $lottery = Lottery::with('prizes')->findOrFail($lotteryId);

        $totalDraws = $lottery->total_draws;
        $totalWins = $lottery->total_wins;
        $winRate = $totalDraws > 0 ? round($totalWins / $totalDraws * 100, 2) : 0;

        $prizeStats = LotteryRecord::where('lottery_id', $lotteryId)
            ->where('is_winner', true)
            ->selectRaw('prize_id, COUNT(*) as count')
            ->groupBy('prize_id')
            ->get()
            ->keyBy('prize_id');

        $prizes = $lottery->prizes->map(function ($prize) use ($prizeStats) {
            $prizeData = $prize->toArray();
            $prizeData['won_count'] = $prizeStats[$prize->getKey()]->count ?? 0;

            return $prizeData;
        });

        return [
            'total_draws' => $totalDraws,
            'total_wins' => $totalWins,
            'win_rate' => $winRate,
            'prizes' => $prizes->toArray(),
        ];
    }
}