<?php

namespace MultiTenantSaas\Services;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\CreditTransaction;

/**
 * 租户积分与财务管理服务
 * 用于 Console 后台的积分管理和财务查询
 */
class TenantCreditService
{
    /**
     * 获取租户积分账户信息
     *
     * @param  int  $tenantId  租户ID
     */
    public function getAccountInfo(int $tenantId): array
    {
        // 获取企业账户
        $enterpriseAccount = CreditAccount::where('tenant_id', $tenantId)
            ->where('account_type', 'enterprise')
            ->first();

        if (! $enterpriseAccount) {
            // 如果不存在则创建
            $enterpriseAccount = CreditAccount::create([
                'tenant_id' => $tenantId,
                'user_id' => null,
                'account_type' => 'enterprise',
                'balance' => 0,
                'total_recharged' => 0,
                'total_consumed' => 0,
            ]);
        }

        // 获取本月统计
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $monthRecharge = CreditTransaction::where('account_id', $enterpriseAccount->credit_account_id)
            ->where('type', 'recharge')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('amount');

        $monthConsume = CreditTransaction::where('account_id', $enterpriseAccount->credit_account_id)
            ->where('type', 'consume')
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum(DB::raw('ABS(amount)'));

        // 获取今日统计
        $todayStart = Carbon::now()->startOfDay();
        $todayEnd = Carbon::now()->endOfDay();

        $todayConsume = CreditTransaction::where('account_id', $enterpriseAccount->credit_account_id)
            ->where('type', 'consume')
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum(DB::raw('ABS(amount)'));

        return [
            'account' => $enterpriseAccount,
            'balance' => $enterpriseAccount->balance,
            'total_recharged' => $enterpriseAccount->total_recharged,
            'total_consumed' => $enterpriseAccount->total_consumed,
            'month_recharge' => $monthRecharge,
            'month_consume' => $monthConsume,
            'today_consume' => $todayConsume,
        ];
    }

