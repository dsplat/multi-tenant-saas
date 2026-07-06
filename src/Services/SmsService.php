<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\SmsBatchTask;
use MultiTenantSaas\Models\SmsSendLog;
use MultiTenantSaas\Models\SmsTemplate;

/**
 * 短信发送服务
 *
 * driver=log → 仅写日志（本地/测试默认）
 * driver=ww  → 调用网建短信网关
 * driver=http→ 通用 HTTP 短信网关（自定义 endpoint）
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
                'phone'   => $phone,
                'message' => trans("sms.verification_code", ["code" => $code]),
                'code'    => $code,
                'type'    => $type,
            ];

            $timeout = (int) config('services.sms.http_timeout', 5);
            $response = Http::asJson()->timeout($timeout)->post($endpoint, $payload);

            $body = $response->json();

            Log::info('SmsService http response', [
                'phone'       => static::maskPhone($phone),
                'type'        => $type,
                'http_status' => $response->status(),
                'response'    => $body,
            ]);

            if ($response->successful() && isset($body['status']) && (int) $body['status'] === 1) {
                return (string) ($body['data']['code'] ?? $body['code'] ?? $code);
            }

            Log::warning('SmsService http send failed', [
                'phone'    => static::maskPhone($phone),
                'type'     => $type,
                'response' => $body,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService http exception', [
                'phone'   => static::maskPhone($phone),
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
        if (preg_match('/<'.preg_quote($tag, '/').'>(.*?)<\/'.preg_quote($tag, '/').'>/s', $xml, $matches) !== 1) {
            return null;
        }

        return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private static function maskPhone(string $phone): string
    {
        if (strlen($phone) !== 11) {
            return $phone;
        }

        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }

    // ========================================================================
    // 扩展: 营销短信模板、批量发送、定时发送、到达率统计
    // ========================================================================

    /**
     * 创建短信模板
     */
    public static function createTemplate(array $data, ?int $tenantId = null): SmsTemplate
    {
        return SmsTemplate::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'code' => $data['code'],
            'content' => $data['content'],
            'type' => $data['type'] ?? 'marketing',
            'sign_name' => $data['sign_name'] ?? null,
            'params' => $data['params'] ?? null,
            'status' => $data['status'] ?? 'active',
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * 获取模板列表
     */
    public static function getTemplates(?int $tenantId = null, array $filters = [])
    {
        $query = SmsTemplate::query();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 发送营销短信（使用模板）
     *
     * @param  string  $phone  手机号
     * @param  int  $templateId  模板ID
     * @param  array  $params  模板参数
     * @param  int|null  $tenantId  租户ID
     */
    public static function sendMarketing(string $phone, int $templateId, array $params = [], ?int $tenantId = null): string|false
    {
        $template = SmsTemplate::findOrFail($templateId);

        $content = $template->content;
        foreach ($params as $key => $value) {
            $content = str_replace('{' . $key . '}', $value, $content);
        }

        $driver = config('services.sms.driver', 'log');
        $result = static::sendRaw($driver, $phone, $content, $template->sign_name);

        SmsSendLog::create([
            'tenant_id' => $tenantId,
            'phone' => $phone,
            'content' => $content,
            'template_id' => $templateId,
            'status' => $result !== false ? 'sent' : 'failed',
            'provider' => $driver,
            'sent_at' => $result !== false ? now() : null,
        ]);

        return $result;
    }

    /**
     * 批量发送短信
     *
     * @param  array  $recipients  [{phone, params}, ...]
     * @param  int  $templateId  模板ID
     * @param  int|null  $tenantId  租户ID
     * @return SmsBatchTask
     */
    public static function sendBatch(array $recipients, int $templateId, ?int $tenantId = null): SmsBatchTask
    {
        $template = SmsTemplate::findOrFail($templateId);

        $task = SmsBatchTask::create([
            'tenant_id' => $tenantId,
            'template_id' => $templateId,
            'name' => '批量发送_' . date('YmdHis'),
            'status' => 'sending',
            'total_count' => count($recipients),
            'started_at' => now(),
        ]);

        $successCount = 0;
        $failCount = 0;
        $driver = config('services.sms.driver', 'log');

        foreach ($recipients as $recipient) {
            $phone = $recipient['phone'];
            $params = $recipient['params'] ?? [];

            $content = $template->content;
            foreach ($params as $key => $value) {
                $content = str_replace('{' . $key . '}', $value, $content);
            }

            $result = static::sendRaw($driver, $phone, $content, $template->sign_name);

            $status = $result !== false ? 'sent' : 'failed';
            if ($result !== false) {
                $successCount++;
            } else {
                $failCount++;
            }

            SmsSendLog::create([
                'task_id' => $task->getKey(),
                'tenant_id' => $tenantId,
                'phone' => $phone,
                'content' => $content,
                'template_id' => $templateId,
                'status' => $status,
                'provider' => $driver,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);
        }

        $task->update([
            'status' => 'completed',
            'sent_count' => $successCount + $failCount,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'completed_at' => now(),
        ]);

        return $task;
    }

    /**
     * 定时发送短信
     *
     * 创建定时任务，实际发送由队列作业处理。
     *
     * @param  array  $recipients  接收者列表
     * @param  int  $templateId  模板ID
     * @param  string|\DateTimeInterface  $scheduledAt  计划发送时间
     * @param  int|null  $tenantId  租户ID
     * @return SmsBatchTask
     */
    public static function sendScheduled(array $recipients, int $templateId, $scheduledAt, ?int $tenantId = null): SmsBatchTask
    {
        $template = SmsTemplate::findOrFail($templateId);

        if ($template->status !== 'active') {
            throw new \RuntimeException('短信模板未启用');
        }

        $task = SmsBatchTask::create([
            'tenant_id' => $tenantId,
            'template_id' => $templateId,
            'name' => '定时发送_' . date('YmdHis'),
            'status' => 'pending',
            'total_count' => count($recipients),
            'scheduled_at' => $scheduledAt,
            'metadata' => ['recipients' => $recipients],
        ]);

        return $task;
    }

    /**
     * 到达率统计
     *
     * @param  int|null  $tenantId  租户ID
     * @param  array  $filters  筛选条件: start_date, end_date, task_id
     * @return array
     */
    public static function getDeliveryStats(?int $tenantId = null, array $filters = []): array
    {
        $query = SmsSendLog::query();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        if (!empty($filters['task_id'])) {
            $query->where('task_id', $filters['task_id']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $total = (clone $query)->count();
        $sent = (clone $query)->where('status', 'sent')->count();
        $delivered = (clone $query)->where('status', 'delivered')->count();
        $failed = (clone $query)->where('status', 'failed')->count();
        $pending = (clone $query)->where('status', 'pending')->count();

        $byProvider = (clone $query)
            ->selectRaw('provider, COUNT(*) as total, SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent_count')
            ->groupBy('provider')
            ->get();

        $dailyStats = (clone $query)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total, SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'total' => $total,
            'sent' => $sent,
            'delivered' => $delivered,
            'failed' => $failed,
            'pending' => $pending,
            'delivery_rate' => $total > 0 ? round($sent / $total * 100, 2) : 0,
            'by_provider' => $byProvider->toArray(),
            'daily_stats' => $dailyStats->toArray(),
        ];
    }

    /**
     * 发送原始内容（不经过模板）
     */
    private static function sendRaw(string $driver, string $phone, string $content, ?string $sign = null): string|false
    {
        $sign = $sign ?? config('services.sms.ww_sign', 'YourApp');
        $message = '【' . $sign . '】' . $content;

        return match ($driver) {
            'ww' => static::sendRawViaWw($phone, $message),
            'http' => static::sendRawViaHttp($phone, $message),
            default => static::sendRawViaLog($phone, $message),
        };
    }

    private static function sendRawViaWw(string $phone, string $message): string|false
    {
        $endpoint = (string) config('services.sms.ww_endpoint');
        $account = (string) config('services.sms.ww_account');
        $password = (string) config('services.sms.ww_password');
        $corpid = (string) config('services.sms.ww_corpid');
        $productId = (string) config('services.sms.ww_product_id');

        if ($endpoint === '' || $account === '' || $password === '' || $productId === '') {
            Log::error('SmsService ww config missing');
            return false;
        }

        try {
            $response = Http::asForm()->timeout((int) config('services.sms.ww_timeout', 10))->post($endpoint, [
                'sname' => $account,
                'spwd' => $password,
                'scorpid' => $corpid,
                'sprdid' => $productId,
                'sdst' => $phone,
                'smsg' => $message,
            ]);

            if (!$response->successful()) {
                return false;
            }

            $state = static::extractXmlValue($response->body(), 'State');

            return $state === '0' ? 'ok' : false;
        } catch (\Throwable $e) {
            Log::error('SmsService sendRaw ww exception', ['message' => $e->getMessage()]);
            return false;
        }
    }

    private static function sendRawViaHttp(string $phone, string $message): string|false
    {
        $endpoint = config('services.sms.http_endpoint');

        if (empty($endpoint)) {
            return false;
        }

        try {
            $response = Http::asJson()->timeout((int) config('services.sms.http_timeout', 5))->post($endpoint, [
                'phone' => $phone,
                'message' => $message,
            ]);

            $body = $response->json();

            if ($response->successful() && isset($body['status']) && (int) $body['status'] === 1) {
                return 'ok';
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService sendRaw http exception', ['message' => $e->getMessage()]);
            return false;
        }
    }

    private static function sendRawViaLog(string $phone, string $message): string
    {
        Log::info('SmsService [log driver] send message', [
            'phone' => static::maskPhone($phone),
            'message' => $message,
        ]);

        return 'ok';
    }
}
