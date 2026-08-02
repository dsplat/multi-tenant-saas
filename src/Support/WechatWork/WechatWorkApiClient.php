<?php

namespace MultiTenantSaas\Support\WechatWork;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 企业微信服务端 API 客户端（共享 SDK 层）
 *
 * access_token 按 corp+agent 缓存（同一自建应用的 ibot 与 Channel 共享令牌），
 * 提前 5 分钟过期。后续 media/kf 等 API 在此扩展。
 *
 * 坑：企微 API 要求服务器出口 IP 在应用「企业可信 IP」白名单内，
 * 否则调用报 60020——失败均记日志便于排查。
 */
class WechatWorkApiClient
{
    private const API_BASE = 'https://qyapi.weixin.qq.com/cgi-bin';

    public function __construct(
        private readonly string $corpId,
        private readonly string $corpSecret,
        private readonly string $agentId = '',
    ) {}

    /**
     * 获取 access_token（缓存优先）
     */
    public function accessToken(): string
    {
        if ($this->corpId === '' || $this->corpSecret === '') {
            return '';
        }

        $cacheKey = "wechat_work:access_token:{$this->corpId}:{$this->agentId}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = Http::timeout(15)->get(self::API_BASE . '/gettoken', [
            'corpid' => $this->corpId,
            'corpsecret' => $this->corpSecret,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] gettoken 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return '';
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 7200);

        cache()->put($cacheKey, $token, max($expiresIn - 300, 60));

        return $token;
    }

    /**
     * 发送应用消息（自动补 touser/agentid）
     *
     * @param  array<string, mixed>  $message  msgtype 及对应内容体
     */
    public function sendMessage(string $toUser, array $message): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge([
            'touser' => $toUser,
            'msgtype' => $message['msgtype'] ?? 'text',
            'agentid' => (int) $this->agentId,
        ], $message);

        $response = Http::timeout(15)->post(self::API_BASE . "/message/send?access_token={$accessToken}", $payload);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] message/send 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }

    /**
     * 发送文本应用消息
     */
    public function sendText(string $toUser, string $content): bool
    {
        return $this->sendMessage($toUser, [
            'msgtype' => 'text',
            'text' => ['content' => $content],
        ]);
    }

    /**
     * 发送 markdown 应用消息（仅企业微信客户端渲染，content 上限 4096 字节）
     */
    public function sendMarkdown(string $toUser, string $content): bool
    {
        return $this->sendMessage($toUser, [
            'msgtype' => 'markdown',
            'markdown' => ['content' => $content],
        ]);
    }

    /**
     * 发送应用群聊消息（appchat/send，面向内部群 chatid）
     *
     * 与 message/send 不同：群聊不带 agentid/touser，改以 chatid 为接收方。
     *
     * @param  array<string, mixed>  $message  msgtype 及对应内容体
     */
    public function sendGroupMessage(string $chatId, array $message): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge([
            'chatid' => $chatId,
            'msgtype' => $message['msgtype'] ?? 'text',
        ], $message);

        $response = Http::timeout(15)->post(self::API_BASE . "/appchat/send?access_token={$accessToken}", $payload);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] appchat/send 失败', [
                'corp_id' => $this->corpId,
                'chatid' => $chatId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }

    // ========== 客户群 API（externalcontact scope） ==========

    /**
     * 获取客户群列表（分页）
     *
     * @param  int  $limit  每页数量（最大 1000）
     * @param  string  $cursor  分页游标
     * @return array{group_chat_list: array, next_cursor: string}
     */
    public function groupChatList(int $limit = 1000, string $cursor = ''): array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return ['group_chat_list' => [], 'next_cursor' => ''];
        }

        $payload = ['status_filter' => 0, 'limit' => min($limit, 1000)];
        if ($cursor !== '') {
            $payload['cursor'] = $cursor;
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/groupchat/list?access_token={$accessToken}",
            $payload
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] groupchat/list 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return ['group_chat_list' => [], 'next_cursor' => ''];
        }

        return [
            'group_chat_list' => $response->json('group_chat_list', []),
            'next_cursor' => $response->json('next_cursor', ''),
        ];
    }

    /**
     * 获取客户群详情（含成员列表）
     *
     * @return array<string, mixed>|null 群详情（name/owner/member_list/member_count 等）
     */
    public function groupChatGet(string $chatId, bool $needName = true): ?array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return null;
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/groupchat/get?access_token={$accessToken}",
            ['chat_id' => $chatId, 'need_name' => $needName ? 1 : 0]
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] groupchat/get 失败', [
                'corp_id' => $this->corpId,
                'chat_id' => $chatId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return null;
        }

        return $response->json('group_chat');
    }

    // ========== 微信客服 API（kf scope，独立 token） ==========

    /**
     * 获取客服 access_token（corp + kf_secret，与自建应用 token 不同 scope）
     */
    public function kfAccessToken(string $kfSecret): string
    {
        if ($this->corpId === '' || $kfSecret === '') {
            return '';
        }

        $cacheKey = "wechat_work:kf_token:{$this->corpId}";
        $cached = cache()->get($cacheKey);

        if ($cached !== null) {
            return (string) $cached;
        }

        $response = Http::timeout(15)->get(self::API_BASE . '/gettoken', [
            'corpid' => $this->corpId,
            'corpsecret' => $kfSecret,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] kf gettoken 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return '';
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 7200);

        cache()->put($cacheKey, $token, max($expiresIn - 300, 60));

        return $token;
    }

    /**
     * 拉取客服消息（kf/sync_msg）
     *
     * @return array{msg_list: list<array<string, mixed>>, next_cursor: string}
     */
    public function kfSyncMsg(string $kfSecret, string $cursor = '', string $token = '', int $limit = 1000): array
    {
        $accessToken = $this->kfAccessToken($kfSecret);

        if ($accessToken === '') {
            return ['msg_list' => [], 'next_cursor' => ''];
        }

        $response = Http::timeout(15)->post(self::API_BASE . "/kf/sync_msg?access_token={$accessToken}", [
            'cursor' => $cursor,
            'token' => $token,
            'limit' => $limit,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] kf/sync_msg 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return ['msg_list' => [], 'next_cursor' => ''];
        }

        return [
            'msg_list' => $response->json('msg_list') ?? [],
            'next_cursor' => (string) ($response->json('next_cursor') ?? ''),
        ];
    }

    /**
     * 发送客服消息（kf/send_msg）
     *
     * @param  array<string, mixed>  $message  msgtype 及对应内容体
     */
    public function kfSendMsg(string $kfSecret, string $externalUserId, string $openKfId, array $message): bool
    {
        $accessToken = $this->kfAccessToken($kfSecret);

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge([
            'touser' => $externalUserId,
            'open_kfid' => $openKfId,
            'msgtype' => $message['msgtype'] ?? 'text',
        ], $message);

        $response = Http::timeout(15)->post(self::API_BASE . "/kf/send_msg?access_token={$accessToken}", $payload);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] kf/send_msg 失败', [
                'corp_id' => $this->corpId,
                'open_kfid' => $openKfId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }
}
