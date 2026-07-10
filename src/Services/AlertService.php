<?php

namespace MultiTenantSaas\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;

/**
 * 告警管理服务
 *
 * 提供告警规则配置、通知发送、历史记录与升级机制。
 *
 * 告警来源：QueueService、HealthCheckService、PerformanceService 等调用 trigger()。
 * 通知通道：邮件（默认）、Webhook（Slack/钉钉/企业微信）、SMS（可选）。
 *
 * 租户隔离：alert_rules 表存储租户级规则；系统级规则 tenant_id 为 NULL。
 */
class AlertService
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_FATAL = 'fatal';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WEBHOOK = 'webhook';

    public const CHANNEL_SMS = 'sms';

    protected const TABLE_RULES = 'alert_rules';

    protected const TABLE_ALERTS = 'alerts';

    /**
     * 触发告警
     *
     * @param  string  $ruleName  规则名称
     * @param  string  $severity  级别（info/warning/critical/fatal）
     * @param  string  $message  告警消息
     * @param  array  $context  上下文数据
     * @return int 告警 ID
     */
    public function trigger(string $ruleName, string $severity, string $message, array $context = []): int
    {
        $tenantId = TenantContext::getId();

        $alertId = DB::table(self::TABLE_ALERTS)->insertGetId([
            'tenant_id' => $tenantId,
            'rule_name' => $ruleName,
            'severity' => $severity,
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'triggered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->dispatchNotifications($ruleName, $severity, $message, $context);

        // 同步写入 Laravel Log
        Log::warning("[Alert] {$ruleName} ({$severity}): {$message}", $context);

        return (int) $alertId;
    }

    /**
     * 配置告警规则
     *
     * @param  array{
     *   name: string,
     *   metric: string,
     *   operator: string,
     *   threshold: float,
     *   severity: string,
     *   channels: array<string>,
     *   cooldown_sec: int,
     *   enabled: bool
     * }  $rule  规则定义
     * @param  int|null  $tenantId  租户 ID（NULL 为系统级规则）
     * @return int 规则 ID
     *
     * @throws \RuntimeException 写入失败
     */
    public function configureRule(array $rule, ?int $tenantId = null): int
    {
        $name = $rule['name'] ?? '';
        if (empty($name)) {
            throw new \RuntimeException(trans('common.rule_name_required'));
        }

        $id = DB::table(self::TABLE_RULES)->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'metric' => $rule['metric'] ?? '',
            'operator' => $rule['operator'] ?? '>',
            'threshold' => (float) ($rule['threshold'] ?? 0),
            'severity' => $rule['severity'] ?? self::SEVERITY_WARNING,
            'channels' => json_encode($rule['channels'] ?? [self::CHANNEL_EMAIL]),
            'cooldown_sec' => (int) ($rule['cooldown_sec'] ?? 300),
            'enabled' => $rule['enabled'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AuditService::log(
            action: 'alert_rule_configured',
            resourceType: 'alert_rule',
            resourceId: $id,
            newValues: $rule
        );

        return (int) $id;
    }

    /**
     * 启用/禁用告警规则
     *
     * @param  int  $ruleId  规则 ID
     * @param  bool  $enabled  是否启用
     * @return int 受影响行数
     */
    public function toggleRule(int $ruleId, bool $enabled): int
    {
        return DB::table(self::TABLE_RULES)
            ->where('id', $ruleId)
            ->update(['enabled' => $enabled, 'updated_at' => now()]);
    }

    /**
     * 列出告警规则
     *
     * @param  int|null  $tenantId  租户 ID（NULL 时返回系统级规则）
     */
    public function listRules(?int $tenantId = null): Collection
    {
        return DB::table(self::TABLE_RULES)
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 查询告警历史
     *
     * @param  array{severity?: string, rule_name?: string, from?: string, to?: string, tenant_id?: int}  $filters
     * @param  int  $perPage  每页条数
     */
    public function history(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $q = DB::table(self::TABLE_ALERTS);

        if (! empty($filters['severity'])) {
            $q->where('severity', $filters['severity']);
        }
        if (! empty($filters['rule_name'])) {
            $q->where('rule_name', 'like', $filters['rule_name'] . '%');
        }
        if (! empty($filters['tenant_id'])) {
            $q->where('tenant_id', $filters['tenant_id']);
        } elseif ($tenantId = TenantContext::getId()) {
            $q->where('tenant_id', $tenantId);
        }
        if (! empty($filters['from'])) {
            $q->where('triggered_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->where('triggered_at', '<=', $filters['to']);
        }

        return $q->orderByDesc('triggered_at')->paginate($perPage);
    }

    /**
     * 告警升级机制：若同一规则在 cooldown 内触发 >= 3 次，自动升级 severity
     *
     * @param  string  $ruleName  规则名称
     * @param  int  $cooldownSec  时间窗口（秒）
     * @return string|null 升级后的 severity（NULL 表示未触发升级）
     */
    public function shouldEscalate(string $ruleName, int $cooldownSec = 300): ?string
    {
        $count = DB::table(self::TABLE_ALERTS)
            ->where('rule_name', $ruleName)
            ->where('triggered_at', '>=', now()->subSeconds($cooldownSec))
            ->count();

        if ($count >= 10) {
            return self::SEVERITY_FATAL;
        }
        if ($count >= 5) {
            return self::SEVERITY_CRITICAL;
        }
        if ($count >= 3) {
            return self::SEVERITY_WARNING;
        }

        return null;
    }

    /**
     * 分发告警通知（基于规则的 channels 配置）
     */
    protected function dispatchNotifications(string $ruleName, string $severity, string $message, array $context): void
    {
        $tenantId = TenantContext::getId();

        $rules = DB::table(self::TABLE_RULES)
            ->where('name', $ruleName)
            ->where('enabled', true)
            ->where(function ($q) use ($tenantId) {
                if ($tenantId) {
                    $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                } else {
                    $q->whereNull('tenant_id');
                }
            })
            ->get();

        if ($rules->isEmpty()) {
            // 无规则时默认邮件通知
            $this->sendEmail($severity, $ruleName, $message, $context);

            return;
        }

        foreach ($rules as $rule) {
            $channels = json_decode($rule->channels ?? '[]', true) ?: [self::CHANNEL_EMAIL];

            // 冷却期检查（避免短时间内重复通知）
            if ($rule->cooldown_sec > 0) {
                $cacheKey = "alert:cooldown:{$rule->id}";
                if (Cache::has($cacheKey)) {
                    continue;
                }
                Cache::put($cacheKey, 1, $rule->cooldown_sec);
            }

            foreach ($channels as $channel) {
                try {
                    match ($channel) {
                        self::CHANNEL_EMAIL => $this->sendEmail($severity, $ruleName, $message, $context),
                        self::CHANNEL_WEBHOOK => $this->sendWebhook($severity, $ruleName, $message, $context),
                        self::CHANNEL_SMS => $this->sendSms($severity, $ruleName, $message, $context),
                        default => null,
                    };
                } catch (\Throwable $e) {
                    Log::error('[AlertService] notify failed', [
                        'channel' => $channel,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 邮件通知（暂用 Log 占位，避免依赖 mail driver）
     */
    protected function sendEmail(string $severity, string $ruleName, string $message, array $context): void
    {
        Log::info("[AlertEmail] [{$severity}] {$ruleName}: {$message}", $context);
    }

    /**
     * Webhook 通知（Slack/钉钉/企业微信）
     */
    protected function sendWebhook(string $severity, string $ruleName, string $message, array $context): void
    {
        $webhookUrl = config('alert.webhook_url');
        if (empty($webhookUrl)) {
            return;
        }

        try {
            Http::timeout(5)->post($webhookUrl, [
                'text' => "[{$severity}] {$ruleName}: {$message}",
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AlertService] webhook failed: ' . $e->getMessage());
        }
    }

    /**
     * SMS 通知
     */
    protected function sendSms(string $severity, string $ruleName, string $message, array $context): void
    {
        if (! class_exists(SmsService::class)) {
            return;
        }

        $phone = config('alert.sms_phone');
        if (empty($phone)) {
            return;
        }

        try {
            app(SmsService::class)->send($phone, "[{$severity}] {$ruleName}: {$message}");
        } catch (\Throwable $e) {
            Log::warning('[AlertService] sms failed: ' . $e->getMessage());
        }
    }
}
