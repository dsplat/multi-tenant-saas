<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\Lottery\LotteryActivity;
use MultiTenantSaas\Models\Lottery\LotteryActivityPrize;
use MultiTenantSaas\Models\Lottery\LotteryBlacklist;
use MultiTenantSaas\Models\Lottery\LotteryDrawLog;

/**
 * 抽奖服务
 *
 * 功能：活动管理、奖品管理、抽奖执行、黑名单管理、统计查询
 */
class LotteryService
{
    // ========================================
    // 活动管理
    // ========================================

    /**
     * 创建抽奖活动
     */
    public static function createActivity(array $data): LotteryActivity
    {
        return LotteryActivity::create($data);
    }

    /**
     * 更新抽奖活动
     */
    public static function updateActivity(int $activityId, array $data): LotteryActivity
    {
        $activity = LotteryActivity::findOrFail($activityId);
        $activity->update($data);

        return $activity->fresh();
    }

    /**
     * 获取活动详情（含奖品列表）
     */
    public static function getActivity(int $activityId): LotteryActivity
    {
        return LotteryActivity::with('prizes')->findOrFail($activityId);
    }

    /**
     * 获取租户活动列表
     */
    public static function getActivities(int $tenantId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = LotteryActivity::where('tenant_id', $tenantId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 更新活动状态
     */
    public static function updateActivityStatus(int $activityId, string $status): LotteryActivity
    {
        $activity = LotteryActivity::findOrFail($activityId);
        $activity->update(['status' => $status]);

        return $activity->fresh();
    }

    // ========================================
    // 奖品管理
    // ========================================

    /**
     * 添加奖品
     */
    public static function addPrize(int $activityId, array $data): LotteryActivityPrize
    {
        $activity = LotteryActivity::findOrFail($activityId);

        $data['tenant_id'] = $activity->tenant_id;
        $data['activity_id'] = $activityId;
        $data['remaining_count'] = $data['remaining_count'] ?? ($data['total_count'] ?? 0);

        return LotteryActivityPrize::create($data);
    }

    /**
     * 更新奖品
     */
    public static function updatePrize(int $prizeId, array $data): LotteryActivityPrize
    {
        $prize = LotteryActivityPrize::findOrFail($prizeId);
        $prize->update($data);

        return $prize->fresh();
    }

    /**
     * 删除奖品
     */
    public static function deletePrize(int $prizeId): bool
    {
        $prize = LotteryActivityPrize::findOrFail($prizeId);

        return $prize->delete();
    }

    /**
     * 获取活动奖品列表
     */
    public static function getPrizes(int $activityId): \Illuminate\Database\Eloquent\Collection
    {
        return LotteryActivityPrize::where('activity_id', $activityId)
            ->orderBy('sort_order')
            ->orderBy('prize_id')
            ->get();
    }

    // ========================================
    // 抽奖执行
    // ========================================

    /**
     * 执行抽奖
     *
     * @return array{result: string, prize: ?LotteryActivityPrize, log: LotteryDrawLog}
     */
    public static function draw(int $activityId, ?int $userId = null, ?string $userIp = null, ?string $userAgent = null): array
    {
        $activity = LotteryActivity::findOrFail($activityId);

        // 检查活动状态
        if ($activity->status !== 'active') {
            throw new \RuntimeException('活动未开始或已结束');
        }

        // 检查时间范围
        $now = now();
        if ($activity->start_at && $now->lt($activity->start_at)) {
            throw new \RuntimeException('活动尚未开始');
        }
        if ($activity->end_at && $now->gt($activity->end_at)) {
            throw new \RuntimeException('活动已结束');
        }

        // 检查黑名单
        if ($userId && static::isBlacklisted($activityId, 'user_id', (string) $userId)) {
            $log = static::recordDraw($activityId, null, $userId, $userIp, $userAgent, 'blacklist');

            return ['result' => 'blacklist', 'prize' => null, 'log' => $log];
        }
        if ($userIp && static::isBlacklisted($activityId, 'ip', $userIp)) {
            $log = static::recordDraw($activityId, null, $userId, $userIp, $userAgent, 'blacklist');

            return ['result' => 'blacklist', 'prize' => null, 'log' => $log];
        }

        // 检查用户抽奖次数限制
        $rules = $activity->rules ?? [];
        $maxPerUser = $rules['max_per_user'] ?? 0;
        if ($maxPerUser > 0 && $userId) {
            $drawCount = LotteryDrawLog::where('activity_id', $activityId)
                ->where('user_id', $userId)
                ->count();
            if ($drawCount >= $maxPerUser) {
                $log = static::recordDraw($activityId, null, $userId, $userIp, $userAgent, 'miss');

                return ['result' => 'miss', 'prize' => null, 'log' => $log];
            }
        }

        // 尝试抽奖
        $prize = static::tryDrawPrize($activityId);

        if ($prize) {
            $log = static::recordDraw($activityId, $prize->prize_id, $userId, $userIp, $userAgent, 'win');

            return ['result' => 'win', 'prize' => $prize, 'log' => $log];
        }

        $log = static::recordDraw($activityId, null, $userId, $userIp, $userAgent, 'miss');

        return ['result' => 'miss', 'prize' => null, 'log' => $log];
    }

    /**
     * 尝试抽取奖品（加权随机 + 乐观锁）
     */
    protected static function tryDrawPrize(int $activityId): ?LotteryActivityPrize
    {
        $prizes = LotteryActivityPrize::where('activity_id', $activityId)
            ->where('remaining_count', '>', 0)
            ->where('weight', '>', 0)
            ->orderBy('sort_order')
            ->get();

        if ($prizes->isEmpty()) {
            return null;
        }

        // 加权随机选择
        $totalWeight = $prizes->sum('weight');
        $random = random_int(1, $totalWeight);
        $cumulative = 0;

        foreach ($prizes as $prize) {
            $cumulative += $prize->weight;
            if ($random <= $cumulative) {
                // 乐观锁扣减库存
                $affected = LotteryActivityPrize::where('prize_id', $prize->prize_id)
                    ->where('remaining_count', '>', 0)
                    ->decrement('remaining_count', 1);

                if ($affected > 0) {
                    return $prize->fresh();
                }

                // 库存不足，重新尝试
                return static::tryDrawPrize($activityId);
            }
        }

        return null;
    }

    /**
     * 记录抽奖日志
     */
    protected static function recordDraw(int $activityId, ?int $prizeId, ?int $userId, ?string $userIp, ?string $userAgent, string $result): LotteryDrawLog
    {
        return LotteryDrawLog::create([
            'activity_id' => $activityId,
            'prize_id' => $prizeId,
            'user_id' => $userId,
            'user_ip' => $userIp,
            'user_agent' => $userAgent,
            'result' => $result,
            'draw_at' => now(),
        ]);
    }

    // ========================================
    // 黑名单管理
    // ========================================

    /**
     * 添加黑名单
     */
    public static function addToBlacklist(int $activityId, string $identifierType, string $identifier, ?string $reason = null): LotteryBlacklist
    {
        return LotteryBlacklist::create([
            'activity_id' => $activityId,
            'identifier_type' => $identifierType,
            'identifier' => $identifier,
            'reason' => $reason,
        ]);
    }

    /**
     * 移除黑名单
     */
    public static function removeFromBlacklist(int $activityId, string $identifierType, string $identifier): bool
    {
        return LotteryBlacklist::where('activity_id', $activityId)
            ->where('identifier_type', $identifierType)
            ->where('identifier', $identifier)
            ->delete() > 0;
    }

    /**
     * 检查是否在黑名单中
     */
    public static function isBlacklisted(int $activityId, string $identifierType, string $identifier): bool
    {
        return LotteryBlacklist::where('activity_id', $activityId)
            ->where('identifier_type', $identifierType)
            ->where('identifier', $identifier)
            ->exists();
    }

    /**
     * 获取活动黑名单
     */
    public static function getBlacklist(int $activityId): \Illuminate\Database\Eloquent\Collection
    {
        return LotteryBlacklist::where('activity_id', $activityId)->get();
    }

    // ========================================
    // 统计查询
    // ========================================

    /**
     * 获取活动抽奖统计
     */
    public static function getDrawStats(int $activityId): array
    {
        $total = LotteryDrawLog::where('activity_id', $activityId)->count();
        $wins = LotteryDrawLog::where('activity_id', $activityId)->where('result', 'win')->count();
        $misses = LotteryDrawLog::where('activity_id', $activityId)->where('result', 'miss')->count();
        $blacklisted = LotteryDrawLog::where('activity_id', $activityId)->where('result', 'blacklist')->count();

        return [
            'total_draws' => $total,
            'wins' => $wins,
            'misses' => $misses,
            'blacklisted' => $blacklisted,
            'win_rate' => $total > 0 ? round($wins / $total * 100, 2) : 0,
        ];
    }

    /**
     * 获取用户抽奖记录
     */
    public static function getUserDrawLogs(int $activityId, int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return LotteryDrawLog::where('activity_id', $activityId)
            ->where('user_id', $userId)
            ->orderByDesc('draw_at')
            ->get();
    }

    /**
     * 获取中奖记录列表
     */
    public static function getWinLogs(int $activityId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return LotteryDrawLog::where('activity_id', $activityId)
            ->where('result', 'win')
            ->with('prize')
            ->orderByDesc('draw_at')
            ->limit($limit)
            ->get();
    }
}
