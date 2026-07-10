<?php

namespace MultiTenantSaas\Modules\Lottery\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Lottery\Models\LotteryActivity;
use MultiTenantSaas\Modules\Lottery\Models\LotteryActivityPrize;
use MultiTenantSaas\Modules\Lottery\Models\LotteryBlacklist;
use MultiTenantSaas\Modules\Lottery\Models\LotteryDrawLog;

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
        $activity = LotteryActivity::create($data);

        // 审计日志（如果表存在）
        try {
            AuditService::log('lottery.activity.create', 'lottery_activity', $activity->activity_id, null, $data);
        } catch (\Throwable $e) {
            // 忽略审计日志错误
        }

        return $activity;
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
    public static function getActivities(int $tenantId, array $filters = []): Collection
    {
        $query = LotteryActivity::where('tenant_id', $tenantId)
            ->withCount(['prizes', 'drawLogs']);

        if (! empty($filters['status'])) {
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
    public static function getPrizes(int $activityId): Collection
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
            throw new \RuntimeException(trans('lottery.activity_not_active'));
        }

        // 检查时间范围
        $now = now();
        if ($activity->start_at && $now->lt($activity->start_at)) {
            throw new \RuntimeException(trans('lottery.activity_not_started'));
        }
        if ($activity->end_at && $now->gt($activity->end_at)) {
            throw new \RuntimeException(trans('lottery.activity_ended'));
        }

        // 检查黑名单
        if ($userId && static::isBlacklisted($activityId, 'user_id', (string) $userId)) {
            $log = static::recordDraw($activity->tenant_id, $activityId, null, $userId, $userIp, $userAgent, 'blacklist');

            return ['result' => 'blacklist', 'prize' => null, 'log' => $log];
        }
        if ($userIp && static::isBlacklisted($activityId, 'ip', $userIp)) {
            $log = static::recordDraw($activity->tenant_id, $activityId, null, $userId, $userIp, $userAgent, 'blacklist');

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
                $log = static::recordDraw($activity->tenant_id, $activityId, null, $userId, $userIp, $userAgent, 'miss');

                return ['result' => 'miss', 'prize' => null, 'log' => $log];
            }
        }

        // 尝试抽奖
        $prize = static::tryDrawPrize($activityId);

        if ($prize) {
            $log = static::recordDraw($activity->tenant_id, $activityId, $prize->prize_id, $userId, $userIp, $userAgent, 'win');

            // 审计日志
            try {
                AuditService::log('lottery.draw.win', 'lottery_activity', $activityId, null, [
                    'prize_id' => $prize->prize_id,
                    'prize_name' => $prize->name,
                    'user_id' => $userId,
                ]);
            } catch (\Throwable $e) {
                // 忽略
            }

            return ['result' => 'win', 'prize' => $prize, 'log' => $log];
        }

        $log = static::recordDraw($activity->tenant_id, $activityId, null, $userId, $userIp, $userAgent, 'miss');

        // 审计日志
        try {
            AuditService::log('lottery.draw.miss', 'lottery_activity', $activityId, null, [
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            // 忽略
        }

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
    protected static function recordDraw(int $tenantId, int $activityId, ?int $prizeId, ?int $userId, ?string $userIp, ?string $userAgent, string $result): LotteryDrawLog
    {
        $log = LotteryDrawLog::create([
            'tenant_id' => $tenantId,
            'activity_id' => $activityId,
            'prize_id' => $prizeId,
            'user_id' => $userId,
            'user_ip' => $userIp,
            'user_agent' => $userAgent,
            'result' => $result,
            'draw_at' => now(),
        ]);

        // 清除统计缓存
        static::clearStatsCache($activityId);

        return $log;
    }

    // ========================================
    // 黑名单管理
    // ========================================

    /**
     * 添加黑名单
     */
    public static function addToBlacklist(int $tenantId, int $activityId, string $identifierType, string $identifier, ?string $reason = null): LotteryBlacklist
    {
        return LotteryBlacklist::create([
            'tenant_id' => $tenantId,
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
    public static function getBlacklist(int $activityId): Collection
    {
        return LotteryBlacklist::where('activity_id', $activityId)->get();
    }

    // ========================================
    // 统计查询
    // ========================================

    /**
     * 获取活动抽奖统计（带缓存）
     */
    public static function getDrawStats(int $activityId): array
    {
        $cacheKey = "lottery:stats:{$activityId}";

        return Cache::remember($cacheKey, 60, function () use ($activityId) {
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
        });
    }

    /**
     * 清除活动统计缓存
     */
    public static function clearStatsCache(int $activityId): void
    {
        Cache::forget("lottery:stats:{$activityId}");
    }

    /**
     * 获取用户抽奖记录
     */
    public static function getUserDrawLogs(int $activityId, int $userId): Collection
    {
        return LotteryDrawLog::where('activity_id', $activityId)
            ->where('user_id', $userId)
            ->orderByDesc('draw_at')
            ->get();
    }

    /**
     * 获取中奖记录列表
     */
    public static function getWinLogs(int $activityId, int $limit = 50): Collection
    {
        return LotteryDrawLog::where('activity_id', $activityId)
            ->where('result', 'win')
            ->with('prize')
            ->orderByDesc('draw_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 导出抽奖记录
     *
     * @return array{headers: array, rows: array, total: int}
     */
    public static function exportDrawLogs(int $activityId, array $filters = []): array
    {
        $query = LotteryDrawLog::where('activity_id', $activityId)
            ->with(['prize']);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['result'])) {
            $query->where('result', $filters['result']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('draw_at', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where('draw_at', '<=', $filters['end_date']);
        }

        $logs = $query->orderByDesc('draw_at')->get();

        $headers = [
            'log_id' => '记录ID',
            'user_id' => '用户ID',
            'prize_name' => '奖品名称',
            'result' => '结果',
            'ip_address' => 'IP地址',
            'draw_at' => '抽奖时间',
        ];

        $rows = $logs->map(function ($log) {
            return [
                'log_id' => $log->log_id,
                'user_id' => $log->user_id,
                'prize_name' => $log->prize?->name ?? '-',
                'result' => $log->result,
                'ip_address' => $log->user_ip,
                'draw_at' => $log->draw_at?->toDateTimeString(),
            ];
        })->toArray();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total' => count($rows),
        ];
    }
}
