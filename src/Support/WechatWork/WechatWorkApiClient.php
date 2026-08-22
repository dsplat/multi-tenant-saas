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

    // ========== 企业群发消息 API（externalcontact scope） ==========

    /**
     * 创建企业群发消息任务
     *
     * 异步分发：调用后消息并不直接发送，企微将任务推送给相关成员（群主/服务人员），
     * 成员在企业微信客户端确认后消息才触达客户/客户群。
     *
     * @param  array<string, mixed>  $payload  chat_type / external_userid / chat_id_list / sender / text / attachments
     * @return array{msgid: string, fail_list: array<int, string>}  msgid 为空表示创建失败
     */
    public function addMsgTemplate(array $payload): array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return ['msgid' => '', 'fail_list' => []];
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/add_msg_template?access_token={$accessToken}",
            $payload
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] add_msg_template 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return ['msgid' => '', 'fail_list' => []];
        }

        return [
            'msgid' => (string) ($response->json('msgid') ?? ''),
            'fail_list' => $response->json('fail_list') ?? [],
        ];
    }

    /**
     * 提醒成员群发（externalcontact/remind_msg_template）
     *
     * 提醒未确认的成员尽快处理群发任务；不传 userid_list 时提醒所有未确认成员。
     *
     * @param  array<int, string>  $userIds
     */
    public function remindMsgTemplate(string $msgid, array $userIds = []): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return false;
        }

        $payload = ['msgid' => $msgid];
        if ($userIds !== []) {
            $payload['userid_list'] = $userIds;
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/remind_msg_template?access_token={$accessToken}",
            $payload
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] remind_msg_template 失败', [
                'corp_id' => $this->corpId,
                'msgid' => $msgid,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }

    /**
     * 停止企业群发（externalcontact/cancel_msg_template）
     */
    public function cancelMsgTemplate(string $msgid): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return false;
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/cancel_msg_template?access_token={$accessToken}",
            ['msgid' => $msgid]
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] cancel_msg_template 失败', [
                'corp_id' => $this->corpId,
                'msgid' => $msgid,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }

    /**
     * 获取企业群发成员执行结果（externalcontact/get_group_msg_send_result）
     *
     * send_list 每项：userid（成员）、status（0=未发送 1=已发送 2=客户不是好友/已删除发送失败 3=客户拒绝接收）、
     * send_time、external_userid（single 模式）或 chat_id（group 模式）。
     *
     * @return array{send_list: array<int, array<string, mixed>>, next_cursor: string}
     */
    public function groupMsgSendResult(string $msgid, int $limit = 1000, string $cursor = ''): array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return ['send_list' => [], 'next_cursor' => ''];
        }

        $payload = ['msgid' => $msgid, 'limit' => min($limit, 1000)];
        if ($cursor !== '') {
            $payload['cursor'] = $cursor;
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/get_group_msg_send_result?access_token={$accessToken}",
            $payload
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] get_group_msg_send_result 失败', [
                'corp_id' => $this->corpId,
                'msgid' => $msgid,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return ['send_list' => [], 'next_cursor' => ''];
        }

        return [
            'send_list' => $response->json('send_list') ?? [],
            'next_cursor' => (string) ($response->json('next_cursor') ?? ''),
        ];
    }

    /**
     * 获取企业的全部群发记录（externalcontact/get_group_msg_list_v2）
     *
     * @param  array<string, mixed>  $filters  chat_type / start_time / end_time / creator / limit / cursor 等
     * @return array{group_msg_list: array<int, array<string, mixed>>, next_cursor: string}
     */
    public function groupMsgListV2(array $filters = []): array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return ['group_msg_list' => [], 'next_cursor' => ''];
        }

        $payload = array_merge(['limit' => min((int) ($filters['limit'] ?? 1000), 1000)], $filters);
        if (($filters['cursor'] ?? '') === '') {
            unset($payload['cursor']);
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/get_group_msg_list_v2?access_token={$accessToken}",
            $payload
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] get_group_msg_list_v2 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return ['group_msg_list' => [], 'next_cursor' => ''];
        }

        return [
            'group_msg_list' => $response->json('group_msg_list') ?? [],
            'next_cursor' => (string) ($response->json('next_cursor') ?? ''),
        ];
    }

    // ========== 外部联系人 API（externalcontact scope） ==========

    /**
     * 获取成员名下的客户列表（externalcontact/list）
     *
     * @return array<int, string> external_userid 列表
     */
    public function externalContactList(string $userId): array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return [];
        }

        $response = Http::timeout(15)->get(self::API_BASE . '/externalcontact/list', [
            'access_token' => $accessToken,
            'userid' => $userId,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] externalcontact/list 失败', [
                'corp_id' => $this->corpId,
                'userid' => $userId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return [];
        }

        return $response->json('external_userid') ?? [];
    }

    /**
     * 获取外部联系人详情（externalcontact/get）
     *
     * @return array<string, mixed>|null  含 external_contact / follow_user
     */
    public function externalContactGet(string $externalUserId): ?array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return null;
        }

        $response = Http::timeout(15)->get(self::API_BASE . '/externalcontact/get', [
            'access_token' => $accessToken,
            'external_userid' => $externalUserId,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] externalcontact/get 失败', [
                'corp_id' => $this->corpId,
                'external_userid' => $externalUserId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * 批量获取客户详情（externalcontact/batch/get_by_user）
     *
     * @param  array<int, string>  $userIds  企业成员 userid 列表（最多 100）
     * @return array{external_contact_list: array<int, array<string, mixed>>, next_cursor: string}
     */
    public function externalContactBatchGet(array $userIds, string $cursor = '', int $limit = 100): array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return ['external_contact_list' => [], 'next_cursor' => ''];
        }

        $payload = ['userid_list' => array_slice($userIds, 0, 100), 'limit' => min($limit, 100)];
        if ($cursor !== '') {
            $payload['cursor'] = $cursor;
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/batch/get_by_user?access_token={$accessToken}",
            $payload
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] batch/get_by_user 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return ['external_contact_list' => [], 'next_cursor' => ''];
        }

        return [
            'external_contact_list' => $response->json('external_contact_list') ?? [],
            'next_cursor' => (string) ($response->json('next_cursor') ?? ''),
        ];
    }

    /**
     * 获取企业成员列表（通讯录 user/list）
     *
     * 外部联系人同步（batch/get_by_user）需要先拿到企业成员 userid 列表。
     * 仅返回自建应用可见范围内的成员。
     *
     * @return array<int, string> userid 列表
     */
    public function userList(int $departmentId = 1): array
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return [];
        }

        $response = Http::timeout(15)->get(self::API_BASE . '/user/list', [
            'access_token' => $accessToken,
            'department_id' => $departmentId,
            'fetch_child' => 1,
        ]);

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] user/list 失败', [
                'corp_id' => $this->corpId,
                'department_id' => $departmentId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return [];
        }

        $userIds = [];
        foreach ($response->json('userlist') ?? [] as $user) {
            $userid = $user['userid'] ?? '';
            if ($userid !== '') {
                $userIds[] = (string) $userid;
            }
        }

        return $userIds;
    }

    // ========== 素材管理 ==========

    /**
     * 上传临时素材（media/upload）
     *
     * 企业群发附件（image/video/file）需先上传获得 media_id。
     *
     * @param  string  $type  image|voice|video|file
     * @return string|null media_id
     */
    public function mediaUpload(string $type, string $filePath): ?string
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '' || ! is_file($filePath)) {
            return null;
        }

        $response = Http::timeout(30)
            ->attach('media', file_get_contents($filePath), basename($filePath))
            ->post(self::API_BASE . "/media/upload?access_token={$accessToken}&type={$type}");

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] media/upload 失败', [
                'corp_id' => $this->corpId,
                'type' => $type,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return null;
        }

        return $response->json('media_id') ? (string) $response->json('media_id') : null;
    }

    // ========== 群聊会话管理 ==========

    /**
     * 修改应用群聊会话（appchat/update）
     *
     * 支持 name / owner / add_user_list / del_user_list 等变更；用于自建群成员管理（如踢人）。
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateAppChat(string $chatId, array $changes): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge(['chatid' => $chatId], $changes);

        $response = Http::timeout(15)->post(
            self::API_BASE . "/appchat/update?access_token={$accessToken}",
            $payload
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] appchat/update 失败', [
                'corp_id' => $this->corpId,
                'chatid' => $chatId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }

    /**
     * 更新客户群（externalcontact/groupchat/update）
     *
     * 支持修改群名/群主/群公告；空值字段不更新。
     * 注意：客户群公告写入后需成员在客户端确认可见，读回经 groupChatGet。
     *
     * @param  array<string, mixed>  $changes  name|owner|announcement
     */
    public function groupChatUpdate(string $chatId, array $changes): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return false;
        }

        $payload = array_merge(['chat_id' => $chatId], $changes);

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/groupchat/update?access_token={$accessToken}",
            $payload
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] groupchat/update 失败', [
                'corp_id' => $this->corpId,
                'chat_id' => $chatId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }

    /**
     * 发送入群欢迎语（externalcontact/send_welcome_msg）
     *
     * 仅能在客户添加成员后的 20 秒窗口内发送，且每个 welcome_code 仅一次。
     * welcome_code 由 add_external_contact 事件回调携带。
     *
     * @param  array<string, mixed>  $payload  text/attachments/buttons
     */
    public function sendWelcomeMsg(string $welcomeCode, array $payload): bool
    {
        $accessToken = $this->accessToken();

        if ($accessToken === '') {
            return false;
        }

        $response = Http::timeout(15)->post(
            self::API_BASE . "/externalcontact/send_welcome_msg?access_token={$accessToken}",
            array_merge(['welcome_code' => $welcomeCode], $payload)
        );

        if (! $response->successful() || ($response->json('errcode') ?? -1) !== 0) {
            Log::warning('[WechatWork] send_welcome_msg 失败', [
                'corp_id' => $this->corpId,
                'errcode' => $response->json('errcode'),
                'errmsg' => mb_substr((string) $response->json('errmsg'), 0, 200),
            ]);

            return false;
        }

        return true;
    }
}
