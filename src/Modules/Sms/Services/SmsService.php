<?php

namespace MultiTenantSaas\Modules\Sms\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Sms\Models\SmsBatchTask;
use MultiTenantSaas\Modules\Sms\Models\SmsDeliveryStat;
use MultiTenantSaas\Modules\Sms\Models\SmsTemplate;
use MultiTenantSaas\Modules\Sms\Models\SmsUnsubscribe;

/**
 * 短信发送服务
 *
 * driver=log → 仅写日志（本地/测试默认）
 * driver=ww  → 调用网建短信网关
 * driver=http→ 通用 HTTP 短信网关（自定义 endpoint）
 *
 * 扩展功能：模板管理、批量发送、到达率统计、退订管理
 */
class SmsService
{
    /**
     * 发送验证码短信，成功返回传入的 $code，失败返回 false。
     */
    public static function send(string $phone, string $code, string $type = 'register'): string|false
    {
        $driver = config('services.sms.driver', 'log');

        return static::sendUsingDriver($driver, $phone, $code, $type);
    }

    public static function sendUsingDriver(string $driver, string $phone, string $code, string $type = 'register'): string|false
    {
        $driver = trim($driver);

        return match ($driver) {
            'ww' => static::sendViaWw($phone, $code, $type),
            'http' => static::sendViaHttp($phone, $code, $type),
            default => static::sendViaLog($phone, $code, $type),
        };
    }

    // ----------------------------------------
    // Private drivers
    // ----------------------------------------

