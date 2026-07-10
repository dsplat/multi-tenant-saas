<?php

declare(strict_types=1);

namespace MultiTenantSaas\EnterpriseWechat;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\ArchivedMessage;

class SessionArchiveService
{
    private ArchiveDecryptor $decryptor;

    private string $corpId;

    private string $corpSecret;

    /**
     * @param  array<string, string>  $config
     */
    public function __construct(array $config)
    {
        $this->corpId = $config['corp_id'] ?? '';
        $this->corpSecret = $config['corp_secret'] ?? '';
        $this->decryptor = new ArchiveDecryptor($config['encoding_aes_key'] ?? '');
    }

    /**
     * @param  int  $seq  起始序列号
     * @param  int  $limit  获取数量
     * @return array{chatdata: list<array<string, mixed>>, seq: int}
     */
    public function fetchFromApi(int $seq = 0, int $limit = 100): array
    {
        $accessToken = $this->getArchiveToken();

        if ($accessToken === '') {
            return ['chatdata' => [], 'seq' => $seq];
        }

        $response = Http::post(
            "https://qyapi.weixin.qq.com/cgi-bin/msgaudit/get_chat_data?access_token={$accessToken}",
            [
                'seq' => $seq,
                'limit' => $limit,
            ],
        );

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('SessionArchiveService: fetch failed', ['response' => $result]);

            return ['chatdata' => [], 'seq' => $seq];
        }

        return [
            'chatdata' => $result['chatdata'] ?? [],
            'seq' => (int) ($result['seq'] ?? $seq),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $chatData
     * @return int 成功存储的消息数量
     */
    public function decryptAndStore(array $chatData, ?int $tenantId = null): int
    {
        $stored = 0;

        foreach ($chatData as $item) {
            $encrypted = $item['encrypt_chat_msg'] ?? '';

            if ($encrypted === '') {
                continue;
            }

            $decrypted = $this->decryptor->decrypt((string) $encrypted);

            if ($decrypted === '') {
                continue;
            }

            $msgData = json_decode($decrypted, true);

            if (! is_array($msgData)) {
                continue;
            }

            $msgId = (string) ($msgData['msg_id'] ?? '');

            if ($msgId === '') {
                continue;
            }

            if ($this->storeMessage($msgData, $tenantId)) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $msgData
     */
    public function storeMessage(array $msgData, ?int $tenantId = null): bool
    {
        $msgId = (string) ($msgData['msg_id'] ?? '');

        if ($msgId === '') {
            return false;
        }

        $existing = ArchivedMessage::query()->where('msg_id', $msgId)->first();

        if ($existing !== null) {
            return false;
        }

        try {
            ArchivedMessage::create([
                'tenant_id' => $tenantId,
                'msg_id' => $msgId,
                'room_id' => (string) ($msgData['room_id'] ?? ''),
                'msg_type' => (string) ($msgData['msgtype'] ?? 'text'),
                'from_user' => (string) ($msgData['from_userid'] ?? ''),
                'content' => $msgData,
                'raw_data' => $msgData,
                'seq' => (int) ($msgData['seq'] ?? 0),
                'create_time' => isset($msgData['msgtime'])
                    ? date('Y-m-d H:i:s', (int) $msgData['msgtime'])
                    : null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('SessionArchiveService: store failed', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return Collection<int, ArchivedMessage>
     */
    public function queryMessages(string $roomId, int $limit = 20, int $offset = 0): Collection
    {
        return ArchivedMessage::query()
            ->where('room_id', $roomId)
            ->orderBy('seq', 'asc')
            ->skip($offset)
            ->take($limit)
            ->get();
    }

    private function getArchiveToken(): string
    {
        $cacheKey = "enterprise_wechat:archive_token:{$this->corpId}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = Http::get('https://qyapi.weixin.qq.com/cgi-bin/gettoken', [
            'corpid' => $this->corpId,
            'corpsecret' => $this->corpSecret,
        ]);

        $result = $response->json();

        if (($result['errcode'] ?? 0) !== 0) {
            Log::error('SessionArchiveService: get archive token failed', ['response' => $result]);

            return '';
        }

        $token = $result['access_token'] ?? '';
        $expiresIn = (int) ($result['expires_in'] ?? 7200);

        cache()->put($cacheKey, $token, $expiresIn - 300);

        return (string) $token;
    }
}
