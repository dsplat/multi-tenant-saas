<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\PaymentOrder;

/**
 * 支付安全服务
 *
 * 提供支付密码、支付限额、支付风控、支付日志能力。
 *
 * 配置：config('pay.security.*')
 *
 * 租户隔离：所有检查均按 tenant_id 进行。
 */
class PaymentSecurityService
{
    /**
     * 设置支付密码
     *
     * @param  int  $userId  用户 ID
     * @param  string  $password  明文密码
     *
     * @throws \RuntimeException 支付密码功能未启用
     */
    public function setPaymentPassword(int $userId, string $password): void
    {
        if (! config('pay.security.payment_password_enabled', false)) {
            throw new \RuntimeException(trans('payment.password_feature_disabled'));
        }

        if (strlen($password) < 6) {
            throw new \RuntimeException(trans('payment.password_too_short'));
        }

        $tenantId = (int) (TenantContext::getId() ?? 0);
        $hash = Hash::make($password);

        DB::table('user_payment_passwords')->updateOrInsert(
            ['user_id' => $userId, 'tenant_id' => $tenantId],
            [
                'password_hash' => $hash,
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]
        );
    }

    /**
     * 验证支付密码
     *
     * @param  int  $userId  用户 ID
     * @param  string  $password  明文密码
     */
    public function verifyPaymentPassword(int $userId, string $password): bool
    {
        if (! config('pay.security.payment_password_enabled', false)) {
            return true;
        }

        $tenantId = (int) (TenantContext::getId() ?? 0);

        $record = DB::table('user_payment_passwords')
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $record) {
            return false;
        }

        return Hash::check($password, $record->password_hash);
    }

    /**
     * 检查单笔支付限额
     *
     * @param  float  $amount  待支付金额
     * @return bool true 表示未超限
     */
    public function checkPerPaymentLimit(float $amount): bool
    {
        $limit = (float) config('pay.security.per_payment_limit', 0);

        if ($limit <= 0) {
            return true;
        }

        return $amount <= $limit;
    }

    /**
     * 检查日累计支付限额
     *
     * @param  int  $userId  用户 ID
     * @param  float  $amount  本次待支付金额
     * @return bool true 表示未超限
     */
    public function checkDailyLimit(int $userId, float $amount): bool
    {
        $limit = (float) config('pay.security.daily_payment_limit', 0);

        if ($limit <= 0) {
            return true;
        }

        $tenantId = (int) (TenantContext::getId() ?? 0);

        $todaySum = PaymentOrder::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['paid', 'completed'])
            ->whereDate('created_at', today())
            ->sum('amount');

        return ($todaySum + $amount) <= $limit;
    }

    /**
     * 支付风控检查：失败次数 / 频率
     *
     * @param  int  $userId  用户 ID
     * @return array{allowed: bool, reason: string|null, retry_after_sec: int}
     */
    public function checkRisk(int $userId): array
    {
        $threshold = (int) config('pay.security.risk_failure_threshold', 5);
        $cooldown = (int) config('pay.security.risk_cooldown_sec', 1800);

        $cacheKey = "payment:risk:blocked:{$userId}";
        $blockedAt = Cache::get($cacheKey);

        if ($blockedAt) {
            $remaining = $cooldown - (now()->timestamp - $blockedAt);

            return [
                'allowed' => false,
                'reason' => trans('payment.risk_blocked'),
                'retry_after_sec' => max(0, $remaining),
            ];
        }

        // 检查最近 1 小时失败次数
        $tenantId = (int) (TenantContext::getId() ?? 0);
        $failureCount = PaymentOrder::where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($failureCount >= $threshold) {
            Cache::put($cacheKey, now()->timestamp, $cooldown);

            $this->logSecurityEvent($userId, 'risk_blocked', [
                'failure_count' => $failureCount,
                'threshold' => $threshold,
                'cooldown_sec' => $cooldown,
            ]);

            return [
                'allowed' => false,
                'reason' => trans('payment.risk_threshold_exceeded'),
                'retry_after_sec' => $cooldown,
            ];
        }

        return ['allowed' => true, 'reason' => null, 'retry_after_sec' => 0];
    }

    /**
     * 记录支付尝试（成功/失败）
     *
     * @param  int  $userId  用户 ID
     * @param  string  $orderNo  订单号
     * @param  float  $amount  金额
     * @param  string  $status  状态
     * @param  array  $context  额外上下文
     */
    public function logPaymentAttempt(int $userId, string $orderNo, float $amount, string $status, array $context = []): void
    {
        $tenantId = (int) (TenantContext::getId() ?? 0);

        try {
            DB::table('payment_logs')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'order_no' => $orderNo,
                'amount' => $amount,
                'status' => $status,
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'ip_address' => request()?->ip(),
                'user_agent' => substr(request()?->userAgent() ?? '', 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PaymentSecurityService] log attempt failed: ' . $e->getMessage());
        }
    }

    /**
     * 记录安全事件（写入 structured_logs 与 Laravel Log）
     *
     * @param  int  $userId  用户 ID
     * @param  string  $event  事件名
     * @param  array  $context  上下文
     */
    public function logSecurityEvent(int $userId, string $event, array $context = []): void
    {
        try {
            app(StructuredLogService::class)->security(
                'payment.' . $event,
                array_merge(['user_id' => $userId], $context),
                $userId
            );
        } catch (\Throwable $e) {
            Log::warning('[PaymentSecurityService] security log failed: ' . $e->getMessage());
        }
    }

    /**
     * 支付对账：比对框架订单与网关返回金额
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $orderNo  订单号
     * @param  float  $gatewayAmount  网关返回金额
     * @return array{match: bool, framework_amount: float, gateway_amount: float}
     */
    public function reconcileOrder(int $tenantId, string $orderNo, float $gatewayAmount): array
    {
        $order = PaymentOrder::where('tenant_id', $tenantId)
            ->where('order_no', $orderNo)
            ->first();

        $frameworkAmount = $order ? (float) $order->amount : 0.0;
        $match = abs($frameworkAmount - $gatewayAmount) < 0.01;

        if (! $match) {
            $this->logSecurityEvent(0, 'reconcile_mismatch', [
                'tenant_id' => $tenantId,
                'order_no' => $orderNo,
                'framework_amount' => $frameworkAmount,
                'gateway_amount' => $gatewayAmount,
            ]);
        }

        return [
            'match' => $match,
            'framework_amount' => $frameworkAmount,
            'gateway_amount' => $gatewayAmount,
        ];
    }

    /**
     * 生成支付报表（按日聚合）
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $fromDate  起始日期（Y-m-d）
     * @param  string  $toDate  截止日期（Y-m-d）
     */
    public function dailyReport(int $tenantId, string $fromDate, string $toDate): Collection
    {
        return PaymentOrder::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$fromDate . ' 00:00:00', $toDate . ' 23:59:59'])
            ->selectRaw('DATE(created_at) as date, driver, status, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('date', 'driver', 'status')
            ->orderByDesc('date')
            ->get();
    }
}