    /**
     * 获取充值记录
     *
     * @param  int  $tenantId  租户ID
     * @param  array  $options  选项 ['start_date' => date, 'end_date' => date, 'perPage' => int]
     */
    public function getRechargeRecords(int $tenantId, array $options = []): LengthAwarePaginator
    {
        $account = CreditAccount::where('tenant_id', $tenantId)
            ->where('account_type', 'enterprise')
            ->first();

        if (! $account) {
            return new LengthAwarePaginator([], 0, $options['perPage'] ?? 15);
        }

        $query = CreditTransaction::where('account_id', $account->credit_account_id)
            ->where('type', 'recharge');

        // 日期筛选
        if (! empty($options['start_date'])) {
            $query->whereDate('created_at', '>=', $options['start_date']);
        }

        if (! empty($options['end_date'])) {
            $query->whereDate('created_at', '<=', $options['end_date']);
        }

        $perPage = $options['perPage'] ?? 15;

        return $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * 获取消费记录
     *
     * @param  int  $tenantId  租户ID
     * @param  array  $options  选项 ['user_id' => int, 'start_date' => date, 'end_date' => date, 'perPage' => int]
     */
    public function getConsumeRecords(int $tenantId, array $options = []): LengthAwarePaginator
    {
        $account = CreditAccount::where('tenant_id', $tenantId)
            ->where('account_type', 'enterprise')
            ->first();

        if (! $account) {
            return new LengthAwarePaginator([], 0, $options['perPage'] ?? 15);
        }

        $query = CreditTransaction::where('account_id', $account->credit_account_id)
            ->where('type', 'consume')
            ->with(['user:user_id,name,email']);

        // 按用户筛选
        if (! empty($options['user_id'])) {
            $query->where('user_id', $options['user_id']);
        }

        // 日期筛选
        if (! empty($options['start_date'])) {
            $query->whereDate('created_at', '>=', $options['start_date']);
        }

        if (! empty($options['end_date'])) {
            $query->whereDate('created_at', '<=', $options['end_date']);
        }

        $perPage = $options['perPage'] ?? 15;

        return $query->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * 充值积分
     *
     * @param  int  $tenantId  租户ID
     * @param  int  $userId  用户ID（操作人）
     * @param  int  $amount  充值金额
     * @param  string  $payment_method  支付方式
     * @param  string|null  $description  描述
     * @return array ['success' => bool, 'message' => string, 'transaction' => CreditTransaction|null]
     */
    public function recharge(int $tenantId, int $userId, int $amount, string $payment_method, ?string $description = null): array
    {
        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => trans('credit.invalid_amount'),
            ];
        }

        DB::beginTransaction();
        try {
            $account = CreditAccount::where('tenant_id', $tenantId)
                ->where('account_type', 'enterprise')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                $account = CreditAccount::create([
                    'tenant_id' => $tenantId,
                    'user_id' => null,
                    'account_type' => 'enterprise',
                    'balance' => 0,
                    'total_recharged' => 0,
                    'total_consumed' => 0,
                ]);
            }

            $transaction = $account->recharge($userId, $amount, $description, [
                'payment_method' => $payment_method,
                'payment_time' => now()->toDateTimeString(),
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => trans('credit.recharge_success'),
                'transaction' => $transaction,
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'success' => false,
                'message' => trans('credit.recharge_failed') . ': ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 获取消费趋势数据（最近30天）
     *
     * @param  int  $tenantId  租户ID
     */
    public function getConsumeTrend(int $tenantId): array
    {
        $account = CreditAccount::where('tenant_id', $tenantId)
            ->where('account_type', 'enterprise')
            ->first();

        if (! $account) {
            return [];
        }

        $startDate = Carbon::now()->subDays(30)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $transactions = CreditTransaction::where('account_id', $account->credit_account_id)
            ->where('type', 'consume')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, SUM(ABS(amount)) as total')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        $trend = [];
        $currentDate = $startDate->copy();

        while ($currentDate <= $endDate) {
            $dateStr = $currentDate->format('Y-m-d');
            $transaction = $transactions->firstWhere('date', $dateStr);

            $trend[] = [
                'date' => $dateStr,
                'amount' => $transaction ? $transaction->total : 0,
            ];

            $currentDate->addDay();
        }

        return $trend;
    }

    /**
     * 获取成员消费统计（Top 10）
     *
     * @param  int  $tenantId  租户ID
     * @param  string  $period  时间范围 (today, week, month, all)
     */
    public function getMemberConsumeStats(int $tenantId, string $period = 'month'): array
    {
        $account = CreditAccount::where('tenant_id', $tenantId)
            ->where('account_type', 'enterprise')
            ->first();

        if (! $account) {
            return [];
        }

        $query = CreditTransaction::where('account_id', $account->credit_account_id)
            ->where('type', 'consume')
            ->whereNotNull('user_id')
            ->with(['user:user_id,name,email']);

        // 根据时间范围筛选
        switch ($period) {
            case 'today':
                $query->whereDate('created_at', Carbon::today());
                break;
            case 'week':
                $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
                break;
            case 'month':
                $query->whereBetween('created_at', [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()]);
                break;
            case 'all':
            default:
                // 不限制时间
                break;
        }

        $stats = $query->selectRaw('user_id, SUM(ABS(amount)) as total_consume, COUNT(*) as transaction_count')
            ->groupBy('user_id')
            ->orderBy('total_consume', 'desc')
            ->limit(10)
            ->get();

        return $stats->map(function ($stat) {
            return [
                'user_id' => $stat->user_id,
                'user_name' => $stat->user->name ?? '未知用户',
                'user_email' => $stat->user->email ?? '',
                'total_consume' => $stat->total_consume,
                'transaction_count' => $stat->transaction_count,
            ];
        })->toArray();
    }

    /**
     * 获取余额预警信息
     *
     * @param  int  $tenantId  租户ID
     * @param  int  $threshold  预警阈值（默认10000积分）
     */
    public function getBalanceAlert(int $tenantId, int $threshold = 10000): array
    {
        $account = CreditAccount::where('tenant_id', $tenantId)
            ->where('account_type', 'enterprise')
            ->first();

        if (! $account) {
            return [
                'alert' => false,
                'balance' => 0,
                'threshold' => $threshold,
            ];
        }

        $alert = $account->balance < $threshold;

        // 如果余额告警，计算可用天数
        $avgDailyConsume = 0;
        $availableDays = 0;

        if ($alert) {
            // 计算最近7天的平均日消费
            $startDate = Carbon::now()->subDays(7)->startOfDay();
            $endDate = Carbon::now()->endOfDay();

            $recentConsume = CreditTransaction::where('account_id', $account->credit_account_id)
                ->where('type', 'consume')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum(DB::raw('ABS(amount)'));

            $avgDailyConsume = $recentConsume / 7;
            $availableDays = $avgDailyConsume > 0 ? floor($account->balance / $avgDailyConsume) : 999;
        }

        return [
            'alert' => $alert,
            'balance' => $account->balance,
            'threshold' => $threshold,
            'avg_daily_consume' => $avgDailyConsume,
            'available_days' => $availableDays,
        ];
    }
}
