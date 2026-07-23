<?php

namespace MultiTenantSaas\Modules\Sms\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Sms\Models\SmsBatchTask;
use MultiTenantSaas\Modules\Sms\Models\SmsDeliveryStat;
use MultiTenantSaas\Modules\Sms\Models\SmsTemplate;
use MultiTenantSaas\Modules\Sms\Models\SmsUnsubscribe;

/**
 * 短信发送服务（DI 实例方法）。
 *
 * 配置读取优先级：租户级 TenantSetting > 系统级 config（当前不兑底）
 *
 * driver=log → 仅写日志（本地/测试默认）
 * driver=ww  → 调用网建短信网关
 * driver=aliyun → 阿里云 dysmsapi
 * driver=http→ 通用 HTTP 短信网关（自定义 endpoint）
 *
 * 扩展功能：模板管理、批量发送、到达率统计、退订管理
 *
 * 向后兼容：保留 __callStatic 代理，新代码应通过构造器注入使用。
 */
class SmsService
{
    /**
     * 向后兼容：静态调用代理到容器实例。
     *
     * @deprecated 请改用构造器注入
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(static::class)->{$method}(...$arguments);
    }

    /**
     * 发送验证码短信，成功返回传入的 $code，失败返回 false。
     *
     * 配置从当前租户 TenantSetting(group=sms) 读取，未配置则返回 false。
     */
    public function send(string $phone, string $code, string $type = 'register'): string|false
    {
        $tenantId = TenantContext::getId();

        // 租户级配置
        if ($tenantId) {
            $driver = TenantSetting::get((int) $tenantId, 'sms', 'driver', '');

            if ($driver) {
                return $this->sendWithTenantConfig((int) $tenantId, $driver, $phone, $code, $type);
            }
        }

        // 系统级兖底（当前不兖底，返回 false）
        Log::warning('SmsService: no tenant SMS config', [
            'tenant_id' => $tenantId,
            'phone' => $this->maskPhone($phone),
            'type' => $type,
        ]);

        return false;
    }

    /**
     * 使用租户级配置发送
     */
    protected function sendWithTenantConfig(int $tenantId, string $driver, string $phone, string $code, string $type): string|false
    {
        $driver = trim($driver);

        return match ($driver) {
            'aliyun' => $this->sendViaAliyunTenant($tenantId, $phone, $code, $type),
            'ww' => $this->sendViaWw($phone, $code, $type),
            'http' => $this->sendViaHttp($phone, $code, $type),
            'log' => $this->sendViaLog($phone, $code, $type),
            default => $this->sendViaLog($phone, $code, $type),
        };
    }

    public function sendUsingDriver(string $driver, string $phone, string $code, string $type = 'register'): string|false
    {
        $driver = trim($driver);

        return match ($driver) {
            'ww' => $this->sendViaWw($phone, $code, $type),
            'aliyun' => $this->sendViaAliyun($phone, $code, $type),
            'http' => $this->sendViaHttp($phone, $code, $type),
            default => $this->sendViaLog($phone, $code, $type),
        };
    }

    // ----------------------------------------
    // Private drivers
    // ----------------------------------------

    private function sendViaWw(string $phone, string $code, string $type): string|false
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

            $state = $this->extractXmlValue($response->body(), 'State');
            $msgId = $this->extractXmlValue($response->body(), 'MsgID');
            $msgState = $this->extractXmlValue($response->body(), 'MsgState');

            if ($state === '0') {
                Log::info('SmsService ww send ok', [
                    'phone' => $this->maskPhone($phone),
                    'type' => $type,
                    'msg_id' => $msgId,
                    'sign' => config('services.sms.ww_sign'),
                    'smsg_preview' => mb_substr($smsg ?? '', 0, 20),
                ]);

                return $code;
            }

