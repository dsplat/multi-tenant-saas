<?php

namespace MultiTenantSaas\Modules\ApiToken\Services;

use MultiTenantSaas\Modules\ApiToken\Models\UserApiToken;
use MultiTenantSaas\Modules\ApiToken\Models\UserApiTokenHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * API Token 模块服务
 *
 * 对接 new-api 后端，负责：
 * - 用户 Token 的创建/查询/禁用/轮换
 * - Quota 追加与本地缓存同步
 * - 模型白名单调整
 */
class ApiTokenService
{
    private string $baseUrl;

    private string $adminKey;

    private int $adminUserId;

    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('apitoken.base_url', 'https://api.example.com'), '/');
        $this->adminKey = config('apitoken.admin_key', '');
        $this->adminUserId = (int) config('apitoken.admin_user_id', 1);
        $this->timeout = (int) config('apitoken.timeout', 15);
    }

    // -------------------------------------------------------------------------
    // 公开方法
    // -------------------------------------------------------------------------

    /**
     * 确保用户在 New API 拥有 Token；首次调用时创建，已有则直接返回。
     *
     * 注意：此版本 New API 的 POST /api/token/ 不返回 data，
     * 需要创建后通过关键词搜索取得 token id。
     */
    public function ensureUserToken(int $userId, ?int $tenantId): UserApiToken
    {
        $record = UserApiToken::withoutGlobalScopes()->where('user_id', $userId)->first();

        if ($record) {
            return $record;
        }

        $tokenName = 'user_' . $userId;

        // 先在 New API 搜索是否已存在同名 token（防止 DB 记录丢失时在 New API 重复创建）
        $existingList = $this->getList('/api/token/', ['keyword' => $tokenName, 'size' => 5]);
        $item = collect($existingList)->firstWhere('name', $tokenName);

        if (! $item) {
            // 不存在才创建（New API 忽略请求体中的 key 字段，始终自动生成）
            // expired_time=-1 表示永不过期；若不传，New API 默认为 0（1970年，立即过期）
            $this->post('/api/token/', [
                'name' => $tokenName,
                'remain_quota' => 0,
                'unlimited_quota' => false,
                'expired_time' => -1,
                'group' => 'default',
            ]);

            // 通过关键词查询取得 New API 内部 token id
            $list = $this->getList('/api/token/', ['keyword' => $tokenName, 'size' => 5]);
            $item = collect($list)->firstWhere('name', $tokenName);

            if (! $item) {
                throw new \RuntimeException('[ApiTokenService] ' . trans("apitoken.token_not_found") . ': ' . $tokenName);
            }
        }

        // 调 POST /api/token/{id}/key 取完整明文 key（New API 数据库存的是不含 sk- 前缀的裸 key）
        $keyResult = $this->post('/api/token/' . (int) $item['id'] . '/key', []);
        $rawKey = $keyResult['key'] ?? null;

        if (! $rawKey || str_contains((string) $rawKey, '****')) {
            throw new \RuntimeException('[ApiTokenService] ' . trans("apitoken.key_incomplete") . ': ' . $item['id']);
        }

        // 加 sk- 前缀存储，保证 getDecryptedKey() 返回可直接使用的完整 key
        $fullKey = str_starts_with($rawKey, 'sk-') ? $rawKey : 'sk-' . $rawKey;

        $record = UserApiToken::create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'apisvr_token_id' => (int) $item['id'],
            'apisvr_key' => $fullKey,   // sk-xxxxx 格式
            'remain_quota_cache' => 0,
            'used_quota_cache' => 0,
        ]);

        return $record;
    }

    /**
     * 向用户的 New API Token 追加 quota。
     *
     * @param  int  $amount  追加的 token 数量
     * @param  int  $tenantId  仅在记录不存在时用于创建
     * @return UserApiToken 更新后的记录
     */
    public function topUpQuota(int $userId, int $amount, ?int $tenantId): UserApiToken
    {
        $record = $this->ensureUserToken($userId, $tenantId);

        $current = $this->fetchRemoteToken($record->apisvr_token_id);
        $newRemain = (int) ($current['remain_quota'] ?? 0) + $amount;

        // apisvr PUT 是「全量替换」语义：必须带上 model_limits 等所有字段，否则会被清空。
        // expired_time：优先保留远端已有值；若为 0（1970年默认值）则强制改为 -1（永不过期）
        $remoteExpired = (int) ($current['expired_time'] ?? 0);
        $this->put('/api/token/', [
            'id' => $record->apisvr_token_id,
            'name' => 'user_' . $userId,
            'remain_quota' => $newRemain,
            'unlimited_quota' => false,
            'expired_time' => $remoteExpired === 0 ? -1 : $remoteExpired,
            'model_limits_enabled' => (bool) ($current['model_limits_enabled'] ?? false),
            'model_limits' => (string) ($current['model_limits'] ?? ''),
        ]);

        $record->remain_quota_cache = $newRemain;
        $record->used_quota_cache = (int) ($current['used_quota'] ?? $record->used_quota_cache);
        $record->quota_synced_at = now();
        $record->save();

        return $record;
    }

    /**
     * 调整用户 Token 的模型白名单。
     *
     * @param  array  $models  模型列表；空数组=不限
     */
    /**
     * 从 New API 获取所有可用模型，扁平化为有序字符串数组。
     *
     * GET /api/models 返回结构: {"data":{"group1":["m1","m2"],...}}
     *
     * @return string[]
     */
    public function getAvailableModels(): array
    {
        try {
            $data = $this->get('/api/models');
            $models = [];
            foreach ($data as $group) {
                if (is_array($group)) {
                    foreach ($group as $model) {
                        $models[] = (string) $model;
                    }
                }
            }
            $models = array_values(array_unique($models));
            sort($models);

            return $models;
        } catch (\Throwable $e) {
            Log::warning('[ApiTokenService] getAvailableModels failed: ' . $e->getMessage());

            return [];
        }
    }

    public function adjustModelLimits(int $userId, array $models): void
    {
        $record = UserApiToken::withoutGlobalScopes()->where('user_id', $userId)->firstOrFail();

        $current = $this->fetchRemoteToken($record->apisvr_token_id);

        $remoteExpiredAdj = (int) ($current['expired_time'] ?? 0);
        $this->put('/api/token/', [
            'id' => $record->apisvr_token_id,
            'name' => 'user_' . $userId,
            'remain_quota' => (int) ($current['remain_quota'] ?? 0),
            'unlimited_quota' => false,
            'expired_time' => $remoteExpiredAdj === 0 ? -1 : $remoteExpiredAdj,
            'model_limits_enabled' => ! empty($models),
            'model_limits' => implode(',', $models),
        ]);
    }

    /**
     * 禁用用户的 New API Token。
     */
    public function disableToken(int $userId): void
    {
        $record = UserApiToken::withoutGlobalScopes()->where('user_id', $userId)->firstOrFail();

        $this->put('/api/token', [
            'id' => $record->apisvr_token_id,
            'status' => 2,
        ]);
    }

    /**
     * 在 New API 创建一个活动专用 token（独立于 user_api_tokens）。
     * 上层负责持久化到 user_promo_tokens。
     *
     * @param  string[]  $models  允许的模型列表（model_limits_enabled=true）
     * @return array{apisvr_token_id: int, apisvr_key: string} sk-xxx 格式
     */
    public function createPromoTokenInApisvr(
        int $userId,
        string $tokenName,
        string $group,
        int $remainQuota,
        int $expiredTime,
        array $models,
    ): array {
        // 防止重复：如已存在同名 token，先尝试删除（活动只允许一次领取）
        $existingList = $this->getList('/api/token/', ['keyword' => $tokenName, 'size' => 5]);
        $existing = collect($existingList)->firstWhere('name', $tokenName);
        if ($existing) {
            try {
                $this->delete('/api/token/' . (int) $existing['id']);
            } catch (\Throwable $e) {
                Log::warning('[ApiTokenService] ' . trans("apitoken.old_token_delete_failed"), [
                    'token_id' => $existing['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->post('/api/token/', [
            'name' => $tokenName,
            'remain_quota' => $remainQuota,
            'unlimited_quota' => false,
            'expired_time' => $expiredTime,
            'group' => $group,
            'model_limits_enabled' => ! empty($models),
            'model_limits' => implode(',', $models),
        ]);

        $list = $this->getList('/api/token/', ['keyword' => $tokenName, 'size' => 5]);
        $item = collect($list)->firstWhere('name', $tokenName);
        if (! $item) {
            throw new RuntimeException('[ApiTokenService] ' . trans("apitoken.promo_token_not_found") . ': ' . $tokenName);
        }

        $keyResult = $this->post('/api/token/' . (int) $item['id'] . '/key', []);
        $rawKey = $keyResult['key'] ?? null;

        if (! $rawKey || str_contains((string) $rawKey, '****')) {
            throw new RuntimeException('[ApiTokenService] ' . trans("apitoken.promo_key_incomplete") . ': ' . $item['id']);
        }

        $fullKey = str_starts_with($rawKey, 'sk-') ? $rawKey : 'sk-' . $rawKey;

        return [
            'apisvr_token_id' => (int) $item['id'],
            'apisvr_key' => $fullKey,
        ];
    }

    /**
     * 轮换用户的 sk-xxx：
     * 1. 同步当前 quota
     * 2. New API 删除旧 token
     * 3. New API 创建新 token（迁移 quota）
     * 4. 更新 user_api_tokens 本地记录
     * 5. 写 user_api_token_history 归档旧 key
     *
     * @param  string  $reason  leaked|admin_reset|user_request
     * @param  int  $operatorId  操作者 user_id
     * @return UserApiToken 更新后的记录
     */
    public function rotateToken(int $userId, string $reason, int $operatorId): UserApiToken
    {
        $record = UserApiToken::withoutGlobalScopes()->where('user_id', $userId)->firstOrFail();

        return DB::transaction(function () use ($record, $reason, $operatorId) {
            $current = $this->fetchRemoteQuota($record->apisvr_token_id);
            $remainQuota = $current['remain_quota'] ?? 0;
            $oldApiserTokenId = $record->apisvr_token_id;
            $oldKeyMasked = UserApiTokenHistory::maskKey($record->getDecryptedKey());

            // 创建新 token，携带旧 quota
            $newToken = $this->post('/api/token', [
                'name' => 'user_' . $record->user_id . '_r' . time(),
                'remain_quota' => $remainQuota,
                'unlimited_quota' => false,
                'group' => 'default',
            ]);

            // 删除旧 token
            $this->delete('/api/token/' . $oldApiserTokenId);

            // 写归档记录
            UserApiTokenHistory::create([
                'user_id' => $record->user_id,
                'apisvr_token_id' => $oldApiserTokenId,
                'apisvr_key_masked' => $oldKeyMasked,
                'quota_at_rotation' => $remainQuota,
                'reason' => $reason,
                'rotated_by' => $operatorId,
                'rotated_at' => now(),
            ]);

            // 更新本地绑定
            $record->apisvr_token_id = $newToken['id'];
            $record->apisvr_key = $newToken['key'];
            $record->remain_quota_cache = $remainQuota;
            $record->quota_synced_at = now();
            $record->save();

            return $record;
        });
    }

    /**
     * 从 New API 拉取最新 quota，写入本地缓存。
     */
    public function syncQuota(int $userId): UserApiToken
    {
        $record = UserApiToken::withoutGlobalScopes()->where('user_id', $userId)->firstOrFail();

        $data = $this->fetchRemoteQuota($record->apisvr_token_id);

        $record->remain_quota_cache = $data['remain_quota'] ?? 0;
        $record->used_quota_cache = $data['used_quota'] ?? 0;
        $record->quota_synced_at = now();
        $record->save();

        return $record;
    }

    /**
     * 全量同步所有用户的 quota 缓存（供定时任务调用）。
     *
     * @return int 同步成功的用户数
     */
    public function syncAllQuotas(): int
    {
        $count = 0;

        UserApiToken::withoutGlobalScopes()->chunkById(100, function ($records) use (&$count) {
            foreach ($records as $record) {
                try {
                    $data = $this->fetchRemoteQuota($record->apisvr_token_id);
                    $record->remain_quota_cache = $data['remain_quota'] ?? 0;
                    $record->used_quota_cache = $data['used_quota'] ?? 0;
                    $record->quota_synced_at = now();
                    $record->saveQuietly();
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning('[ApiTokenService] ' . trans("apitoken.sync_quota_failed"), [
                        'user_id' => $record->user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $count;
    }

    // -------------------------------------------------------------------------
    // 私有方法：New API HTTP 调用
    // -------------------------------------------------------------------------

    /**
     * 获取指定 Token 某天的用量汇总。
     * New API 暂未提供按天查询接口，预留此方法；返回 null 表示暂不支持。
     *
     * @return array{tokens_used: int, calls_count: int, cost_usd: float}|null
     */
    public function fetchDailyUsage(int $apiserTokenId, \Illuminate\Support\Carbon $date): ?array
    {
        // TODO: 当 New API 提供 /api/usage?token_id=&date= 时，在此实现
        return null;
    }

    /**
     * 拉取指定 token 的调用日志（New API admin /api/log/）。
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function fetchTokenLogs(int $apiserTokenId, int $page = 1, int $pageSize = 20): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->get($this->baseUrl . '/api/log/', [
                'p' => $page,
                'page_size' => $pageSize,
                'token_id' => $apiserTokenId,
            ]);

        $this->assertSuccess($response, 'GET', '/api/log/');

        $data = $response->json('data', []);

        return [
            'items' => $data['items'] ?? [],
            'total' => (int) ($data['total'] ?? 0),
            'page' => (int) ($data['page'] ?? $page),
            'page_size' => (int) ($data['page_size'] ?? $pageSize),
        ];
    }

    /**
     * 从 New API 获取 token 的 remain_quota / used_quota。
     *
     * @return array{remain_quota: int, used_quota: int}
     */
    private function fetchRemoteQuota(int $apiserTokenId): array
    {
        $token = $this->fetchRemoteToken($apiserTokenId);

        return [
            'remain_quota' => (int) ($token['remain_quota'] ?? 0),
            'used_quota' => (int) ($token['used_quota'] ?? 0),
        ];
    }

    /**
     * 拉取 New API token 完整信息（用于 PUT 前的字段合并）。
     *
     * @return array<string, mixed>
     */
    /**
     * 查询 apisvr token 剩余额度（实时）。
     */
    public function getRemainQuota(int $apiserTokenId): int
    {
        $data = $this->get('/api/token/' . $apiserTokenId);

        return (int) ($data['remain_quota'] ?? 0);
    }

    private function fetchRemoteToken(int $apiserTokenId): array
    {
        return $this->get('/api/token/' . $apiserTokenId);
    }

    private function get(string $path): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->get($this->baseUrl . $path);

        $this->assertSuccess($response, 'GET', $path);

        return $response->json('data', []);
    }

    /**
     * GET 列表接口，返回 data.items 数组。
     * New API 列表接口结构：{"data":{"page":1,"total":N,"items":[...]}}
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function getList(string $path, array $query = []): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->get($this->baseUrl . $path, $query);

        $this->assertSuccess($response, 'GET', $path);

        return $response->json('data.items', []);
    }

    private function post(string $path, array $payload): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->post($this->baseUrl . $path, $payload);

        $this->assertSuccess($response, 'POST', $path);

        return $response->json('data', []);
    }

    private function put(string $path, array $payload): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->put($this->baseUrl . $path, $payload);

        $this->assertSuccess($response, 'PUT', $path);

        return $response->json('data', []);
    }

    private function delete(string $path): void
    {
        $response = Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->delete($this->baseUrl . $path);

        $this->assertSuccess($response, 'DELETE', $path);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => $this->adminKey,
            'New-Api-User' => (string) $this->adminUserId,
            'Content-Type' => 'application/json',
        ];
    }

    private function assertSuccess(\Illuminate\Http\Client\Response $response, string $method, string $path): void
    {
        if ($response->failed()) {
            $body = $response->body();
            Log::error('[ApiTokenService] ' . trans("apitoken.http_error"), [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'body' => mb_substr($body, 0, 500),
            ]);
            throw new RuntimeException(
                '[ApiTokenService] ' . $method . ' ' . $path . ' ' . trans("apitoken.http_failed") . ': HTTP ' . $response->status()
            );
        }

        // New API 业务错误：HTTP 200 但 success = false
        $json = $response->json();
        if (is_array($json) && isset($json['success']) && $json['success'] === false) {
            $message = $json['message'] ?? 'unknown error';
            Log::error('[ApiTokenService] ' . trans("apitoken.business_error"), [
                'method' => $method,
                'path' => $path,
                'message' => $message,
            ]);
            throw new RuntimeException(
                '[ApiTokenService] ' . $method . ' ' . $path . ' ' . trans("apitoken.business_failed") . ': ' . $message
            );
        }
    }
}