    private static function sendViaWw(string $phone, string $code, string $type): string|false
    {
        $endpoint = (string) config('services.sms.ww_endpoint');
        $account = (string) config('services.sms.ww_account');
        $password = (string) config('services.sms.ww_password');
        $corpid = (string) config('services.sms.ww_corpid');
        $productId = (string) config('services.sms.ww_product_id');
        $sign = (string) config('services.sms.ww_sign', 'YourApp');
        $smsg = '【' . $sign . "】您的验证码是{$code}，5分钟内有效，请勿泄露。";

        if ($endpoint === '' || $account === '' || $password === '' || $productId === '') {
            Log::error('SmsService ww config missing', [
                'phone' => $phone,
                'type' => $type,
                'account_len' => strlen($account),
                'endpoint' => $endpoint,
            ]);

            return false;
        }

        try {
            $response = Http::asForm()->timeout((int) config('services.sms.ww_timeout', 10))->post($endpoint, [
                'sname' => $account,
                'spwd' => $password,
                'scorpid' => $corpid,
                'sprdid' => $productId,
                'sdst' => $phone,
                'smsg' => $smsg,
            ]);

            if (! $response->successful()) {
                Log::error('SmsService ww HTTP error', [
                    'phone' => $phone,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $state = static::extractXmlValue($response->body(), 'State');
            $msgId = static::extractXmlValue($response->body(), 'MsgID');
            $msgState = static::extractXmlValue($response->body(), 'MsgState');

            if ($state === '0') {
                Log::info('SmsService ww send ok', [
                    'phone' => static::maskPhone($phone),
                    'type' => $type,
                    'msg_id' => $msgId,
                    'sign' => config('services.sms.ww_sign'),
                    'smsg_preview' => mb_substr($smsg ?? '', 0, 20),
                ]);

                return $code;
            }

            Log::error('SmsService ww send failed', [
                'phone' => static::maskPhone($phone),
                'type' => $type,
                'state' => $state,
                'msg_id' => $msgId,
                'msg_state' => $msgState,
                'raw_body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService ww exception', [
                'phone' => static::maskPhone($phone),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 通用 HTTP 短信网关驱动
     *
     * 配置项（services.sms）：
     *   http_endpoint: 网关地址
     *   http_timeout:  超时秒数（默认 5）
     */
    private static function sendViaHttp(string $phone, string $code, string $type): string|false
    {
        $endpoint = config('services.sms.http_endpoint');

        if (empty($endpoint)) {
            Log::error('SmsService http driver: endpoint not configured');

            return false;
        }

        try {
            $payload = [
                'phone' => $phone,
                'message' => trans('sms.verification_code', ['code' => $code]),
                'code' => $code,
                'type' => $type,
            ];

            $timeout = (int) config('services.sms.http_timeout', 5);
            $response = Http::asJson()->timeout($timeout)->post($endpoint, $payload);

            $body = $response->json();

            Log::info('SmsService http response', [
                'phone' => static::maskPhone($phone),
                'type' => $type,
                'http_status' => $response->status(),
                'response' => $body,
            ]);

            if ($response->successful() && isset($body['status']) && (int) $body['status'] === 1) {
                return (string) ($body['data']['code'] ?? $body['code'] ?? $code);
            }

            Log::warning('SmsService http send failed', [
                'phone' => static::maskPhone($phone),
                'type' => $type,
                'response' => $body,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService http exception', [
                'phone' => static::maskPhone($phone),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private static function sendViaLog(string $phone, string $code, string $type): string
    {
        Log::info('SmsService [log driver] send code', [
            'phone' => static::maskPhone($phone),
            'code' => $code,
            'type' => $type,
        ]);

        return $code;
    }

    private static function extractXmlValue(string $xml, string $tag): ?string
    {
        if (preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/s', $xml, $matches) !== 1) {
            return null;
        }

        return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private static function maskPhone(string $phone): string
    {
        if (strlen($phone) !== 11) {
            return $phone;
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    // ========================================
    // 模板管理
    // ========================================

    /**
     * 创建短信模板
     */
    public static function createTemplate(array $data): SmsTemplate
    {
        return SmsTemplate::create($data);
    }

    /**
     * 更新短信模板
     */
    public static function updateTemplate(int $templateId, array $data): SmsTemplate
    {
        $template = SmsTemplate::findOrFail($templateId);
        $template->update($data);

        return $template->fresh();
    }

    /**
     * 提交模板审核
     */
    public static function submitForApproval(int $templateId): SmsTemplate
    {
        $template = SmsTemplate::findOrFail($templateId);
        $template->update(['status' => SmsTemplate::STATUS_PENDING_APPROVAL]);

        return $template->fresh();
    }

    /**
     * 渲染模板内容（变量替换）
     */
    public static function renderContent(int $templateId, array $variables = []): string
    {
        $template = SmsTemplate::findOrFail($templateId);
        $content = $template->content;

        foreach ($variables as $key => $value) {
            $content = str_replace('{' . $key . '}', (string) $value, $content);
        }

        return $content;
    }

    /**
     * 获取模板列表
     */
    public static function getTemplates(array $filters = []): Collection
    {
        $query = SmsTemplate::query();

        if (! empty($filters['channel'])) {
            $query->ofChannel($filters['channel']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    // ========================================
    // 批量发送
    // ========================================

    /**
     * 批量发送短信
     */
    public static function batchSend(int $templateId, array $phones, array $globalVars = []): SmsBatchTask
    {
        $template = SmsTemplate::findOrFail($templateId);

        $task = SmsBatchTask::create([
            'tenant_id' => $template->tenant_id,
            'sms_template_id' => $templateId,
            'type' => SmsBatchTask::TYPE_BATCH_SEND,
            'target_type' => 'user_list',
            'target_ids' => $phones,
            'total_count' => count($phones),
            'status' => SmsBatchTask::STATUS_PENDING,
        ]);

        return $task;
    }

    /**
     * 定时发送短信
     */
    public static function scheduledSend(int $templateId, array $phones, string $scheduledAt): SmsBatchTask
    {
        $template = SmsTemplate::findOrFail($templateId);

        $task = SmsBatchTask::create([
            'tenant_id' => $template->tenant_id,
            'sms_template_id' => $templateId,
            'type' => SmsBatchTask::TYPE_SCHEDULED,
            'target_type' => 'user_list',
            'target_ids' => $phones,
            'total_count' => count($phones),
            'status' => SmsBatchTask::STATUS_PENDING,
            'scheduled_at' => $scheduledAt,
        ]);

        return $task;
    }

    /**
     * 获取批量任务详情
     */
    public static function getBatchTask(int $taskId): SmsBatchTask
    {
        return SmsBatchTask::findOrFail($taskId);
    }

    /**
     * 取消批量任务
     */
    public static function cancelBatchTask(int $taskId): SmsBatchTask
    {
        $task = SmsBatchTask::findOrFail($taskId);

        if ($task->status === SmsBatchTask::STATUS_PENDING) {
            $task->update(['status' => SmsBatchTask::STATUS_CANCELLED]);
        }

        return $task->fresh();
    }

    // ========================================
    // 到达率统计
    // ========================================

    /**
     * 记录到达率统计结果
     */
    public static function recordDeliveryResult(int $batchTaskId, array $result): SmsDeliveryStat
    {
        return SmsDeliveryStat::create([
            'tenant_id' => $result['tenant_id'] ?? null,
            'sms_batch_task_id' => $batchTaskId,
            'sent_count' => $result['sent_count'] ?? 0,
            'delivered_count' => $result['delivered_count'] ?? 0,
            'failed_count' => $result['failed_count'] ?? 0,
            'clicked_count' => $result['clicked_count'] ?? 0,
            'unsubscribed_count' => $result['unsubscribed_count'] ?? 0,
            'delivery_rate' => $result['delivery_rate'] ?? 0,
            'recorded_at' => $result['recorded_at'] ?? now(),
        ]);
    }

    /**
     * 获取批量任务的到达率统计
     */
    public static function getDeliveryStats(int $batchTaskId): Collection
    {
        return SmsDeliveryStat::where('sms_batch_task_id', $batchTaskId)
            ->orderByDesc('recorded_at')
            ->get();
    }

    /**
     * 获取租户整体到达率统计
     */
    public static function getOverallStats(int $tenantId, ?string $from = null, ?string $to = null): array
    {
        $query = SmsDeliveryStat::where('tenant_id', $tenantId);

        if ($from) {
            $query->where('recorded_at', '>=', $from);
        }
        if ($to) {
            $query->where('recorded_at', '<=', $to);
        }

        $stats = $query->selectRaw('
            SUM(sent_count) as total_sent,
            SUM(delivered_count) as total_delivered,
            SUM(failed_count) as total_failed,
            SUM(clicked_count) as total_clicked,
            SUM(unsubscribed_count) as total_unsubscribed,
            AVG(delivery_rate) as avg_delivery_rate
        ')->first();

        return [
            'total_sent' => (int) ($stats->total_sent ?? 0),
            'total_delivered' => (int) ($stats->total_delivered ?? 0),
            'total_failed' => (int) ($stats->total_failed ?? 0),
            'total_clicked' => (int) ($stats->total_clicked ?? 0),
            'total_unsubscribed' => (int) ($stats->total_unsubscribed ?? 0),
            'avg_delivery_rate' => round((float) ($stats->avg_delivery_rate ?? 0), 2),
        ];
    }

    // ========================================
    // 退订管理
    // ========================================

    /**
     * 退订短信
     */
    public static function unsubscribe(string $phone, ?int $tenantId = null, ?int $userId = null, ?string $reason = null): SmsUnsubscribe
    {
        return SmsUnsubscribe::create([
            'tenant_id' => $tenantId,
            'phone' => $phone,
            'user_id' => $userId,
            'reason' => $reason,
            'unsubscribed_at' => now(),
        ]);
    }

    /**
     * 检查手机号是否已退订
     */
    public static function isUnsubscribed(string $phone, ?int $tenantId = null): bool
    {
        return SmsUnsubscribe::where('phone', $phone)
            ->where(function ($query) use ($tenantId) {
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }
            })
            ->exists();
    }

    /**
     * 获取退订列表
     */
    public static function getUnsubscribes(?int $tenantId = null): Collection
    {
        $query = SmsUnsubscribe::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        } else {
            $query->withoutGlobalScopes();
        }

        return $query->orderByDesc('unsubscribed_at')->get();
    }
}