            Log::error('SmsService ww send failed', [
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'state' => $state,
                'msg_id' => $msgId,
                'msg_state' => $msgState,
                'raw_body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService ww exception', [
                'phone' => $this->maskPhone($phone),
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
    private function sendViaHttp(string $phone, string $code, string $type): string|false
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
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'http_status' => $response->status(),
                'response' => $body,
            ]);

            if ($response->successful() && isset($body['status']) && (int) $body['status'] === 1) {
                return (string) ($body['data']['code'] ?? $body['code'] ?? $code);
            }

            Log::warning('SmsService http send failed', [
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'response' => $body,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService http exception', [
                'phone' => $this->maskPhone($phone),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendViaLog(string $phone, string $code, string $type): string
    {
        Log::info('SmsService [log driver] send code', [
            'phone' => $this->maskPhone($phone),
            'code' => $code,
            'type' => $type,
        ]);

        return $code;
    }

    /**
     * 阿里云短信驱动（dysmsapi HTTP API, SignatureVersion 1.0, HMAC-SHA1）
     *
     * 配置项（services.sms）：
     *   aliyun_access_key_id, aliyun_access_key_secret,
     *   aliyun_sign_name, aliyun_template_code
     */
    private function sendViaAliyun(string $phone, string $code, string $type): string|false
    {
        $accessKeyId = (string) config('services.sms.aliyun_access_key_id');
        $accessKeySecret = (string) config('services.sms.aliyun_access_key_secret');
        $signName = (string) config('services.sms.aliyun_sign_name');
        $templateCode = (string) config('services.sms.aliyun_template_code');

        if ($accessKeyId === '' || $accessKeySecret === '' || $signName === '' || $templateCode === '') {
            Log::error('SmsService aliyun config missing', [
                'phone' => $this->maskPhone($phone),
                'type' => $type,
            ]);

            return false;
        }

        try {
            // 构造阿里云 API 请求参数
            $params = [
                'AccessKeyId' => $accessKeyId,
                'Action' => 'SendSms',
                'Format' => 'JSON',
                'PhoneNumbers' => $phone,
                'RegionId' => 'cn-hangzhou',
                'SignName' => $signName,
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureNonce' => uniqid((string) mt_rand(), true),
                'SignatureVersion' => '1.0',
                'TemplateCode' => $templateCode,
                'TemplateParam' => json_encode(['code' => $code, 'time' => 5], JSON_UNESCAPED_UNICODE),
                'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'Version' => '2017-05-25',
            ];

            // 计算签名
            $params['Signature'] = $this->computeAliyunSignature($params, $accessKeySecret);

            $response = Http::timeout((int) config('services.sms.aliyun_timeout', 10))
                ->asForm()
                ->post('https://dysmsapi.aliyuncs.com/', $params);

            $body = $response->json();

            if ($response->successful() && ($body['Code'] ?? '') === 'OK') {
                Log::info('SmsService aliyun send ok', [
                    'phone' => $this->maskPhone($phone),
                    'type' => $type,
                    'biz_id' => $body['BizId'] ?? null,
                ]);

                return $code;
            }

            Log::error('SmsService aliyun send failed', [
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'code' => $body['Code'] ?? null,
                'message' => $body['Message'] ?? null,
                'request_id' => $body['RequestId'] ?? null,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService aliyun exception', [
                'phone' => $this->maskPhone($phone),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 阿里云短信（租户级配置）
     *
     * 从 TenantSetting(group=sms) 读取：
     *   aliyun_access_key_id, aliyun_access_key_secret, aliyun_sign_name, aliyun_template_code
     */
    private function sendViaAliyunTenant(int $tenantId, string $phone, string $code, string $type): string|false
    {
        $accessKeyId = TenantSetting::get($tenantId, 'sms', 'access_key_id', '');
        $accessKeySecret = TenantSetting::get($tenantId, 'sms', 'access_key_secret', '');
        $signName = TenantSetting::get($tenantId, 'sms', 'sign_name', '');
        $templateCode = TenantSetting::get($tenantId, 'sms', 'template_code', '');

        if ($accessKeyId === '' || $accessKeySecret === '' || $signName === '' || $templateCode === '') {
            Log::error('SmsService aliyun tenant config missing', [
                'tenant_id' => $tenantId,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
            ]);

            return false;
        }

        try {
            $params = [
                'AccessKeyId' => $accessKeyId,
                'Action' => 'SendSms',
                'Format' => 'JSON',
                'PhoneNumbers' => $phone,
                'RegionId' => 'cn-hangzhou',
                'SignName' => $signName,
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureNonce' => uniqid((string) mt_rand(), true),
                'SignatureVersion' => '1.0',
                'TemplateCode' => $templateCode,
                'TemplateParam' => json_encode(['code' => $code, 'time' => 5], JSON_UNESCAPED_UNICODE),
                'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'Version' => '2017-05-25',
            ];

            $params['Signature'] = $this->computeAliyunSignature($params, $accessKeySecret);

            $response = Http::timeout(10)->asForm()->post('https://dysmsapi.aliyuncs.com/', $params);

            $body = $response->json();

            if ($response->successful() && ($body['Code'] ?? '') === 'OK') {
                Log::info('SmsService aliyun tenant send ok', [
                    'tenant_id' => $tenantId,
                    'phone' => $this->maskPhone($phone),
                    'type' => $type,
                    'biz_id' => $body['BizId'] ?? null,
                ]);

                return $code;
            }

            Log::error('SmsService aliyun tenant send failed', [
                'tenant_id' => $tenantId,
                'phone' => $this->maskPhone($phone),
                'type' => $type,
                'code' => $body['Code'] ?? null,
                'message' => $body['Message'] ?? null,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('SmsService aliyun tenant exception', [
                'tenant_id' => $tenantId,
                'phone' => $this->maskPhone($phone),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 计算阿里云 API 签名（SignatureVersion 1.0, HMAC-SHA1）
     */
    private function computeAliyunSignature(array $params, string $accessKeySecret): string
    {
        ksort($params);

        $canonicalized = '';
        foreach ($params as $key => $value) {
            $canonicalized .= '&' . $this->aliyunPercentEncode($key) . '=' . $this->aliyunPercentEncode($value);
        }

        $stringToSign = 'POST&' . $this->aliyunPercentEncode('/') . '&' . $this->aliyunPercentEncode(substr($canonicalized, 1));

        return base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    }

    /**
     * 阿里云特殊 URL 编码（RFC 3986）
     */
    private function aliyunPercentEncode(string $value): string
    {
        return str_replace(
            ['+', '*', '%7E'],
            ['%20', '%2A', '~'],
            urlencode($value)
        );
    }

    private function extractXmlValue(string $xml, string $tag): ?string
    {
        if (preg_match('/<' . preg_quote($tag, '/') . '>(.*?)<\/' . preg_quote($tag, '/') . '>/s', $xml, $matches) !== 1) {
            return null;
        }

        return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function maskPhone(string $phone): string
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
    public function createTemplate(array $data): SmsTemplate
    {
        return SmsTemplate::create($data);
    }

    /**
     * 更新短信模板
     */
    public function updateTemplate(int $templateId, array $data): SmsTemplate
    {
        $template = SmsTemplate::findOrFail($templateId);
        $template->update($data);

        return $template->fresh();
    }

    /**
     * 提交模板审核
     */
    public function submitForApproval(int $templateId): SmsTemplate
    {
        $template = SmsTemplate::findOrFail($templateId);
        $template->update(['status' => SmsTemplate::STATUS_PENDING_APPROVAL]);

        return $template->fresh();
    }

    /**
     * 渲染模板内容（变量替换）
     */
    public function renderContent(int $templateId, array $variables = []): string
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
    public function getTemplates(array $filters = []): Collection
    {
        $query = SmsTemplate::query();

        // 租户隔离
        $tenantId = TenantContext::getId();
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

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
    public function batchSend(int $templateId, array $phones, array $globalVars = []): SmsBatchTask
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
    public function scheduledSend(int $templateId, array $phones, string $scheduledAt): SmsBatchTask
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
    public function getBatchTask(int $taskId): SmsBatchTask
    {
        return SmsBatchTask::findOrFail($taskId);
    }

    /**
     * 取消批量任务
     */
    public function cancelBatchTask(int $taskId): SmsBatchTask
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
    public function recordDeliveryResult(int $batchTaskId, array $result): SmsDeliveryStat
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
    public function getDeliveryStats(int $batchTaskId): Collection
    {
        return SmsDeliveryStat::where('sms_batch_task_id', $batchTaskId)
            ->orderByDesc('recorded_at')
            ->get();
    }

    /**
     * 获取租户整体到达率统计
     */
    public function getOverallStats(int $tenantId, ?string $from = null, ?string $to = null): array
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
    public function unsubscribe(string $phone, ?int $tenantId = null, ?int $userId = null, ?string $reason = null): SmsUnsubscribe
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
    public function isUnsubscribed(string $phone, ?int $tenantId = null): bool
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
    public function getUnsubscribes(?int $tenantId = null): Collection
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
